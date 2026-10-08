<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\Cache;
use think\facade\Db;

/**
 * 后台认证
 */
class Auth extends BaseController
{
    public function login()
    {
        $data    = $this->input();
        $user    = trim((string)($data['username'] ?? ''));
        $pass    = (string)($data['password'] ?? '');
        $ip      = $this->clientIp();

        if ($user === '' || $pass === '') {
            return $this->fail('请输入账号和密码');
        }

        // 登录限速：10 分钟内最多失败 8 次
        // 双维度计数：IP 维度在 CDN/反代场景下仍可能被伪造，
        // 账号维度不受 IP 影响，二者任一超限即拒绝
        $ipFailKey   = 'login_fail_ip_' . md5($ip);
        $userFailKey = 'login_fail_user_' . md5(strtolower($user));
        $ipFails     = (int)Cache::store('file')->get($ipFailKey, 0);
        $userFails   = (int)Cache::store('file')->get($userFailKey, 0);
        if ($ipFails >= 8) {
            return $this->fail('失败次数过多，请 10 分钟后再试');
        }
        if ($userFails >= 8) {
            return $this->fail('该账号登录失败次数过多，请 10 分钟后再试');
        }

        $admin = Db::name('users')->where('username', $user)->where('status', 1)->find();
        if (!$admin || !password_verify($pass, $admin['password'])) {
            Cache::store('file')->set($ipFailKey, $ipFails + 1, 600);
            Cache::store('file')->set($userFailKey, $userFails + 1, 600);
            \app\logic\OpLog::write('auth', '登录失败', '账号：' . $user, 0);
            return $this->fail('账号或密码错误');
        }

        Cache::store('file')->delete($ipFailKey);
        Cache::store('file')->delete($userFailKey);

        // 轮换会话 ID，防会话固定攻击（攻击者预置会话 ID 等待受害者登录）。
        // 注意：ThinkPHP 的会话是自研 Store，不使用原生 session_start，
        // session_status() 恒为 NONE，必须走框架的 Session::regenerate()。
        app('session')->regenerate(true);

        session('admin_id', (int)$admin['id']);
        session('admin_name', $admin['nickname'] ?: $admin['username']);
        session('admin_role', (string)($admin['role'] ?? 'admin'));
        // 登录后作废旧 CSRF 令牌，使其与新会话绑定
        session('csrf_token', null);

        Db::name('users')->where('id', $admin['id'])->update([
            'last_login_time' => $this->now(),
            'last_login_ip'   => $ip,
        ]);

        \app\logic\OpLog::write('auth', '登录成功', '', 1, (int)$admin['id'], $admin['nickname'] ?: $admin['username']);

        return $this->ok([
            'username' => session('admin_name'),
            'role'     => (string)($admin['role'] ?? 'admin'),
        ], '登录成功');
    }

    public function logout()
    {
        \app\logic\OpLog::write('auth', '退出登录');
        session('admin_id', null);
        session('admin_name', null);
        session('admin_role', null);
        session('csrf_token', null);
        return $this->ok([], '已退出');
    }

    public function me()
    {
        $id = session('admin_id');
        if (!$id) {
            return $this->fail('未登录', 401);
        }
        $admin = Db::name('users')->where('id', (int)$id)->field('id, username, nickname, role, status, last_login_time, permissions_json')->find();
        if (!$admin || (int)$admin['status'] !== 1) {
            session('admin_id', null);
            return $this->fail('账号不存在或已禁用', 401);
        }
return $this->ok([
            'id'        => (int)$admin['id'],
            'username'  => $admin['nickname'] ?: $admin['username'],
            'role'      => (string)($admin['role'] ?: 'admin'),
            'lastLogin' => $admin['last_login_time'] ?? null,
            // 真实权限数组：前端据此判定可见菜单与按钮，不再硬编码 *:*:*
            'permissions' => self::permissionsOf((string)($admin['role'] ?: 'admin'), $admin['permissions_json'] ?? null),
            // CSRF 令牌：后续所有写操作需通过 X-CSRF-TOKEN 头回传
            'csrfToken' => \app\middleware\Csrf::token(),
        ]);
    }

    /**
     * 角色对应的权限点列表
     *
     * 这是前端展示层的依据；真正的鉴权始终在服务端
     * （AdminAuth 中间件 + forbidNonAdmin + formQuery 数据隔离）。
     *
     * @param string $role 角色
     * @param mixed $permJson users.permissions_json：成员可配置权限点数组，NULL=默认全开
     */
    private static function permissionsOf(string $role, mixed $permJson = null): array
    {
        $common = [
            'dashboard:view',
            'form:view', 'form:create', 'form:edit', 'form:delete',
            'data:view', 'data:export', 'data:edit', 'data:delete',
            'stats:view',
            'upload:view', 'upload:delete',
            'notice:view',
            'ai:use',
            'profile:edit',
        ];
        if ($role === 'admin') {
            $common[] = 'user:manage';
            $common[] = 'sys:manage';
            $common[] = 'log:view';
            return $common;
        }
        // 成员：form:create 可按用户关闭（用户管理中配置）
        $list = null;
        if (is_string($permJson) && trim($permJson) !== '') {
            $decoded = json_decode($permJson, true);
            $list = is_array($decoded) ? $decoded : null;
        }
        if ($list !== null && !in_array('form:create', $list, true)) {
            $common = array_values(array_diff($common, ['form:create']));
        }
        return $common;
    }

    public function password()
    {
        $data    = $this->input();
        $oldPass = (string)($data['oldPassword'] ?? '');
        $newPass = (string)($data['newPassword'] ?? '');
        $id      = (int)session('admin_id');

        if (mb_strlen($newPass) < 6) {
            return $this->fail('新密码至少 6 位');
        }
        $admin = Db::name('users')->where('id', $id)->find();
        if (!$admin || !password_verify($oldPass, $admin['password'])) {
            return $this->fail('原密码错误');
        }
        Db::name('users')->where('id', $id)->update([
            'password'   => password_hash($newPass, PASSWORD_BCRYPT),
            'updated_at' => $this->now(),
        ]);
        \app\logic\OpLog::write('auth', '修改密码');
        return $this->ok([], '密码已修改');
    }
}
