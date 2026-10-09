<?php
declare(strict_types=1);

namespace app\middleware;

use think\Response;

/**
 * CSRF 防护
 *
 * 后台完全依赖 PHP Session Cookie 认证，而 Cookie 会随浏览器自动携带，
 * 因此恶意页面可诱导已登录管理员发起跨站请求（改系统设置、建管理员账号、清日志）。
 *
 * 校验方式：同时要求
 *   1. X-CSRF-TOKEN 请求头（或 csrf_token 参数）与 Session 中的令牌一致
 *   2. Origin / Referer 属于本站（老浏览器无 Origin 时的兜底）
 *
 * 安全方法（GET/HEAD/OPTIONS）不做校验；公开接口（未登录态可达）也不校验，
 * 因为它们本来就不依赖会话，CSRF 无从谈起。
 */
class Csrf
{
    /** Session 中存放令牌的键 */
    private const SESSION_KEY = 'csrf_token';

    private const HEADER = 'x-csrf-token';

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * 不参与 CSRF 校验的公开接口前缀
     *
     * 这些接口不依赖会话态（任何人都能调用），CSRF 对它们没有意义：
     * 攻击者无需冒充受害者，直接自己发请求即可。
     * 按「是否登录」豁免是错的——管理员在后台登录后于另一标签页
     * 提交访客表单会被误拦，而填写页是独立静态页面，不持有 CSRF 令牌。
     */
    private const PUBLIC_PREFIXES = [
        'api/fill/',
        'api/captcha',
        'api/upload',
        'api/install/',
        'api/sys/brand',
        'api/auth/login',
        // 页面访客端：只读页面不依赖会话；view/unlock 同样是公开接口
        'api/page/',
        // 引流活码：长按识别上报（访客无会话）
        'api/q/',
        // 支付渠道异步回调（微信/支付宝服务器调用，无会话，自带验签）
        'api/pay/notify/',
    ];

    public function handle($request, \Closure $next)
    {
        if (in_array(strtoupper((string)$request->method()), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        if (self::isPublicPath((string)$request->pathinfo())) {
            return $next($request);
        }

        // 未登录时的其余接口由 AdminAuth 拦截，无需 CSRF 校验
        if (!session('admin_id')) {
            return $next($request);
        }

        if (!$this->tokenMatches($request)) {
            return json([
                'code' => 403,
                'msg'  => '请求校验失败，请刷新页面后重试',
                'data' => [],
            ], 403);
        }

        if (!$this->originMatches($request)) {
            return json([
                'code' => 403,
                'msg'  => '请求来源校验失败',
                'data' => [],
            ], 403);
        }

        return $next($request);
    }

    /**
     * 判定是否为不依赖会话的公开接口
     *
     * @access private
     */
    private static function isPublicPath(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        foreach (self::PUBLIC_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 令牌比对（恒定时间比较，防时序侧信道）
     */
    private function tokenMatches($request): bool
    {
        $expected = (string)(session(self::SESSION_KEY) ?: '');
        if ($expected === '') {
            // 会话中没有令牌（例如旧会话未轮换）：拒绝，由前端刷新后重试
            return false;
        }
        $given = (string)($request->header(self::HEADER, '') ?: $request->param('csrf_token', ''));
        return $given !== '' && hash_equals($expected, $given);
    }

    /**
     * 来源校验：Origin 优先，缺失时回退 Referer
     *
     * 必须比较 host + port：$request->host() 返回的是「主机名:端口」，
     * 而 parse_url(..., PHP_URL_HOST) 只给主机名（不含端口）。
     * 只比主机名会让开发环境（带端口）的合法同源请求被误判为跨站而全部拒绝。
     */
    private function originMatches($request): bool
    {
        $expected = self::normalizeAuthority((string)$request->host());
        if ($expected === '') {
            return false;
        }

        $origin = trim((string)$request->header('origin', ''));
        if ($origin !== '' && $origin !== 'null') {
            return self::authorityOf($origin) === $expected;
        }

        $referer = trim((string)$request->header('referer', ''));
        if ($referer !== '') {
            return self::authorityOf($referer) === $expected;
        }

        // Origin 与 Referer 都缺失（部分隐私浏览器 / 非浏览器客户端）：
        // 放行，安全性由令牌校验单独承担
        return true;
    }

    /**
     * 取出 URL 的 host[:port] 并统一为小写
     */
    private static function authorityOf(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $host = strtolower((string)$parts['host']);
        return isset($parts['port']) ? $host . ':' . (int)$parts['port'] : $host;
    }

    /**
     * 归一化请求 Host 头（统一小写与去空白）
     */
    private static function normalizeAuthority(string $host): string
    {
        return strtolower(trim($host));
    }

    /**
     * 取当前会话的 CSRF 令牌（不存在则生成）
     *
     * @access public
     */
    public static function token(): string
    {
        $token = (string)(session(self::SESSION_KEY) ?: '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            session(self::SESSION_KEY, $token);
        }
        return $token;
    }
}