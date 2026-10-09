<?php
declare(strict_types=1);

namespace app\logic;

use Yansongda\Pay\Pay as PaySdk;
use Yansongda\ArtisanSdk\Support\Collection;

/**
 * 支付业务层：渠道配置构建 + 下单驱动 + 回调验签 + 原路退款
 *
 * 渠道（pay_type）：
 *   wxpay_native  微信扫码（PC，返回二维码 code_url）
 *   wxpay_h5      微信 H5（手机浏览器，返回跳转链接）
 *   alipay_page   支付宝电脑网站支付（返回跳转 HTML）
 *   alipay_fce    支付宝当面付（返回二维码 qr_code）
 *   alipay_wap    支付宝手机网站支付（返回跳转 HTML）
 *   transfer      转账核销（展示收款码，人工确认）
 *
 * 渠道能力勾选存 settings.pay_channels（JSON 数组）；
 * 商户密钥证书存 SECRET_KEYS（AES-256-GCM 加密，只写不读掩码回显）。
 */
class Pay
{
    /** 支付渠道中文名（前端展示用） */
    public const CHANNEL_NAMES = [
        'wxpay_native' => '微信扫码',
        'wxpay_h5'     => '微信 H5',
        'alipay_page'  => '支付宝（电脑）',
        'alipay_fce'   => '支付宝（扫码）',
        'alipay_wap'   => '支付宝（手机）',
        'transfer'     => '转账核销',
    ];

    private static bool $booted = false;

    /**
     * 后台已勾选启用的渠道
     */
    public static function enabledChannels(): array
    {
        $list = json_decode((string)Setting::get('pay_channels', '[]'), true);
        return is_array($list) ? array_values(array_intersect($list, array_keys(self::CHANNEL_NAMES))) : [];
    }

    /**
     * 是否至少可用一个在线支付渠道（不含转账）
     */
    public static function hasOnlineChannel(): bool
    {
        foreach (self::enabledChannels() as $c) {
            if ($c !== 'transfer') {
                return true;
            }
        }
        return false;
    }

    /**
     * 回调/跳转基地址：优先域名池第一个，其次当前请求 host
     */
    public static function baseUrl(): string
    {
        $domains = array_filter(array_map('trim', explode(',', (string)Setting::get('site_domains'))));
        $scheme = 'https';
        if (!$domains) {
            $host = request()->host(true);
            return $scheme . '://' . $host;
        }
        return $scheme . '://' . $domains[0];
    }

