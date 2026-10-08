<?php
declare(strict_types=1);

namespace app\middleware;

use app\logic\OpLog;
use app\logic\Setting;
use think\Request;
use think\Response;

/**
 * API 报文日志（调试开关：系统设置 log_debug 开启时记录）
 * 记录 /api/* 请求与响应报文（脱敏截断），排除轮询类噪音接口
 */
class ApiLog
{
    /** 不记录的噪音接口（轮询/高频） */
    private const EXCLUDE = [
        'api/notice/list',
        'api/auth/me',
        'api/captcha',
    ];

    public function handle(Request $request, \Closure $next)
    {
        $path = ltrim((string)$request->pathinfo(), '/');
        $isApi = str_starts_with($path, 'api/') || str_starts_with($path, 'api\\');

        // 日志保留期：每日首个请求触发一次过期日志清理（log_keep_days=0 为永久保留）
        self::rotateLogs();

        $start = microtime(true);
        /** @var Response $response */
        $response = $next($request);
        if (!$isApi) {
            return $response;
        }

        $duration = (int)((microtime(true) - $start) * 1000);

        // 噪音排除
        foreach (self::EXCLUDE as $ex) {
            if (str_starts_with($path, $ex)) {
                return $response;
            }
        }

        // 开关：log_debug 开启才记录
        if (Setting::get('log_debug', '0') !== '1') {
            return $response;
        }

        // 请求报文（JSON 或表单，均脱敏截断）
        $ctype = (string)$request->contentType();
        if (str_contains($ctype, 'application/json')) {
            $reqBody = (string)$request->getContent();
        } else {
            $reqBody = http_build_query($request->param());
        }
        $reqBody = OpLog::sanitize(mb_substr($reqBody, 0, 3000));
        if ($request->file('file')) {
            $reqBody .= "\n[multipart] file=" . $request->file('file')->getOriginalName();
        }

        // 响应报文（截断；文件流/下载跳过）
        $resBody = '';
        if (!$response instanceof \think\response\File) {
            $resBody = (string)$response->getContent();
            $resBody = OpLog::sanitize(mb_substr($resBody, 0, 4000));
        }

        $status = $response->getCode();
        OpLog::write(
            'http',
            $request->method() . ' ' . $path,
            'HTTP ' . $status . ' · ' . $duration . 'ms',
            $status < 400 ? 1 : 0,
            0,
            '',
            ['request' => $reqBody, 'response' => $resBody, 'duration' => $duration]
        );

        return $response;
    }

    /** 按保留天数清理过期日志（每自然日仅执行一次） */
    private static function rotateLogs(): void
    {
        try {
            $flag = 'log_rotate_' . date('Ymd');
            $store = \think\facade\Cache::store('file');
            if ($store->get($flag)) {
                return;
            }
            $store->set($flag, 1, 86400);
            $days = (int)Setting::get('log_keep_days', '90');
            if ($days > 0) {
                \think\facade\Db::name('logs')
                    ->whereTime('created_at', '<', date('Y-m-d H:i:s', time() - $days * 86400))
                    ->delete();
            }
        } catch (\Throwable $e) {
            // 清理失败不影响请求
        }
    }
}
