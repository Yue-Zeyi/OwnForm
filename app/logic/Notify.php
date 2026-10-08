<?php
declare(strict_types=1);

namespace app\logic;

use think\facade\Log;

/**
 * 提交 Webhook 通知：支持自定义 / 钉钉 / 企业微信 / 飞书机器人
 */
class Notify
{
    public static function fireFormSubmit(array $form, array $data, int $submissionId): void
    {
        $url = Setting::get('webhook_url');
        if ($url === '' || !self::isSafeUrl($url)) {
            return;
        }
        $format   = Setting::get('webhook_format', 'raw');
        $title    = (string)($form['title'] ?? '表单');
        $subtitle = "收到新提交「{$title}」#" . $submissionId;

        $lines = [];
        foreach (array_slice($data, 0, 8, true) as $k => $v) {
            if (str_starts_with((string)$k, '__')) {
                continue;
            }
            if (is_array($v)) {
                $v = implode('、', array_map(fn($x) => is_scalar($x) ? (string)$x : '', $v));
            }
            $lines[] = $k . '：' . mb_substr((string)$v, 0, 50);
        }
        $text = $subtitle . ($lines ? "\n" . implode("\n", $lines) : '');

        switch ($format) {
            case 'dingtalk':
            case 'wecom':
                $payload = ['msgtype' => 'text', 'text' => ['content' => $text]];
                break;
            case 'feishu':
                $payload = ['msg_type' => 'text', 'content' => ['text' => $text]];
                break;
            default:
                $payload = [
                    'event'  => 'form.submit',
                    'form'   => ['id' => (int)$form['id'], 'title' => $title],
                    'submitId' => $submissionId,
                    'data'   => $data,
                    'time'   => date('Y-m-d H:i:s'),
                ];
        }

        self::postJson($url, $payload);
    }

    /**
     * Webhook 地址安全校验（防 SSRF）
     *
     * 仅凭 filter_var(FILTER_VALIDATE_URL) 会放行 http://169.254.169.254/
     * 这类云元数据与内网地址，因此必须额外做协议白名单 + 私网网段拒绝。
     * 该函数在每次表单提交时自动触发，是持续内网探测的现成通道。
     */
    private static function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = trim((string)parse_url($url, PHP_URL_HOST), '[]');
        if ($host === '') {
            return false;
        }
        if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host) || str_contains($host, ':')) {
            return !Ip::isPrivateAddress($host);
        }
        return !in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)
            && !str_ends_with(strtolower($host), '.local')
            && !str_ends_with(strtolower($host), '.internal');
    }

    private static function postJson(string $url, array $payload): void
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // 限定协议，禁用 gopher/file/ftp 等可能被用于探测本地资源的协议
            // （不要加 CURLOPT_PROTOCOLS_STR：该常量在多数 libcurl 构建里未定义，会直接 Fatal）
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // 不跟随跳转：避免 302 绕过上面的地址校验
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $ok = curl_exec($ch) !== false;
        curl_close($ch);
        if (!$ok) {
            Log::write('[notify] webhook 请求失败: ' . $url, 'notice');
        }
    }
}
