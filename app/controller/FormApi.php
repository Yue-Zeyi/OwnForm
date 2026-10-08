<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\logic\FieldUtil;
use think\facade\Db;

/**
 * 表单管理
 */
class FormApi extends BaseController
{
    public function index()
    {
        $page    = max(1, (int)input('page', 1));
        $size    = min(100, max(1, (int)input('size', 20)));
        $keyword = trim((string)input('keyword', ''));
        $status  = input('status', '');
        $deleted = (int)input('deleted', 0);

        // 回收站：仅看已删除
        $query = $deleted
            ? Db::name('forms')->whereNotNull('deleted_at')->when(!$this->isAdmin(), fn($q) => $q->where('user_id', $this->uid()))
            : $this->formQuery();
        if ($keyword !== '') {
            $query->whereLike('title', '%' . self::like($keyword) . '%');
        }
        if ($status !== '' && $status !== null) {
            $query->where('status', (int)$status);
        }
        $total = (clone $query)->count();
        $list  = $query->order('id', 'desc')
            ->field('id, title, description, slug, status, submit_count, created_at, updated_at, user_id')
            ->page($page, $size)
            ->select()
            ->toArray();
        // 创建人昵称（管理员视图使用）
        $isAdmin = $this->isAdmin();
        if ($isAdmin && $list) {
            $uids = array_unique(array_column($list, 'user_id'));
            $nicknames = Db::name('users')->whereIn('id', $uids)->column('nickname', 'id');
            foreach ($list as &$row) {
                $row['creator'] = $nicknames[$row['user_id']] ?? '';
            }
            unset($row);
        }

        foreach ($list as &$row) {
            if ($deleted) {
                $row['shareUrl'] = '';
            } else {
                $row['shareUrl'] = self::shareUrl($row['slug']);
            }
            if ($isAdmin && empty($row['creator'])) {
                $row['creator'] = '';
            }
        }
        unset($row);

        // 成员视角：被授权表单附上授权信息（前端控制编辑/渠道/导出入口）
        if (!$isAdmin && $list) {
            $authMap = $this->authForms();
            $ownScopeIds = [];
            foreach ($list as &$row) {
                $b = $authMap[(int)$row['id']] ?? null;
                if ($b) {
                    $row['auth'] = [
                        'owner'      => false,
                        'scope'      => (string)$b['data_scope'],
                        'canChannel' => (bool)$b['can_channel'],
                        'canExport'  => (bool)$b['can_export'],
                    ];
                    if ($b['data_scope'] === 'own') {
                        $ownScopeIds[] = (int)$row['id'];
                    }
                } else {
                    $row['auth'] = null;
                }
            }
            unset($row);
            // own 范围授权：提交数按"归属自己渠道"口径显示，不泄露全量数字
            if ($ownScopeIds) {
                $myChIds = array_map('intval', Db::name('form_channels')
                    ->where('member_id', $this->uid())->whereNull('deleted_at')->column('id'));
                $counts = Db::name('form_submissions')
                    ->whereIn('form_id', $ownScopeIds)
                    ->whereNull('deleted_at')
                    ->whereIn('channel_id', $myChIds ?: [0])
                    ->group('form_id')
                    ->field('form_id, count(*) as c')
                    ->select()->toArray();
                $countMap = array_column($counts, 'c', 'form_id');
                foreach ($list as &$row) {
                    if (isset($row['auth']) && $row['auth'] && $row['auth']['scope'] === 'own') {
                        $row['submit_count'] = (int)($countMap[$row['id']] ?? 0);
                    }
                }
                unset($row);
            }
        }

        return $this->ok(['list' => $list, 'total' => $total, 'page' => $page, 'size' => $size, 'deleted' => $deleted]);
    }

