<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\Db;

/**
 * 用户管理（仅管理员）
 */
class UserApi extends BaseController
{
    public function index()
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        $list = Db::name('users')
            ->field('id, username, nickname, role, permissions_json, status, last_login_time, last_login_ip, created_at')
            ->order('id', 'asc')->select()->toArray();

        // 每用户统计：创建的表单数 / 收集数据数 / 待审核数 / 最近提交时间（不含回收站表单）
        $formStats = Db::name('forms')->whereNull('deleted_at')
            ->group('user_id')
            ->field('user_id, count(*) as form_count')
            ->select()->toArray();
        $subStats = Db::name('form_submissions')->alias('s')
            ->join('forms f', 'f.id = s.form_id')
            ->whereNull('f.deleted_at')
            ->group('f.user_id')
            ->field('f.user_id, count(s.id) as sub_count, sum(if(s.status=0,1,0)) as pending_count, max(s.created_at) as last_submit')
            ->select()->toArray();
        $formMap = array_column($formStats, null, 'user_id');
        $subMap = array_column($subStats, null, 'user_id');
        foreach ($list as &$u) {
            $u['form_count'] = (int)($formMap[$u['id']]['form_count'] ?? 0);
            $u['sub_count'] = (int)($subMap[$u['id']]['sub_count'] ?? 0);
            $u['pending_count'] = (int)($subMap[$u['id']]['pending_count'] ?? 0);
            $u['last_submit'] = $subMap[$u['id']]['last_submit'] ?? '';
            // 解码功能权限（管理员恒为全量，前端无需配置入口）
            $perms = json_decode((string)($u['permissions_json'] ?? ''), true);
            $u['permissions'] = is_array($perms) ? $perms : null;
            unset($u['permissions_json']);
        }
        unset($u);
        return $this->ok(['list' => $list]);
    }

    /**
     * 用户详情：统计 + 该用户创建的表单列表（管理员查看）
     */
    public function forms(int $id)
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        $user = Db::name('users')
            ->field('id, username, nickname, role, status, last_login_time, created_at')
            ->where('id', $id)->find();
        if (!$user) {
            return $this->fail('用户不存在', 404);
        }

        $forms = Db::name('forms')->alias('f')
            ->leftjoin('form_submissions s', 's.form_id = f.id')
            ->whereNull('f.deleted_at')
            ->where('f.user_id', $id)
            ->group('f.id')
            ->field('f.id, f.title, f.status, f.created_at, f.updated_at, count(s.id) as submit_count, sum(if(s.status=0,1,0)) as pending_count')
            ->order('f.id', 'desc')
            ->select()->toArray();
        foreach ($forms as &$f) {
            $f['submit_count'] = (int)$f['submit_count'];
            $f['pending_count'] = (int)$f['pending_count'];
        }
        unset($f);

        return $this->ok([
            'user'  => $user,
            'forms' => $forms,
            'summary' => [
                'form_count'    => count($forms),
                'sub_count'     => array_sum(array_column($forms, 'submit_count')),
                'pending_count' => array_sum(array_column($forms, 'pending_count')),
            ],
        ]);
    }

    public function save()
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        $data     = $this->input();
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $nickname = trim((string)($data['nickname'] ?? ''));
        $role     = ($data['role'] ?? 'member') === 'admin' ? 'admin' : 'member';

        if (!preg_match('/^[a-zA-Z][\w]{2,29}$/', $username)) {
            return $this->fail('账号需为 3-30 位字母开头的字母数字下划线');
        }
        if (mb_strlen($password) < 6) {
            return $this->fail('密码至少 6 位');
        }
        if (Db::name('users')->where('username', $username)->count()) {
            return $this->fail('该账号已存在');
        }
        $now = $this->now();
        Db::name('users')->insert([
            'username'         => $username,
            'password'         => password_hash($password, PASSWORD_BCRYPT),
            'nickname'         => $nickname ?: $username,
            'role'             => $role,
            'permissions_json' => self::encodePerms($role, $data['permissions'] ?? null),
            'status'           => 1,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        \app\logic\OpLog::write('user', '创建用户', $username . '（' . $role . '）');
        return $this->ok([], '用户已创建');
    }

    /**
     * 修改：昵称 / 角色 / 状态 / 重置密码
     */
    public function update(int $id)
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        $user = Db::name('users')->where('id', $id)->find();
        if (!$user) {
            return $this->fail('用户不存在', 404);
        }
        $data   = $this->input();
        $update = ['updated_at' => $this->now()];

        if (isset($data['nickname'])) {
            $update['nickname'] = trim((string)$data['nickname']) ?: $user['username'];
        }
        if (isset($data['role'])) {
            if ($id === $this->uid() && $data['role'] !== 'admin') {
                return $this->fail('不能降级自己的管理员角色');
            }
            $update['role'] = $data['role'] === 'admin' ? 'admin' : 'member';
        }
        if (isset($data['status'])) {
            $status = (int)$data['status'] === 1 ? 1 : 0;
            if ($id === $this->uid() && $status !== 1) {
                return $this->fail('不能禁用自己的账号');
            }
            $update['status'] = $status;
        }
        if (!empty($data['password'])) {
            if (mb_strlen((string)$data['password']) < 6) {
                return $this->fail('重置密码至少 6 位');
            }
            $update['password'] = password_hash((string)$data['password'], PASSWORD_BCRYPT);
        }
        if (array_key_exists('permissions', $data)) {
            $role = $update['role'] ?? $user['role'];
            $update['permissions_json'] = self::encodePerms((string)$role, $data['permissions']);
        }

        Db::name('users')->where('id', $id)->update($update);
        \app\logic\OpLog::write('user', '编辑用户', $user['username'] . '（#' . $id . '）' . (isset($update['password']) ? ' 含重置密码' : ''));
        return $this->ok([], '已保存');
    }

    /**
     * 功能权限编码：成员存已开启权限点数组；管理员恒为 NULL（全量不受限）。
     * 语义约定：NULL = 默认全开（存量账号兼容），"[]" = 显式关闭全部可配置权限；
     * 因此数组输入恒编码为 JSON（空数组也要落库），仅非法输入回退 NULL
     */
    private static function encodePerms(string $role, mixed $perms): ?string
    {
        if ($role !== 'member' || !is_array($perms)) {
            return null;
        }
        $valid = array_values(array_intersect($perms, array_keys(\app\BaseController::CONFIGURABLE_PERMS)));
        return json_encode($valid);
    }

    public function delete(int $id)
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        if ($id === $this->uid()) {
            return $this->fail('不能删除自己');
        }
        if (Db::name('users')->where('id', $id)->where('role', 'admin')->count()
            && Db::name('users')->where('role', 'admin')->where('status', 1)->count() <= 1) {
            return $this->fail('至少保留一个管理员');
        }
        Db::startTrans();
        try {
            // 协作授权与名下渠道成员标识清理：渠道行保留（历史归因不丢），
            // 成员昵称展示由查询侧兜底为"已注销成员"
            Db::name('form_members')->where('member_id', $id)->delete();
            Db::name('users')->where('id', $id)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('删除失败：' . $e->getMessage());
        }
        \app\logic\OpLog::write('user', '删除用户', '#' . $id);
        return $this->ok([], '已删除');
    }
}
