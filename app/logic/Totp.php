<?php
declare(strict_types=1);

namespace app\logic;

/**
 * TOTP 动态码（RFC 6238，6 位 / 30 秒步长，SHA1）
 *
 * 允许 ±1 个时间窗的时钟偏移；比对使用 hash_equals 防时序侧信道。
 * 密钥为 Base32（RFC 4648，无填充），长度 32 字符 = 160 位熵。
 */
class Totp
{
    /** 生成 Base32 密钥（32 字符 = 160 位） */
    public static function generateSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        for ($i = 0; $i < 32; $i++) {
            $out .= $alphabet[random_int(0, 31)];
        }
        return $out;
    }

    /** Base32 解码（RFC 4648，忽略非字母表字符） */
    public static function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $pos = strpos($alphabet, $c);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    /** 当前步长的 6 位动态码 */
    public static function code(string $secret, ?int $timeSlice = null): string
    {
        $slice = $timeSlice ?? intdiv(time(), 30);
        $binary = pack('N', 0) . pack('N', $slice);
        $hash = hash_hmac('sha1', $binary, self::base32Decode($secret), true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $num = (unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF) % 1000000;
        return str_pad((string)$num, 6, '0', STR_PAD_LEFT);
    }

    /**
     * 校验：允许 ±1 个时间窗（各 30 秒），成功返回命中的窗口偏移，失败返回 null
     */
    public static function verify(string $secret, string $code): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return null;
        }
        $slice = intdiv(time(), 30);
        foreach ([-1, 0, 1] as $offset) {
            if (hash_equals(self::code($secret, $slice + $offset), $code)) {
                return $offset;
            }
        }
        return null;
    }

    /** otpauth:// URI（认证器扫码绑定用） */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }
}