    public function save()
    {
        // 全局功能权限：成员可被关闭"创建表单"能力（用户管理中配置）
        if (!$this->hasPerm('form:create')) {
            return $this->fail('你没有创建表单的权限，请联系管理员开通', 403);
        }
        $data     = $this->input();
        $title    = trim((string)($data['title'] ?? ''));
        $fields   = (string)($data['fields'] ?? '[]');
        $settings = $data['settings'] ?? [];
        $desc     = trim((string)($data['description'] ?? ''));

        if ($title === '' || mb_strlen($title) > 100) {
            return $this->fail('标题必填且不超过 100 字');
        }
        if (json_decode($fields) === null && json_last_error() !== JSON_ERROR_NONE) {
            return $this->fail('表单字段数据不合法');
        }

        $id = 0;
        Db::startTrans();
        try {
            $slug = self::genSlug();
            $id   = (int)Db::name('forms')->insertGetId([
                'user_id'       => (int)session('admin_id'),
                'title'         => $title,
                'description'   => $desc,
                'slug'          => $slug,
                'fields_json'   => $fields,
                'settings_json' => json_encode(FieldUtil::normalizeSettings($settings), JSON_UNESCAPED_UNICODE),
                'status'        => 0,
                'submit_count'  => 0,
                'created_at'    => $this->now(),
                'updated_at'    => $this->now(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('保存失败：' . $e->getMessage());
        }

        \app\logic\OpLog::write('form', '创建表单', $title . '（#' . $id . '）');
        return $this->ok(['id' => $id, 'slug' => $slug, 'shareUrl' => self::shareUrl($slug)], '创建成功');
    }

    /**
     * 表单副本：复制字段/设置/说明为新草稿，标题加"副本"后缀
     */
    public function copy(int $id)
    {
        // 全局功能权限：与创建表单一致
        if (!$this->hasPerm('form:create')) {
            return $this->fail('你没有创建表单的权限，请联系管理员开通', 403);
        }
        $form = $this->formQuery()->where('id', $id)->find();
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        $newId = 0;
        Db::startTrans();
        try {
            $newId = (int)Db::name('forms')->insertGetId([
                'user_id'       => (int)session('admin_id'),
                'title'         => mb_substr(trim((string)$form['title']), 0, 94) . ' - 副本',
                'description'   => (string)$form['description'],
                'slug'          => self::genSlug(),
                'fields_json'   => (string)$form['fields_json'],
                'settings_json' => (string)$form['settings_json'],
                'status'        => 0,
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('副本创建失败，请稍后重试');
        }
        \app\logic\OpLog::write('form', '复制表单', $form['title'] . ' -> #' . $newId);
        return $this->ok(['id' => $newId, 'slug' => (string)Db::name('forms')->where('id', $newId)->value('slug')], '副本已创建');
    }

    public function read(int $id)
    {
        // 被授权成员可读表单定义（用于分享/查看数据），编辑仍限创建者与管理员
        $form = $this->loadVisibleForm($id);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        $perm = $this->permOf($form);
        return $this->ok([
            'id'          => (int)$form['id'],
            'title'       => $form['title'],
            'description' => (string)$form['description'],
            'slug'        => $form['slug'],
            'status'      => (int)$form['status'],
            'submitCount' => (int)$form['submit_count'],
            'fields'      => json_decode((string)$form['fields_json'], true) ?: [],
            'settings'    => json_decode((string)$form['settings_json'], true) ?: [],
            'shareUrl'    => self::shareUrl($form['slug']),
            'createdAt'   => $form['created_at'],
            'updatedAt'   => $form['updated_at'],
            'auth'        => [
                'owner'      => $perm['owner'],
                'scope'      => $perm['scope'],
                'canEdit'    => $perm['owner'],
                'canChannel' => $perm['canChannel'],
                'canExport'  => $perm['canExport'],
            ],
        ]);
    }

    public function update(int $id)
    {
        $form = $this->loadOwnedForm($id);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        $data     = $this->input();
        $update   = ['updated_at' => $this->now()];

        if (isset($data['title'])) {
            $title = trim((string)$data['title']);
            if ($title === '' || mb_strlen($title) > 100) {
                return $this->fail('标题必填且不超过 100 字');
            }
            $update['title'] = $title;
        }
        if (isset($data['description'])) {
            $update['description'] = trim((string)$data['description']);
        }
        if (isset($data['fields'])) {
            $fields = (string)$data['fields'];
            if (json_decode($fields) === null && json_last_error() !== JSON_ERROR_NONE) {
                return $this->fail('表单字段数据不合法');
            }
            $update['fields_json'] = $fields;
        }
        if (isset($data['settings'])) {
            $update['settings_json'] = json_encode(
                FieldUtil::normalizeSettings(is_array($data['settings']) ? $data['settings'] : []),
                JSON_UNESCAPED_UNICODE
            );
        }

        // 版本快照：已有提交数据的表单，结构实际发生变化时留存旧版定义，
        // 供数据详情判断"该提交来自旧版表单"
        $structureChanged = isset($update['fields_json']) && $update['fields_json'] !== (string)$form['fields_json'];
        if ($structureChanged && (int)$form['submit_count'] > 0) {
            Db::name('form_versions')->insert([
                'form_id'       => $id,
                'fields_json'   => (string)$form['fields_json'],
                'settings_json' => (string)$form['settings_json'],
                'created_at'    => $this->now(),
            ]);
        }

        Db::name('forms')->where('id', $id)->update($update);
        \app\logic\OpLog::write('form', '保存表单', $form['title'] . '（#' . $id . '）'
            . ($structureChanged && (int)$form['submit_count'] > 0 ? '（已留存旧版结构快照）' : ''));
        return $this->ok([], '已保存');
    }

    public function delete(int $id)
    {
        $form = $this->loadOwnedForm($id);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        Db::name('forms')->where('id', $id)->update(['deleted_at' => $this->now(), 'status' => 2]);
        \app\logic\OpLog::write('form', '删除表单', $form['title'] . '（#' . $id . '）');
        return $this->ok([], '已删除');
    }

    /**
     * 回收站恢复
     */
    public function restore(int $id)
    {
        $form = Db::name('forms')->whereNotNull('deleted_at')->where('id', $id)
            ->when(!$this->isAdmin(), fn($q) => $q->where('user_id', $this->uid()))
            ->find();
        if (!$form) {
            return $this->fail('回收站中不存在该表单', 404);
        }
        Db::name('forms')->where('id', $id)->update(['deleted_at' => null, 'status' => 0, 'updated_at' => $this->now()]);
        \app\logic\OpLog::write('form', '回收站恢复', $form['title'] . '（#' . $id . '）');
        return $this->ok([], '已恢复为草稿');
    }

    /**
     * 回收站彻底删除（连同提交数据与附件记录）
     */
    public function purge(int $id)
    {
        // 任意状态（含已恢复）均可彻底删除
        $form = Db::name('forms')->where('id', $id)
            ->when(!$this->isAdmin(), fn($q) => $q->where('user_id', $this->uid()))
            ->find();
        if (!$form) {
            return $this->fail('回收站中不存在该表单', 404);
        }
        Db::startTrans();
        try {
            // 同步删除附件文件（本地/云端），失败不阻断
            $paths = Db::name('uploads')->where('form_id', $id)->column('path');
            foreach ($paths as $p) {
                try {
                    \app\logic\Storage::deleteObject((string)$p);
                } catch (\Throwable $e) {
                    \think\facade\Log::write('[purge] 附件删除失败 ' . $p . ': ' . $e->getMessage(), 'notice');
                }
            }
            Db::name('form_submissions')->where('form_id', $id)->delete();
            Db::name('uploads')->where('form_id', $id)->delete();
            Db::name('form_versions')->where('form_id', $id)->delete();
            Db::name('form_channels')->where('form_id', $id)->delete();
            Db::name('form_members')->where('form_id', $id)->delete();
            Db::name('forms')->where('id', $id)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('删除失败：' . $e->getMessage());
        }
        \app\logic\OpLog::write('form', '彻底删除表单', $form['title'] . '（#' . $id . '，含全部提交数据）');
        return $this->ok([], '表单及其全部数据已彻底删除');
    }

    public function status(int $id)
    {
        $data = $this->input();
        $status = (int)($data['status'] ?? 0);
        if (!in_array($status, [1, 2], true)) {
            return $this->fail('状态值不合法');
        }
        $form = $this->loadOwnedForm($id);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        if ($status === 1) {
            // 发布前检查：至少有一个可填写字段
            $fields = json_decode((string)$form['fields_json'], true) ?: [];
            if (empty(FieldUtil::extractFields($fields))) {
                return $this->fail('表单还没有字段，请先在设计器中添加');
            }
        }
        Db::name('forms')->where('id', $id)->update(['status' => $status, 'updated_at' => $this->now()]);
        \app\logic\OpLog::write('form', $status === 1 ? '发布表单' : '停止收集', $form['title'] . '（#' . $id . '）');
        return $this->ok(['status' => $status], $status === 1 ? '已开始收集' : '已停止收集');
    }

    // ==================== 协作成员授权 ====================

    /**
     * 表单协作成员列表（仅创建者/管理员）
     */
    public function members(int $formId)
    {
        $form = $this->loadOwnedForm($formId);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        $list = Db::name('form_members')->alias('m')
            ->join('users u', 'u.id = m.member_id')
            ->where('m.form_id', $formId)
            ->field('m.id, m.member_id, m.data_scope, m.can_channel, m.can_export, m.created_at, u.username, u.nickname, u.status')
            ->order('m.id', 'asc')->select()->toArray();

        // 每成员名下渠道数（不含已删除）
        $chCounts = [];
        if ($list) {
            $rows = Db::name('form_channels')
                ->where('form_id', $formId)->whereNull('deleted_at')->whereNotNull('member_id')
                ->group('member_id')
                ->field('member_id, count(*) as c')
                ->select()->toArray();
            $chCounts = array_column($rows, 'c', 'member_id');
        }
        foreach ($list as &$row) {
            $row['member_id']  = (int)$row['member_id'];
            $row['can_channel'] = (int)$row['can_channel'];
            $row['can_export']  = (int)$row['can_export'];
            $row['channel_count'] = (int)($chCounts[$row['member_id']] ?? 0);
        }
        unset($row);

        return $this->ok(['list' => $list, 'members' => self::memberOptions()]);
    }

    /**
     * 授权成员访问该表单
     */
    public function bindMember(int $formId)
    {
        $form = $this->loadOwnedForm($formId);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        $data       = $this->input();
        $memberId   = (int)($data['member_id'] ?? 0);
        $scope      = ($data['data_scope'] ?? 'own') === 'all' ? 'all' : 'own';
        $canChannel = !empty($data['can_channel']) ? 1 : 0;
        $canExport  = !empty($data['can_export']) ? 1 : 0;
        if (!$memberId) {
            return $this->fail('请选择要授权的成员');
        }
        $member = Db::name('users')->where('id', $memberId)->where('role', 'member')->find();
        if (!$member) {
            return $this->fail('只能授权成员账号（管理员天然可见全部表单）');
        }
        if (Db::name('form_members')->where('form_id', $formId)->where('member_id', $memberId)->count()) {
            return $this->fail('该成员已被授权，可直接编辑授权设置');
        }
        Db::name('form_members')->insert([
            'form_id'     => $formId,
            'member_id'   => $memberId,
            'data_scope'  => $scope,
            'can_channel' => $canChannel,
            'can_export'  => $canExport,
            'created_at'  => $this->now(),
        ]);
        // own 范围：确保该成员名下有一条可用渠道，保证"每人链接不同"开箱即用
        if ($scope === 'own' && $canChannel) {
            self::ensureDefaultChannel($form, $member);
        }
        \app\logic\OpLog::write('form', '授权协作成员', '表单#' . $formId . ' → ' . ($member['nickname'] ?: $member['username']) . '（' . $scope . '）');
        return $this->ok([], '已授权');
    }

    /**
     * 调整授权：数据范围 / 能力位
     */
    public function updateMember(int $formId, int $memberId)
    {
        $form = $this->loadOwnedForm($formId);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        $binding = Db::name('form_members')->where('form_id', $formId)->where('member_id', $memberId)->find();
        if (!$binding) {
            return $this->fail('授权记录不存在', 404);
        }
        $data   = $this->input();
        $update = [];
        if (isset($data['data_scope'])) {
            $update['data_scope'] = $data['data_scope'] === 'all' ? 'all' : 'own';
        }
        if (isset($data['can_channel'])) {
            $update['can_channel'] = !empty($data['can_channel']) ? 1 : 0;
        }
        if (isset($data['can_export'])) {
            $update['can_export'] = !empty($data['can_export']) ? 1 : 0;
        }
        if (!$update) {
            return $this->fail('无更新内容');
        }
        Db::name('form_members')->where('id', $binding['id'])->update($update);
        if (($update['data_scope'] ?? $binding['data_scope']) === 'own') {
            $member = Db::name('users')->where('id', $memberId)->find();
            if ($member) {
                self::ensureDefaultChannel($form, $member);
            }
        }
        \app\logic\OpLog::write('form', '调整协作授权', '表单#' . $formId . ' 成员#' . $memberId);
        return $this->ok([], '已保存');
    }

    /**
     * 移除授权：成员立即失去访问；名下渠道保留（历史归因不丢）
     */
    public function unbindMember(int $formId, int $memberId)
    {
        $form = $this->loadOwnedForm($formId);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        $count = Db::name('form_members')->where('form_id', $formId)->where('member_id', $memberId)->delete();
        if (!$count) {
            return $this->fail('授权记录不存在', 404);
        }
        \app\logic\OpLog::write('form', '移除协作授权', '表单#' . $formId . ' 成员#' . $memberId . '（其名下渠道保留归因）');
        return $this->ok([], '已移除授权');
    }

    /**
     * 确保成员在该表单下有一条默认渠道（以成员昵称命名）
     */
    private static function ensureDefaultChannel(array $form, array $member): void
    {
        $exists = Db::name('form_channels')
            ->where('form_id', $form['id'])
            ->where('member_id', $member['id'])
            ->whereNull('deleted_at')
            ->count();
        if ($exists) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        Db::name('form_channels')->insert([
            'form_id'      => $form['id'],
            'name'         => mb_substr(($member['nickname'] ?: $member['username']) . '的渠道', 0, 100),
            'code'         => \app\logic\ChannelUtil::genCode(),
            'member_id'    => (int)$member['id'],
            'status'       => 1,
            'submit_count' => 0,
            'created_by'   => (int)session('admin_id'),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    /**
     * 可选协作成员列表（仅成员角色账号）
     */
    private static function memberOptions(): array
    {
        return Db::name('users')
            ->where('role', 'member')->where('status', 1)
            ->field('id, username, nickname')
            ->order('id', 'asc')->select()->toArray();
    }

    private static function like(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }

    private static function genSlug(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
        do {
            $slug = '';
            for ($i = 0; $i < 10; $i++) {
                $slug .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while (Db::name('forms')->where('slug', $slug)->count() > 0);
        return $slug;
    }

    public static function shareUrl(string $slug): string
    {
        $base = request()->domain();
        return $base . '/s/' . $slug;
    }
}
