<?php
declare(strict_types=1);

namespace app\middleware;

use think\Response;

/**
 * 后台 API 登录校验
 */
class AdminAuth
{
    public function handle($request, \Closure $next)
    {
        $adminId = session('admin_id');
        if (!$adminId) {
            return json(['code' => 401, 'msg' => '请先登录', 'data' => []], 200);
        }
        // 授权锁定：写操作拒绝（读与授权/更新端点放行，保证可以完成激活解锁）
        $method = strtoupper((string)$request->method());
        $path = (string)$request->pathinfo();
        $licensePath = str_starts_with($path, 'api/license') || str_starts_with($path, 'api/update');
        if (!$licensePath && in_array($method, ['POST', 'PUT', 'DELETE'], true)
            && \app\logic\License::isLocked()) {
            return json(['code' => 4031, 'msg' => '系统授权已到期，已进入只读模式，请到 关于系统 完成激活'], 200);
        }
        return $next($request);
    }
}
