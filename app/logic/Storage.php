<?php
declare(strict_types=1);

namespace app\logic;

use Qiniu\Auth as QiniuAuth;
use Qiniu\Storage\UploadManager;

/**
 * 文件存储驱动：local 本地 / cos 腾讯云 / oss 阿里云 / qiniu 七牛
 * 配置存于 of_settings（SysApi 维护），上传成功返回公网 URL
 */
class Storage
{
    public static function driver(): string
    {
        $d = Setting::get('storage_type', 'local');
        return in_array($d, ['local', 'cos', 'oss', 'qiniu'], true) ? $d : 'local';
    }

    /**
     * 上传文件
     * @param string $localPath 本地文件绝对路径
     * @param string $key 云端对象键（如 uploads/202610/xxx.png）
     * @param string $mime
     * @return string 公网可访问 URL
     * @throws \RuntimeException 上传失败
     */
    public static function put(string $localPath, string $key, string $mime = ''): string
    {
        $driver = self::driver();
        switch ($driver) {
            case 'cos':
                return self::putCos($localPath, $key);
            case 'oss':
                return self::putOss($localPath, $key, $mime);
            case 'qiniu':
                return self::putQiniu($localPath, $key, $mime);
            default:
                return self::localUrl($key);
        }
    }

    public static function isCloud(): bool
    {
        return self::driver() !== 'local';
    }

    public static function localUrl(string $key): string
    {
        return '/' . ltrim($key, '/');
    }

    private static function domain(): string
    {
        return rtrim(Setting::get('storage_domain'), '/');
    }

