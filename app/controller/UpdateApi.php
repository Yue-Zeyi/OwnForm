<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\logic\License;
use app\logic\Setting;

/**
 * 授权与在线更新（管理端）
 *
 *   GET  api/license/status  当前版本 / 授权状态 / 更新服务器配置
 *   POST api/license/activate{license}
 *   POST api/license/verify  心跳校验（返回到期时间）
 *   GET  api/update/check    检查更新
 *   POST api/update/apply{version,url,sha256}  应用热更新
 */
class UpdateApi extends BaseController
{
    public function status()
    {
        $configured = License::isConfigured();
        $out = [
            'version'    => License::currentVersion(),
            'server'     => License::serverUrl(),
            'license'    => License::licenseCode() !== ''
                ? substr(License::licenseCode(), 0, 4) . '****' . substr(License::licenseCode(), -4)
                : '',
            'domain'     => License::domain(),
            'activated'  => trim((string)Setting::get('license_token')) !== '',
            'expire'     => Setting::get('license_expire', ''),
            'configured' => $configured,
        ];
        return $this->ok($out);
    }

    public function activate()
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        $code = trim((string)input('post.license', ''));
        if ($code === '') {
            return $this->fail('请输入授权码');
        }
        if (License::serverUrl() === '') {
            return $this->fail('请先填写授权服务器地址');
        }
        try {
            $res = License::activate($code);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 502);
        }
        if (!$res['ok']) {
            return $this->fail($res['msg'], 403);
        }
        return $this->ok(['msg' => '激活成功' . ($res['expire'] ? '，有效期至 ' . $res['expire'] : '')]);
    }

    public function verify()
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        $res = License::verify();
        return $this->ok($res);
    }

    public function check()
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        try {
            $res = License::checkUpdate();
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 502);
        }
        return $this->ok($res);
    }

    public function apply()
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        $version = trim((string)input('post.version', ''));
        $url     = trim((string)input('post.url', ''));
        $sha256  = trim((string)input('post.sha256', ''));
        if ($version === '' || $url === '') {
            return $this->fail('更新信息不完整，请先检查更新');
        }
        // 只允许相对下载路径（URL 由服务器签发，防止被指到任意地址）
        if (!str_starts_with($url, '/download/')) {
            return $this->fail('下载地址不合法');
        }
        try {
            $res = License::applyUpdate($version, $url, $sha256);
        } catch (\Throwable $e) {
            return $this->fail('更新失败：' . $e->getMessage(), 500);
        }
        if (!$res['ok']) {
            return $this->fail($res['msg'], 500);
        }
        return $this->ok($res, '已升级至 v' . $version);
    }
}