    /**
     * yansongda/pay v3 配置（每次按最新设置构建；证书为 PEM 全文）
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        $base = self::baseUrl();
        $config = [
            'alipay' => [
                'default' => [
                    'app_id'                  => Setting::get('pay_ali_app_id'),
                    'app_secret_cert_path'    => self::pemToFile('ali_private', (string)Setting::get('pay_ali_private_key')),
                    'app_public_cert_path'    => self::pemToFile('ali_app_public', (string)Setting::get('pay_ali_app_public_cert')),
                    'alipay_public_cert_path' => self::pemToFile('ali_public_cert', (string)Setting::get('pay_ali_public_cert')),
                    'return_url'              => $base . '/s/pay/return',
                    'notify_url'              => $base . '/api/pay/notify/alipay',
                    'mode'                    => 0,
                ],
            ],
            'wechat' => [
                'default' => [
                    'mch_id'                  => Setting::get('pay_wx_mch_id'),
                    'app_id'                  => Setting::get('pay_wx_app_id'),
                    'mch_secret_key'          => Setting::get('pay_wx_apiv3_key'),
                    'mch_secret_cert_path'    => self::pemToFile('wx_mch_key', (string)Setting::get('pay_wx_mch_key')),
                    'mch_public_cert_path'    => self::pemToFile('wx_mch_cert', (string)Setting::get('pay_wx_mch_cert')),
                    'notify_url'              => $base . '/api/pay/notify/wechat',
                    'mode'                    => 0,
                ],
            ],
            'logger' => [
                'enable' => false,
            ],
        ];
        PaySdk::config($config);
        self::$booted = true;
    }

    /**
     * 把 PEM 全文落为运行时私钥文件（0600，路径固定便于 SDK 读取）
     */
    private static function pemToFile(string $name, string $pem): string
    {
        if ($pem === '') {
            return '';
        }
        $dir = runtime_path() . 'pay_certs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            @file_put_contents($dir . '/.htaccess', "deny from all\n");
        }
        $path = $dir . '/' . $name . '.pem';
        if (!is_file($path) || md5((string)@file_get_contents($path)) !== md5($pem)) {
            @file_put_contents($path, $pem, LOCK_EX);
            @chmod($path, 0600);
        }
        return $path;
    }

    /**
     * 生成业务订单号：OF + yyyymmddHHMMSS + 6 位随机（防遍历）
     */
    public static function genOrderNo(): string
    {
        return 'OF' . date('YmdHis') . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 创建在线支付单（返回驱动响应：二维码/跳转目标）
     * @return array{qr:string, redirect:string}
     * @throws \Throwable
     */
    public static function createOnline(string $payType, string $orderNo, float $amount, string $subject): array
    {
        self::boot();
        $out = ['qr' => '', 'redirect' => ''];
        $payable = [
            'out_trade_no' => $orderNo,
            'amount'       => ['total' => (int)round($amount * 100)],
            'subject'      => mb_substr($subject, 0, 60),
            'expire_at'    => 900, // 15 分钟（相对秒，SDK v3 通用）
        ];

        if ($payType === 'wxpay_native') {
            $res = PaySdk::wechat()->native($payable);
            $arr = $res->all();
            $out['qr'] = (string)($arr['code_url'] ?? '');
        } elseif ($payType === 'wxpay_h5') {
            $res = PaySdk::wechat()->h5($payable);
            $arr = $res->all();
            $link = (string)($arr['h5_url'] ?? '');
            $out['redirect'] = $link . (str_contains($link, '?') ? '&' : '?') . 'redirect_url='
                . urlencode(self::baseUrl() . '/s/pay/return?no=' . $orderNo);
        } elseif ($payType === 'alipay_page') {
            $res = PaySdk::alipay()->page($payable);
            $out['redirect'] = 'html:' . (string)$res->getBody();
        } elseif ($payType === 'alipay_fce') {
            $res = PaySdk::alipay()->scan($payable);
            $arr = $res->all();
            $out['qr'] = (string)($arr['qr_code'] ?? '');
        } elseif ($payType === 'alipay_wap') {
            $res = PaySdk::alipay()->wap($payable);
            $out['redirect'] = 'html:' . (string)$res->getBody();
        } else {
            throw new \InvalidArgumentException('不支持的支付渠道: ' . $payType);
        }
        return $out;
    }

    /**
     * 原路退款（微信/支付宝；转账渠道需线下原路退回，仅标记）
     * @return array{ok:bool, msg:string, refund_no:string}
     */
    public static function refund(array $order, string $reason): array
    {
        $refundNo = 'RF' . substr($order['order_no'], 2) . random_int(10, 99);
        if ($order['pay_type'] === 'transfer') {
            return ['ok' => true, 'msg' => '转账订单已标记退款，请线下原路退回', 'refund_no' => $refundNo];
        }
        self::boot();
        if (str_starts_with($order['pay_type'], 'wxpay')) {
            PaySdk::wechat()->refund([
                'out_trade_no' => $order['order_no'],
                'out_refund_no' => $refundNo,
                'amount' => [
                    'refund'   => (int)round(((float)$order['amount'] - (float)$order['refund_amount']) * 100),
                    'total'    => (int)round((float)$order['amount'] * 100),
                    'currency' => 'CNY',
                ],
                'reason' => mb_substr($reason, 0, 60),
            ]);
            return ['ok' => true, 'msg' => '微信原路退款已受理', 'refund_no' => $refundNo];
        }
        if (str_starts_with($order['pay_type'], 'alipay')) {
            PaySdk::alipay()->refund([
                'out_trade_no' => $order['order_no'],
                'refund_amount' => number_format((float)$order['amount'] - (float)$order['refund_amount'], 2, '.', ''),
                'out_request_no' => $refundNo,
                'refund_reason' => mb_substr($reason, 0, 60),
            ]);
            return ['ok' => true, 'msg' => '支付宝原路退款成功', 'refund_no' => $refundNo];
        }
        return ['ok' => false, 'msg' => '未知支付渠道', 'refund_no' => ''];
    }

    /**
     * 转账收款信息（给前端展示）
     */
    public static function transferInfo(): array
    {
        return [
            'name'    => Setting::get('pay_transfer_name'),
            'account' => Setting::get('pay_transfer_account'),
            'qr'      => Setting::get('pay_transfer_qr'),
            'tip'     => Setting::get('pay_transfer_tip'),
        ];
    }
}