    // ---------- 腾讯云 COS ----------
    private static function putCos(string $localPath, string $key): string
    {
        $cfg = [
            'region'      => Setting::get('cos_region'),
            'schema'      => 'https',
            'credentials' => [
                'secretId'  => Setting::get('cos_secret_id'),
                'secretKey' => Setting::get('cos_secret_key'),
            ],
        ];
        $bucket = Setting::get('cos_bucket');
        if (!$cfg['region'] || !$bucket || !$cfg['credentials']['secretId']) {
            throw new \RuntimeException('腾讯云 COS 配置不完整，请在系统设置中补全');
        }
        try {
            $client = new \Qcloud\Cos\Client($cfg);
            $fh = fopen($localPath, 'rb');
            if (!$fh) {
                throw new \RuntimeException('读取临时文件失败');
            }
            $client->putObject([
                'Bucket' => $bucket,
                'Key'    => $key,
                'Body'   => $fh,
            ]);
            if (is_resource($fh)) {
                fclose($fh);
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('COS 上传失败：' . $e->getMessage());
        }
        $domain = self::domain();
        if ($domain !== '') {
            return $domain . '/' . $key;
        }
        return 'https://' . $bucket . '.cos.' . $cfg['region'] . '.myqcloud.com/' . $key;
    }

    // ---------- 阿里云 OSS ----------
    private static function putOss(string $localPath, string $key, string $mime = ''): string
    {
        $accessKeyId = Setting::get('oss_access_key_id');
        $accessKeySecret = Setting::get('oss_access_key_secret');
        $endpoint = Setting::get('oss_endpoint');
        $bucket = Setting::get('oss_bucket');
        if (!$accessKeyId || !$endpoint || !$bucket) {
            throw new \RuntimeException('阿里云 OSS 配置不完整，请在系统设置中补全');
        }
        try {
            $client = new \OSS\OssClient($accessKeyId, $accessKeySecret, $endpoint);
            $opts = $mime ? [\OSS\OssClient::OSS_HEADERS => ['Content-Type' => $mime]] : [];
            $client->uploadFile($bucket, $key, $localPath, $opts);
        } catch (\OSS\Core\OssException $e) {
            throw new \RuntimeException('OSS 上传失败：' . $e->getMessage());
        }
        $domain = self::domain();
        if ($domain !== '') {
            return $domain . '/' . $key;
        }
        $ep = str_replace(['https://', 'http://'], '', $endpoint);
        return 'https://' . $bucket . '.' . $ep . '/' . $key;
    }

    // ---------- 七牛云 ----------
    private static function putQiniu(string $localPath, string $key, string $mime = ''): string
    {
        $ak = Setting::get('qiniu_access_key');
        $sk = Setting::get('qiniu_secret_key');
        $bucket = Setting::get('qiniu_bucket');
        if (!$ak || !$sk || !$bucket) {
            throw new \RuntimeException('七牛云配置不完整，请在系统设置中补全');
        }
        try {
            $auth = new QiniuAuth($ak, $sk);
            $token = $auth->uploadToken($bucket, $key, 3600, null, true);
            $um = new UploadManager();
            $opts = $mime ? ['mime' => $mime] : [];
            [$ret, $err] = $um->putFile($token, $key, $localPath, null, $opts);
            if ($err !== null) {
                throw new \RuntimeException($err->message());
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('七牛上传失败：' . $e->getMessage());
        }
        $domain = self::domain();
        if ($domain === '') {
            throw new \RuntimeException('请在系统设置中填写七牛云的加速域名');
        }
        return $domain . '/' . $key;
    }

    /**
     * 云端对象键（不含对象存储 bucket 名）：uploads/YYYYMM/文件名
     */
    public static function key(string $relativePath): string
    {
        // relativePath 形如 /storage/uploads/202610/xxx.png → uploads/202610/xxx.png
        return ltrim(str_replace('/storage/', '', $relativePath), '/');
    }

    /**
     * 删除文件：本地相对路径（/storage/...）或云端 URL 均可
     * 删除失败抛出异常，由调用方决定是否忽略
     */
    public static function deleteObject(string $pathOrUrl): void
    {
        if ($pathOrUrl === '') {
            return;
        }
        if (str_starts_with($pathOrUrl, 'http')) {
            if (!self::isCloud()) {
                // 存储驱动已切回本地，云端文件无法定位，交由调用方记日志
                throw new \RuntimeException('云端文件已无法定位（当前为本地存储）');
            }
            $keyPath = parse_url($pathOrUrl, PHP_URL_PATH);
            $key = ltrim((string)$keyPath, '/');
            if ($key === '') {
                return;
            }
            switch (self::driver()) {
                case 'cos':
                    self::cosClient()->deleteObject([
                        'Bucket' => Setting::get('cos_bucket'),
                        'Key'    => $key,
                    ]);
                    break;
                case 'oss':
                    self::ossClient()->deleteObject(Setting::get('oss_bucket'), $key);
                    break;
                case 'qiniu':
                    $auth = new QiniuAuth(Setting::get('qiniu_access_key'), Setting::get('qiniu_secret_key'));
                    (new \Qiniu\Storage\BucketManager($auth))->delete(Setting::get('qiniu_bucket'), $key);
                    break;
            }
            return;
        }
        // 本地文件
        if (str_starts_with($pathOrUrl, '/storage/uploads/')) {
            $abs = app()->getRootPath() . 'public' . $pathOrUrl;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
    }

    private static function cosClient(): \Qcloud\Cos\Client
    {
        $region = Setting::get('cos_region');
        $secretId = Setting::get('cos_secret_id');
        if (!$region || !$secretId) {
            throw new \RuntimeException('腾讯云 COS 配置不完整');
        }
        return new \Qcloud\Cos\Client([
            'region'      => $region,
            'schema'      => 'https',
            'credentials' => [
                'secretId'  => $secretId,
                'secretKey' => Setting::get('cos_secret_key'),
            ],
        ]);
    }

    private static function ossClient(): \OSS\OssClient
    {
        $accessKeyId = Setting::get('oss_access_key_id');
        $endpoint = Setting::get('oss_endpoint');
        if (!$accessKeyId || !$endpoint) {
            throw new \RuntimeException('阿里云 OSS 配置不完整');
        }
        return new \OSS\OssClient($accessKeyId, Setting::get('oss_access_key_secret'), $endpoint);
    }
}
