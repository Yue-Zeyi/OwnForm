<?php
declare (strict_types = 1);

namespace app;

use think\App;
use think\exception\ValidateException;
use think\Validate;

/**
 * 控制器基础类
 */
abstract class BaseController
{
    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;

        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {}

    /**
     * 成功 JSON 响应
     */
    protected function ok(mixed $data = [], string $msg = 'ok'): \think\Response\Json
    {
        return json(['code' => 0, 'msg' => $msg, 'data' => $data]);
    }

    /**
     * 失败 JSON 响应
     */
    protected function fail(string $msg, int $code = 1, mixed $data = []): \think\Response\Json
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => $data]);
    }

    /**
     * 客户端 IP（系统设置 client_ip_header 支持 CDN/反代场景取真实 IP）
     */
    protected function clientIp(): string
    {
        return \app\logic\Ip::get($this->request);
    }

    /**
     * 读取 JSON 请求体（兼容 form 表单提交）
     */
    protected function input(): array
    {
        $ct = (string)$this->request->contentType();
        if (str_contains($ct, 'application/json')) {
            $data = json_decode((string)$this->request->getContent(), true);
            return is_array($data) ? $data : [];
        }
        $data = $this->request->param();
        unset($data['s']);
        return $data;
    }

    /**
     * 当前时间字符串
     */
    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * 当前登录用户 id
     */
    protected function uid(): int
    {
        return (int)(session('admin_id') ?: 0);
    }

    /**
     * 当前用户角色
     */
    protected function role(): string
    {
        return (string)(session('admin_role') ?: 'admin');
    }

    protected function isAdmin(): bool
    {
        return $this->role() === 'admin';
    }

    /**
     * 非 admin 访问管理接口
     */
    protected function forbidNonAdmin(): ?\think\Response\Json
    {
        return $this->isAdmin() ? null : $this->fail('需要管理员权限', 403);
    }

    /**
     * 成员可配置的功能权限点及默认值
     *
     * permissions_json 为 NULL 时全部按默认值（向后兼容存量账号），
     * 非 NULL 时以数组内容为准。管理员不受限。
     */
    public const CONFIGURABLE_PERMS = ['form:create' => true];

    /**
     * 当前用户是否拥有某功能权限点
     */
    protected function hasPerm(string $key): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        if (!array_key_exists($key, self::CONFIGURABLE_PERMS)) {
            return false;
        }
        static $json = null;
        if ($json === null) {
            $json = \think\facade\Db::name('users')->where('id', $this->uid())->value('permissions_json');
        }
        if ($json === null || $json === false || trim((string)$json) === '') {
            return self::CONFIGURABLE_PERMS[$key];
        }
        $list = json_decode((string)$json, true);
        return is_array($list) ? in_array($key, $list, true) : self::CONFIGURABLE_PERMS[$key];
    }

    /** @var array|null 当前用户被授权的表单映射 form_id => form_members 记录 */
    private ?array $authForms = null;

    private bool $authFormsLoaded = false;

    /**
     * 当前成员被授权的表单映射（管理员为空数组，走不限制分支）
     */
    protected function authForms(): array
    {
        if (!$this->authFormsLoaded) {
            $this->authFormsLoaded = true;
            $this->authForms = [];
            if (!$this->isAdmin() && $this->uid()) {
                $rows = \think\facade\Db::name('form_members')
                    ->where('member_id', $this->uid())->select()->toArray();
                foreach ($rows as $r) {
                    $this->authForms[(int)$r['form_id']] = $r;
                }
            }
        }
        return $this->authForms;
    }

    /**
     * 当前用户可见的表单 ID 集合（自己创建 + 被授权）
     * admin 返回 null 表示不限制
     */
    protected function visibleFormIds(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }
        $own = \think\facade\Db::name('forms')
            ->where('user_id', $this->uid())->whereNull('deleted_at')->column('id');
        return array_values(array_unique(array_merge(
            array_map('intval', $own),
            array_keys($this->authForms())
        )));
    }

    /**
     * 表单查询基（数据隔离：成员可见「自己创建 + 被授权」的表单）
     */
    protected function formQuery()
    {
        $q = \think\facade\Db::name('forms')->whereNull('deleted_at');
        $ids = $this->visibleFormIds();
        if ($ids !== null) {
            $q->whereIn('id', $ids ?: [0]);
        }
        return $q;
    }

    /**
     * 表单管理查询基：仅创建者与管理员（写操作口径）
     */
    protected function ownedFormQuery()
    {
        $q = \think\facade\Db::name('forms')->whereNull('deleted_at');
        if (!$this->isAdmin()) {
            $q->where('user_id', $this->uid());
        }
        return $q;
    }

    /**
     * 载入有权限操作的表单（创建者/管理员口径），失败返回 null
     */
    protected function loadOwnedForm(int $id): ?array
    {
        $form = $this->ownedFormQuery()->where('id', $id)->find();
        return $form ?: null;
    }

    /**
     * 载入可见表单（含被授权），失败返回 null
     */
    protected function loadVisibleForm(int $id): ?array
    {
        $form = $this->formQuery()->where('id', $id)->find();
        return $form ?: null;
    }

    /**
     * 当前用户在某表单下的数据权限
     * owner=true 表示创建者/管理员（全量数据 + 全部管理能力）
     * scope: all=全部数据 own=仅自己渠道的数据 none=无授权
     */
    protected function permOf(array $form): array
    {
        if ($this->isAdmin() || (int)$form['user_id'] === $this->uid()) {
            return ['owner' => true, 'scope' => 'all', 'canChannel' => true, 'canExport' => true];
        }
        $b = $this->authForms()[(int)$form['id']] ?? null;
        if (!$b) {
            return ['owner' => false, 'scope' => 'none', 'canChannel' => false, 'canExport' => false];
        }
        return [
            'owner'      => false,
            'scope'      => (string)$b['data_scope'],
            'canChannel' => (bool)$b['can_channel'],
            'canExport'  => (bool)$b['can_export'],
        ];
    }

    /**
     * 当前成员在某表单下名下的渠道 ID 集合（不含已删除/停用过滤，归因历史有效）
     */
    protected function ownChannelIds(int $formId): array
    {
        if (!$this->uid()) {
            return [];
        }
        return array_map('intval', \think\facade\Db::name('form_channels')
            ->where('form_id', $formId)
            ->where('member_id', $this->uid())
            ->whereNull('deleted_at')
            ->column('id'));
    }

    /**
     * 验证数据
     * @access protected
     * @param  array        $data     数据
     * @param  string|array $validate 验证器名或者验证规则数组
     * @param  array        $message  提示信息
     * @param  bool         $batch    是否批量验证
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate(array $data, string|array $validate, array $message = [], bool $batch = false)
    {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                // 支持场景
                [$validate, $scene] = explode('.', $validate);
            }
            $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
            $v     = new $class();
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        // 是否批量验证
        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        return $v->failException(true)->check($data);
    }

}
