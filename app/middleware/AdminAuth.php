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
        return $next($request);
    }
}
