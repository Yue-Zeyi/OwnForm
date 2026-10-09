<?php
declare(strict_types=1);

namespace app\logic;

use think\facade\Db;

/**
 * 全局配置（of_settings 表 KV）
 */
class Setting
{
    /**
     * 需要加密存储的敏感配置键
     *
     * 这些键的值一律经 Secret::encrypt() 落库，由 Setting::get() 透明解密。
     */
    private const SECRET_KEYS = [
        'cos_secret_id', 'cos_secret_key',
        'oss_access_key_id', 'oss_access_key_secret',
        'qiniu_access_key', 'qiniu_secret_key',
        'sms_access_key_id', 'sms_access_key_secret',
        'geetest_captcha_key',
        'mail_pass',
        'ai_key',
        'pay_wx_apiv3_key',
        'pay_wx_mch_cert',      // apiclient_cert.pem 全文
        'pay_wx_mch_key',       // apiclient_key.pem 全文
        'pay_ali_private_key',      // 应用私钥 PEM 全文
        'pay_ali_app_public_cert',  // 应用公钥证书全文
        'pay_ali_public_cert',      // 支付宝公钥证书全文
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $rows = [];
        try {
            $rows = Db::name('settings')->column('setting_value', 'setting_key');
        } catch (\Throwable $e) {
            $rows = [];
        }
        return self::$cache = is_array($rows) ? $rows : [];
    }

    public static function get(string $key, string $default = ''): string
    {
        $all = self::all();
        $val = isset($all[$key]) && $all[$key] !== '' ? (string)$all[$key] : $default;
        // 敏感键：落库时已加密，这里透明解密（同时兼容历史明文值）
        return in_array($key, self::SECRET_KEYS, true) && Secret::isEncrypted($val)
            ? Secret::decrypt($val)
            : $val;
    }

    public static function set(string $key, string $value): void
    {
        if (in_array($key, self::SECRET_KEYS, true)) {
            $value = Secret::encrypt($value);
        }
        Db::name('settings')->duplicate([
            'setting_value' => $value,
            'updated_at'    => date('Y-m-d H:i:s'),
        ])->insert([
            'setting_key'   => $key,
            'setting_value' => $value,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        self::$cache = null;
    }

    /**
     * 是否为需要加密存储的敏感键
     */
    public static function isSecretKey(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }
}