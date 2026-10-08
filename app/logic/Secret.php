<?php
declare(strict_types=1);

namespace app\logic;

/**
 * 敏感配置（云存储密钥 / 短信密钥 / 邮件密码 / AI Key）的应用层加解密
 *
 * 主密钥来源优先级：
 *   1. .env 中的 SECRET_KEY（或 config('app_secret_key')）
 *   2. config/db_local.php 同级的 secret_key
 *   3. 退化为主密钥自检模式（见 resolveKey 的告警说明）
 *
 * 密文格式：enc:v1:<base64>，非该前缀的值视为历史明文，
 * 由 Setting 侧在读取时透明兼容（见 Setting::getSecret）。
 */
class Secret
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    /** @var string|null 主密钥缓存 */
    private static ?string $key = null;

    /** @var bool 是否已告警过「无主密钥」 */
    private static bool $warned = false;

    /**
     * 是否为密文
     */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * 加密；已加密的值原样返回（保持幂等）
     */
    public static function encrypt(string $plain): string
    {
        if ($plain === '' || self::isEncrypted($plain)) {
            return $plain;
        }
        $key = self::resolveKey();
        if ($key === '') {
            // 无主密钥时保持明文，不静默产生「看似加密实则可逆」的假象
            return $plain;
        }
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            return $plain;
        }
        return self::PREFIX . base64_encode($iv . $tag . $ct);
    }

    /**
     * 解密；明文或解密失败时原样返回
     */
    public static function decrypt(string $value): string
    {
        if ($value === '' || !self::isEncrypted($value)) {
            return $value;
        }
        $key = self::resolveKey();
        if ($key === '') {
            return '';
        }
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $plain = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    /**
     * 掩码展示：sk-abc...wxyz（保留首尾少量字符）
     * 非空值一律返回掩码，空值返回空串，便于前端判断「是否已配置」
     */
    public static function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $len = mb_strlen($value);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return mb_substr($value, 0, 3) . str_repeat('*', min(12, $len - 6)) . mb_substr($value, -3);
    }

    /**
     * 判断传入值是否为本工具生成的掩码占位符
     *
     * 设置保存接口据此识别「前端未修改该密钥，原样回传了掩码」，
     * 从而跳过写入，保证密钥只写不读。
     *
     * 启发式必须收紧：真实密钥也可能含星号（如 SMTP 授权码 ab*cd）。
     * 本工具生成的掩码结构固定——总长 ≥ 10、连续星号 ≥ 4、
     * 首尾明文各 ≤ 3；短于该结构的含星号值一律视为真实密钥。
     */
    public static function isMask(string $value): bool
    {
        // 纯星号串（原值极短时 mask() 输出全星号）：真实密钥不会是纯星号
        if (preg_match('/^\*{4,}$/u', $value)) {
            return true;
        }
        if (mb_strlen($value) < 10) {
            return false;
        }
        // 「少量明文 + 长星号段」的掩码形态
        return preg_match('/^(.{0,3})\*{4,}(.{0,3})$/u', $value) === 1;
    }

    /**
     * 解析主密钥
     */
    private static function resolveKey(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        // 优先级 1：config 中的 app_secret_key（默认无此配置文件，
        // 主要供部署方在 config/extend.php 之类自定义挂载）
        $raw = (string)(config('app_secret_key') ?: '');
        if ($raw === '') {
            $env = (string)env('SECRET_KEY', '');
            if ($env !== '') {
                $raw = $env;
            }
        }
        if ($raw === '') {
            $file = app()->getRootPath() . 'config/secret_key.php';
            if (is_file($file)) {
                $raw = (string)((include $file) ?: '');
            }
        }

        if ($raw === '') {
            if (!self::$warned) {
                self::$warned = true;
                \think\facade\Log::write(
                    '[secret] 未配置主密钥（.env 中设置 SECRET_KEY），敏感配置将以明文存储',
                    'warning'
                );
            }
            return self::$key = '';
        }

        // 任意长度输入都归一化为 32 字节
        return self::$key = hash('sha256', 'ownform-secret-v1|' . $raw, true);
    }
}