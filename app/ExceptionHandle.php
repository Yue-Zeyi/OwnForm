<?php
namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param  Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        // 使用内置的方式记录异常日志
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param \think\Request   $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        // API 请求统一返回 JSON
        $pathinfo = $request->pathinfo();
        $isApi = str_starts_with($pathinfo, 'api/');
        if ($isApi || $request->isJson() || str_contains((string)$request->header('accept', ''), 'application/json')) {
            $code = 500;
            $msg  = $this->canShowDebug($request) ? $e->getMessage() : '服务器开小差了，请稍后再试';
            if ($e instanceof HttpException) {
                $code = $e->getStatusCode();
                $msg  = $e->getMessage() ?: ($code == 404 ? '接口不存在' : '请求错误');
            } elseif ($e instanceof ValidateException) {
                $code = 422;
                $msg  = $e->getMessage();
            }
            return json(['code' => $code, 'msg' => $msg, 'data' => []], 200);
        }

// 其他错误交给系统处理
    return parent::render($request, $e);
}

/**
 * 是否允许把异常详情（含 SQL 语句与服务器绝对路径）回显给本次请求
 *
 * 必须同时满足两个条件：app_debug 开启，且来源 IP 在 debug_ip_allowlist 内。
 * 仅凭 app_debug 不足以放行——它只是一个环境变量，一旦被误设为 true，
 * 任何访问者都能读到表结构与服务器路径。
 */
protected function canShowDebug($request): bool
{
    if (!config('app.app_debug')) {
        return false;
    }
    $allow = (array)config('app.debug_ip_allowlist', []);
    if (!$allow) {
        return false;
    }
    return in_array(\app\logic\Ip::get($request), $allow, true);
}
}
