<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\Cache;
use think\facade\Db;

/**
 * 文件上传：后台素材与公开填写共用
 */
class UploadApi extends BaseController
{
    /** 允许的扩展名 */
    private const EXT_WHITELIST = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv',
        'zip', 'rar', '7z',
        'mp3', 'mp4', 'm4a',
    ];

    private const MAX_SIZE = 20 * 1024 * 1024; // 20MB

    /** 单表单附件总容量默认上限（500MB，可在系统设置 upload_quota_mb 调整，0 为不限） */
    private const FORM_QUOTA_MB = 500;

    /** 生效的表单配额（字节），0 为不限 */
    private static function formQuota(): int
    {
        $mb = (int)\app\logic\Setting::get('upload_quota_mb', (string)self::FORM_QUOTA_MB);
        return max(0, $mb) * 1024 * 1024;
    }

    /**
     * 附件列表（管理端）：成员仅可见全量数据范围表单（自己创建 + all 范围授权）的附件
     */
    public function index()
    {
        $page    = max(1, (int)input('page', 1));
        $size    = min(100, max(1, (int)input('size', 24)));
        $keyword = trim((string)input('keyword', ''));

        $query = Db::name('uploads')->alias('u')
            ->leftJoin('forms f', 'f.id = u.form_id')
            ->field('u.id, u.form_id, u.path, u.name, u.size, u.mime, u.created_at, f.title as form_title, f.user_id');
        if (!$this->isAdmin()) {
            // own 范围授权的表单附件不在此列：附件按表单聚合，无法按渠道行级拆分
            $fullIds = array_map('intval', Db::name('forms')
                ->where('user_id', $this->uid())->whereNull('deleted_at')->column('id'));
            foreach ($this->authForms() as $formId => $b) {
                if ($b['data_scope'] === 'all') {
                    $fullIds[] = (int)$formId;
                }
            }
            $query->whereIn('f.id', $fullIds ?: [0]);
        }
        if ($keyword !== '') {
            $safe = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword);
            $query->whereLike('u.name', '%' . $safe . '%');
        }
        $total = (clone $query)->count();
        $list  = $query->order('u.id', 'desc')->page($page, $size)->select()->toArray();
        foreach ($list as &$r) {
            $r['url'] = $r['path'];
            if ($r['form_id'] == 0) {
                $r['form_title'] = '未关联表单';
            }
        }
        unset($r);
        return $this->ok(['list' => $list, 'total' => $total, 'page' => $page, 'size' => $size]);
    }

    /**
     * 删除附件（同时删文件）
     */
    /**
     * 批量删除（仅管理员）：逐条复用单删逻辑（权限 + 存储文件清理）
     */
    public function batchDelete()
    {
        if (!$this->isAdmin()) {
            return $this->fail('仅管理员可批量删除附件', 403);
        }
        $ids = array_filter(array_map('intval', (array)input('ids/a', [])));
        if (!$ids) {
            return $this->fail('请先选择要删除的附件');
        }
        $deleted = 0;
        foreach ($ids as $id) {
            $row = Db::name('uploads')->alias('u')
                ->leftJoin('forms f', 'f.id = u.form_id')
                ->field('u.id, u.path, f.user_id')
                ->where('u.id', $id)->find();
            if (!$row) {
                continue;
            }
            try {
                \app\logic\Storage::deleteObject($row['path']);
            } catch (\Throwable $e) {
                \think\facade\Log::write('[upload] 批量删除文件失败 ' . $row['path'] . ': ' . $e->getMessage(), 'notice');
            }
            Db::name('uploads')->where('id', $id)->delete();
            $deleted++;
        }
        \app\logic\OpLog::write('upload', '批量删除附件', '共 ' . $deleted . ' 个附件');
        return $this->ok(['deleted' => $deleted], '已删除 ' . $deleted . ' 个附件');
    }

    public function remove(int $id)
    {
        $row = Db::name('uploads')->alias('u')
            ->leftJoin('forms f', 'f.id = u.form_id')
            ->field('u.id, u.path, f.user_id')
            ->where('u.id', $id)->find();
        if (!$row) {
            return $this->fail('附件不存在', 404);
        }
        if (!$this->isAdmin() && (int)($row['user_id'] ?? -1) !== $this->uid()) {
            return $this->fail('无权删除该附件', 403);
        }
        // 同步删除云端/本地文件（删除失败不影响记录清理）
        try {
            \app\logic\Storage::deleteObject($row['path']);
        } catch (\Throwable $e) {
            \think\facade\Log::write('[upload] 文件删除失败 ' . $row['path'] . ': ' . $e->getMessage(), 'notice');
        }
        Db::name('uploads')->where('id', $id)->delete();
        \app\logic\OpLog::write('upload', '删除附件', $row['path']);
        return $this->ok([], '已删除');
    }

    public function save()
    {
        // 限频：每 IP 每分钟最多 20 次
        $ip  = $this->clientIp();
        $key = 'upload_rl_' . md5($ip);
        $n   = (int)Cache::store('file')->get($key, 0);
        if ($n >= 20) {
            return $this->fail('上传太频繁，请稍后再试');
        }
        Cache::store('file')->set($key, $n + 1, 60);

        $file = $this->request->file('file');
        if (!$file || !$file->isValid()) {
            return $this->fail('未收到有效文件');
        }
        if ($file->getSize() > self::MAX_SIZE) {
            return $this->fail('文件不能超过 20MB');
        }

        $ext = strtolower(pathinfo($file->getOriginalName(), PATHINFO_EXTENSION));
        if (!in_array($ext, self::EXT_WHITELIST, true)) {
            return $this->fail('不支持该文件类型：' . $ext);
        }
        // 内容级校验：用文件真实内容（finfo）判断，不信任客户端 MIME
        $realMime = $file->getMime();
        $imageMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true) && !in_array($realMime, $imageMimes, true)) {
            return $this->fail('图片文件内容不合法');
        }

        // 表单关联校验：公开填写仅允许往"收集中且含上传字段"的表单上传；
        // formId=0（素材库）仅限已登录的管理员
        $formId = (int)input('formId', 0);
        if ($formId > 0) {
            $form = Db::name('forms')->where('id', $formId)->whereNull('deleted_at')->find();
            if (!$form || (int)$form['status'] !== 1) {
                return $this->fail('表单不存在或已停止收集');
            }
            $fields = \app\logic\FieldUtil::extractFields(json_decode((string)$form['fields_json'], true) ?: []);
            if (!in_array('upload', array_column($fields, 'type'), true)) {
                return $this->fail('该表单未开启文件上传');
            }
            $quota = self::formQuota();
            if ($quota > 0) {
                $used = (int)Db::name('uploads')->where('form_id', $formId)->sum('size');
                if ($used >= $quota) {
                    return $this->fail('该表单附件总容量已达上限');
                }
            }
        } elseif (!$this->uid() || !$this->isAdmin()) {
            // formId=0（素材库）仅限管理员：成员对素材附件无可见入口，
            // 放开只会造成"传得上却看不见"的孤儿数据
            return $this->fail('素材上传仅限管理员');
        }
        // 元信息在移动临时文件前读取
        $size = (int)$file->getSize();
        $quota = self::formQuota();
        if ($quota > 0 && $formId > 0 && $size > $quota) {
            return $this->fail('单个文件不能超过表单附件总容量上限');
        }
        $origName = mb_substr($file->getOriginalName(), 0, 250);
        $mime = mb_substr($realMime, 0, 100);

        $relDir = 'storage/uploads/' . date('Ym');
        $absDir = app()->getRootPath() . 'public/' . $relDir;
        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true)) {
            return $this->fail('上传目录创建失败');
        }

        $name = date('dHi') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $target = $absDir . '/' . $name;

        $tmpPath = $file->getPathname();
        if (!@move_uploaded_file($tmpPath, $target) && !@rename($tmpPath, $target) && !@copy($tmpPath, $target)) {
            return $this->fail('文件保存失败');
        }
        @chmod($target, 0644);

        // 存储驱动：本地返回 /storage/... 相对路径；云存储转传后返回公网 URL 并清理本地临时文件
        try {
            if (\app\logic\Storage::isCloud()) {
                $relUrl = \app\logic\Storage::put($target, \app\logic\Storage::key('/' . $relDir . '/' . $name), $mime);
                @unlink($target);
            } else {
                $relUrl = '/' . $relDir . '/' . $name;
            }
        } catch (\Throwable $e) {
            @unlink($target);
            return $this->fail($e->getMessage());
        }

        Db::name('uploads')->insert([
            'form_id'    => $formId,
            'path'       => mb_substr($relUrl, 0, 500),
            'name'       => $origName,
            'size'       => $size,
            'mime'       => $mime,
            'created_at' => $this->now(),
        ]);

        return $this->ok([
            'url'  => $relUrl,
            'name' => $origName,
            'size' => $size,
        ]);
    }
}
