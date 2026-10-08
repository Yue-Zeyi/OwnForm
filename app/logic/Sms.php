<?php
declare(strict_types=1);

namespace app\logic;

use think\facade\Cache;
use think\facade\Log;

/**
 * 短信验证码：阿里云（RPC 签名）/ 腾讯云（TC3 签名），验证码存 Cache 一次性
 */
class Sms
{
    private const TTL = 300;        // 验证码有效期 5 分钟
    private const RESEND = 60;      // 重发间隔

    /**
     * 发送验证码
     * @return array{ok:bool,msg:string,wait:int}
     */
    public static function sendCode(string $phone): array
    {
        $provider = Setting::get('sms_provider', '');
        if (!in_array($provider, ['aliyun', 'tencent'], true)) {
            return ['ok' => false, 'msg' => '短信服务未配置', 'wait' => 0];
        }
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return ['ok' => false, 'msg' => '手机号格式不正确', 'wait' => 0];
        }
        // 限频：60 秒重发 + 每号每小时 5 条
        $sendKey = 'sms_sent_' . md5($phone);
        if (Cache::store('file')->get($sendKey)) {
            return ['ok' => false, 'msg' => '发送太频繁，请 1 分钟后再试', 'wait' => 60];
        }
        $hourKey = 'sms_hour_' . md5($phone);
        if ((int)Cache::store('file')->get($hourKey, 0) >= 5) {
            return ['ok' => false, 'msg' => '该手机号验证码发送次数已达上限', 'wait' => 0];
        }

        $code = (string)random_int(100000, 999999);
        $res = $provider === 'aliyun'
            ? self::sendAliyun($phone, $code)
            : self::sendTencent($phone, $code);
        if (!$res['ok']) {
            Log::write('[sms] 发送失败 ' . $phone . ': ' . $res['msg'], 'notice');
            return $res;
        }

