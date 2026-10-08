<?php
declare(strict_types=1);

namespace app\logic;

use think\Request;

/**
 * 客户端 IP 统一获取
 *
 * 安全要点：直连部署（client_ip_header = REMOTE_ADDR）时直接用连接对端地址，
 * 任何请求头都不可信。只有当「连接对端确实是配置的可信代理」时，才采信
 * X-Real-IP / X-Forwarded-For —— 否则任何客户端都能伪造 IP，
 * 使基于 IP 的登录限频、提交限频、限一次提交全部失效。
 *
 * X-Forwarded-For 的正确读法是从右向左遍历，跳过可信代理段，
 * 取第一个非可信地址作为真实客户端 IP（最左侧的值完全由客户端控制）。
 */
class Ip
{
    public static function get(?Request $request = null): string
    {
        $request = $request ?: request();
        $peer    = (string)$request->ip();

        $key = (string)Setting::get('client_ip_header', 'REMOTE_ADDR');
        if ($key === '' || $key === 'REMOTE_ADDR') {
            return $peer;
        }

        // 未配置可信代理时拒绝采信任何转发头（宁可用代理 IP，也不能被伪造）
        if (!self::isTrustedProxy($peer)) {
            return $peer;
        }

        if ($key === 'X-Real-IP') {
            $raw = trim((string)$request->header('x-real-ip', ''));
            return filter_var($raw, FILTER_VALIDATE_IP) ? $raw : $peer;
        }

        // X-Forwarded-For：从右向左，跳过可信代理，取第一个非可信地址
        $chain = array_reverse(array_filter(array_map('trim', explode(',', (string)$request->header('x-forwarded-for', '')))));
        foreach ($chain as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            if (!self::isTrustedProxy($ip)) {
                return $ip;
            }
        }

        return $peer;
    }

    /**
     * 判断是否属于内网 / 保留网段（防 SSRF 的公共判定）
     *
     * 覆盖 IPv4 私网、回环、链路本地（含云元数据 169.254.169.254）、
     * CGNAT、组播与保留段，以及 IPv6 回环 / 唯一本地 / 链路本地。
     *
     * @access public
     */
    public static function isPrivateAddress(string $ip): bool
    {
        $ip = trim($ip, '[]');

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }
            $blocks = [
                ['0.0.0.0', 8],         // 本机
                ['10.0.0.0', 8],        // 私网
                ['100.64.0.0', 10],     // CGNAT
                ['127.0.0.0', 8],       // 回环
                ['169.254.0.0', 16],    // 链路本地 / 云元数据
                ['172.16.0.0', 12],     // 私网
                ['192.0.0.0', 24],      // IETF 协议保留
                ['192.168.0.0', 16],    // 私网
                ['198.18.0.0', 15],     // 基准测试
                ['224.0.0.0', 4],       // 组播
                ['240.0.0.0', 4],       // 保留
            ];
            foreach ($blocks as [$subnet, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($subnet) & $mask)) {
                    return true;
                }
            }
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);
            if ($packed === false) {
                return true;
            }
            // IPv4-mapped IPv6（::ffff:a.b.c.d）：curl/OS 会按内嵌 IPv4 连接，
            // 必须取后 4 字节按 IPv4 网段重新判定，
            // 否则 ::ffff:169.254.169.254 这类写法可绕过 SSRF 校验
            if (str_starts_with($packed, str_repeat("\x00", 10) . "\xff\xff")) {
                return self::isPrivateAddress(long2ip(unpack('N', substr($packed, 12, 4))[1]));
            }
            $b0 = ord($packed[0]);
            $b1 = ord($packed[1]);
            // fc00::/7（fb 类 ULA 现实中几乎都是 fd 开头，首字节高 7 位为 1111110x）
            if (($b0 & 0xFE) === 0xFC) {
                return true;
            }
            // fe80::/10（首字节 11111110，次字节高两位 10）
            if ($b0 === 0xFE && ($b1 & 0xC0) === 0x80) {
                return true;
            }
            return $ip === '::1' || $packed === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
        }

        return false;
    }

    /**
     * 判断某 IP 是否为可信代理
     *
     * trusted_proxies 支持三种写法，逗号分隔：
     *   1.2.3.4                     单个 IP
     *   10.0.0.0/8                  CIDR 网段
     *   192.168.1.1-192.168.1.20    IP 区间
     */
    public static function isTrustedProxy(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        foreach (self::trustedProxyRules() as $rule) {
            if (str_contains($rule, '/')) {
                [$subnet, $bits] = explode('/', $rule, 2);
                $subnetLong = ip2long($subnet);
                $bits = (int)$bits;
                if ($subnetLong === false || $bits < 0 || $bits > 32) {
                    continue;
                }
                $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
                if ((($long & $mask) === ($subnetLong & $mask))) {
                    return true;
                }
            } elseif (str_contains($rule, '-')) {
                [$from, $to] = array_map('trim', explode('-', $rule, 2));
                $fromLong = ip2long($from);
                $toLong   = ip2long($to);
                if ($fromLong === false || $toLong === false) {
                    continue;
                }
                if ($long >= min($fromLong, $toLong) && $long <= max($fromLong, $toLong)) {
                    return true;
                }
            } elseif ($rule === $ip) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] */
    private static function trustedProxyRules(): array
    {
        $raw = (string)Setting::get('trusted_proxies', '');
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}