        Cache::store('file')->set('sms_code_' . md5($phone), ['code' => $code, 'expire' => time() + self::TTL], self::TTL);
        Cache::store('file')->set($sendKey, 1, self::RESEND);
        Cache::store('file')->inc($hourKey) or Cache::store('file')->set($hourKey, 1, 3600);
        return ['ok' => true, 'msg' => '验证码已发送', 'wait' => self::RESEND];
    }

    /**
     * 校验（一次性）
     */
    public static function checkCode(string $phone, string $input): bool
    {
        $key = 'sms_code_' . md5($phone);
        $data = Cache::store('file')->get($key);
        if (!$data || !is_array($data) || $data['expire'] < time()) {
            return false;
        }
        if (hash_equals($data['code'], trim($input))) {
            Cache::store('file')->delete($key);
            return true;
        }
        return false;
    }

    // ---------- 阿里云短信 ----------
    private static function sendAliyun(string $phone, string $code): array
    {
        $accessKeyId = Setting::get('sms_access_key_id');
        $accessKeySecret = Setting::get('sms_access_key_secret');
        $signName = Setting::get('sms_sign_name');
        $templateCode = Setting::get('sms_template_code');
        if (!$accessKeyId || !$accessKeySecret || !$signName || !$templateCode) {
            return ['ok' => false, 'msg' => '短信服务配置不完整', 'wait' => 0];
        }

        $params = [
            'AccessKeyId'      => $accessKeyId,
            'Action'           => 'SendSms',
            'Format'           => 'JSON',
            'PhoneNumbers'     => $phone,
            'RegionId'         => 'cn-hangzhou',
            'SignName'         => $signName,
            'SignatureMethod'  => 'HMAC-SHA1',
            'SignatureNonce'   => bin2hex(random_bytes(8)),
            'SignatureVersion' => '1.0',
            'TemplateCode'     => $templateCode,
            'TemplateParam'    => json_encode(['code' => $code], JSON_UNESCAPED_UNICODE),
            'Timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
            'Version'          => '2017-05-25',
        ];
        ksort($params);
        $query = self::percentEncodeRfc3986BuildQuery($params);
        $stringToSign = 'GET&%2F&' . rawurlencode($query);
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
        $url = 'https://dysmsapi.aliyuncs.com/?Signature=' . rawurlencode($signature) . '&' . $query;

        $resp = self::httpGet($url);
        $data = json_decode((string)$resp, true);
        if (($data['Code'] ?? '') === 'OK') {
            return ['ok' => true, 'msg' => '已发送', 'wait' => self::RESEND];
        }
        return ['ok' => false, 'msg' => '阿里云短信发送失败：' . ($data['Message'] ?? '未知错误'), 'wait' => 0];
    }

    private static function percentEncodeRfc3986BuildQuery(array $params): string
    {
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = self::percentEncode($k) . '=' . self::percentEncode((string)$v);
        }
        return implode('&', $parts);
    }

    private static function percentEncode(string $s): string
    {
        return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], rawurlencode($s));
    }

    // ---------- 腾讯云短信 ----------
    private static function sendTencent(string $phone, string $code): array
    {
        $secretId = Setting::get('sms_access_key_id');
        $secretKey = Setting::get('sms_access_key_secret');
        $sdkAppId = Setting::get('sms_sdk_app_id');
        $signName = Setting::get('sms_sign_name');
        $templateId = Setting::get('sms_template_code');
        if (!$secretId || !$secretKey || !$sdkAppId || !$templateId) {
            return ['ok' => false, 'msg' => '短信服务配置不完整', 'wait' => 0];
        }

        $timestamp = time();
        $date = gmdate('Y-m-d', $timestamp);
        $service = 'sms';
        $host = 'sms.tencentcloudapi.com';
        $action = 'SendSms';
        $version = '2021-01-11';

        $payload = json_encode([
            'PhoneNumberSet'   => ['+86' . $phone],
            'SmsSdkAppId'      => $sdkAppId,
            'SignName'         => $signName,
            'TemplateId'       => $templateId,
            'TemplateParamSet' => [$code, '5'],
        ], JSON_UNESCAPED_SLASHES);

        // TC3-HMAC-SHA256 签名
        $canonicalRequest = "POST\n/\n\ncontent-type:application/json; charset=utf-8\nhost:{$host}\n\ncontent-type;host\n" . hash('sha256', $payload);
        $stringToSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$date}/{$service}/tc3_request\n" . hash('sha256', $canonicalRequest);
        $secretDate = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('sha256', $stringToSign, $secretSigning);

        $authorization = "TC3-HMAC-SHA256 Credential={$secretId}/{$date}/{$service}/tc3_request, SignedHeaders=content-type;host, Signature={$signature}";
        $resp = self::httpPost(
            "https://{$host}/",
            $payload,
            [
                'Authorization: ' . $authorization,
                'Content-Type: application/json; charset=utf-8',
                'Host: ' . $host,
                'X-TC-Action: ' . $action,
                'X-TC-Timestamp: ' . $timestamp,
                'X-TC-Version: ' . $version,
                'X-TC-Region: ' . (Setting::get('sms_region', 'ap-guangzhou')),
            ]
        );
        $data = json_decode((string)$resp, true);
        $status = $data['Response']['SendStatusSet'][0]['Code'] ?? 'Err';
        if ($status === 'Ok') {
            return ['ok' => true, 'msg' => '已发送', 'wait' => self::RESEND];
        }
        return ['ok' => false, 'msg' => '腾讯云短信发送失败：' . $status, 'wait' => 0];
    }

    private static function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $r = curl_exec($ch);
        if ($r === false) {
            throw new \RuntimeException('网络请求失败：' . curl_error($ch));
        }
        curl_close($ch);
        return (string)$r;
    }

    private static function httpPost(string $url, string $body, array $headers): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $r = curl_exec($ch);
        if ($r === false) {
            throw new \RuntimeException('网络请求失败：' . curl_error($ch));
        }
        curl_close($ch);
        return (string)$r;
    }

    // ---------- 极验 v4 二次校验 ----------
    public static function geetestCheck(array $captchaInput): bool
    {
        $captchaId = Setting::get('geetest_captcha_id');
        $captchaKey = Setting::get('geetest_captcha_key');
        if (!$captchaId || !$captchaKey) {
            return false;
        }
        $lotNumber = (string)($captchaInput['lot_number'] ?? '');
        $captchaOutput = (string)($captchaInput['captcha_output'] ?? '');
        $passToken = (string)($captchaInput['pass_token'] ?? '');
        $genTime = (string)($captchaInput['gen_time'] ?? '');
        if (!$lotNumber || !$captchaOutput || !$passToken || !$genTime) {
            return false;
        }
        $signToken = hash_hmac('sha256', $lotNumber, $captchaKey);
        $query = http_build_query([
            'lot_number'     => $lotNumber,
            'captcha_output' => $captchaOutput,
            'pass_token'     => $passToken,
            'gen_time'       => $genTime,
            'sign_token'     => $signToken,
        ]);
        try {
            $resp = self::httpGet('https://gcaptcha4.geetest.com/validate?captcha_id=' . rawurlencode($captchaId) . '&' . $query);
            $data = json_decode((string)$resp, true);
        } catch (\Throwable $e) {
            return false;
        }
        return ($data['result'] ?? '') === 'success';
    }
}
