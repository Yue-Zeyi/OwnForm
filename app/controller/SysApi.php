<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\logic\Setting;
use think\facade\Db;

/**
 * 全局系统设置（仅管理员）：云存储 / 短信 / 极验等
 */
class SysApi extends BaseController
{
    /** 可写配置键与默认值 */
    private const KEYS = [
        // 日志调试（API 报文记录）
        'log_debug'            => '0',
        // 日志保留天数（0=永久）
        'log_keep_days'        => '90',
        // 客户端真实 IP 头（REMOTE_ADDR=直连自动；CDN/反代场景选 X-Real-IP 或 X-Forwarded-For）
        'client_ip_header'     => 'REMOTE_ADDR',
        // 可信代理 IP 列表：只有连接对端命中此列表时才采信转发头。
        // 逗号分隔，支持 单个IP / CIDR(10.0.0.0/8) / 区间(192.168.1.1-192.168.1.20)
        'trusted_proxies'      => '',
        // 上传：单表单附件总容量上限（MB，0=使用默认 500MB）
        'upload_quota_mb'      => '500',
        // 数据保留天数：超过 N 天的提交自动清理（0=永久）。含身份证等敏感信息时建议开启
        'retention_days'       => '0',
        // 每日汇总邮件：开启后每天第一次进后台时发送昨日数据摘要
        'digest_enabled'       => '0',
        'digest_email'         => '',
        // AI 助理（OpenAI 兼容自建模型，后端代理调用）
        'ai_base_url'          => '',
        'ai_model'             => '',
        'ai_key'               => '',
        'ai_temperature'       => '0.7',
        // 支付：渠道能力勾选（JSON 数组，非敏感）
        // wxpay_native/wxpay_h5/alipay_page/alipay_fce/alipay_wap/transfer
        'pay_channels'         => '[]',
        // 支付：微信商户（AppID/商户号/证书序列号非敏感；密钥类走 SECRET_KEYS 加密）
        'pay_wx_app_id'        => '',
        'pay_wx_mch_id'        => '',
        'pay_wx_serial_no'     => '',
        // 支付：支付宝应用
        'pay_ali_app_id'       => '',
        // 支付：密钥证书类（默认空串占位使键进入保存白名单；
        // 真值经 Setting SECRET_KEYS 加密落库，读取仅掩码）
        'pay_wx_apiv3_key'     => '',
        'pay_wx_mch_cert'      => '',
        'pay_wx_mch_key'       => '',
        'pay_ali_private_key'  => '',
        'pay_ali_app_public_cert' => '',
        'pay_ali_public_cert'  => '',
        // 支付：转账核销
        'pay_transfer_name'    => '',
        // 授权与在线更新
        'update_server_url'    => '',
        'license_code'         => '',
        'license_token'        => '',
        'license_domain'       => '',
        'license_expire'       => '',
        'pay_transfer_account' => '',
        'pay_transfer_qr'      => '',
        'pay_transfer_tip'     => '',
        // 品牌定制（白标）
        'sys_name'             => 'OwnForm',
        'sys_logo'             => '',
        'login_subtitle'       => '自建表单收集系统',
        'sys_copyright'        => '',
        'sys_icp'              => '',
        // 通知
        'webhook_url'          => '',
        // 邮件通知（SMTP）
        'mail_host'            => '',
        'mail_port'            => '465',
        'mail_secure'          => 'ssl',
        'mail_user'            => '',
        'mail_pass'            => '',
        'mail_from_name'       => 'OwnForm',
        'webhook_format'       => 'raw',
        'notify_on_submit'     => '0',
        // 存储
        'storage_type'         => 'local',
        'storage_domain'       => '',
        'cos_region'           => '',
        'cos_bucket'           => '',
        'cos_secret_id'        => '',
        'cos_secret_key'       => '',
        'oss_access_key_id'    => '',
        'oss_access_key_secret'=> '',
        'oss_endpoint'         => '',
        'oss_bucket'           => '',
        'qiniu_access_key'     => '',
        'qiniu_secret_key'     => '',
        'qiniu_bucket'         => '',
        // 站点域名池（逗号分隔）：表单/页面/引流链接可指定用哪个域名生成，
        // 支持一域名绑定多站点的落地/炮灰策略。留空则仅用当前域名。
        'site_domains'         => '',
        // 短信
        'sms_provider'         => '',
        'sms_access_key_id'    => '',
        'sms_access_key_secret'=> '',
        'sms_sign_name'        => '',
        'sms_template_code'    => '',
        'sms_sdk_app_id'       => '',
        'sms_region'           => 'ap-guangzhou',
        // 极验
        'geetest_captcha_id'   => '',
        'geetest_captcha_key'  => '',
    ];

/**
 * 品牌信息（公开：登录页/未登录也需展示系统名与 Logo）
     */
    public function brand()
    {
        return $this->ok([
            // AI 助理固定走后端代理，密钥由服务端持有
            'aiApi'         => '/api/ai/chat',
            'sysName'       => Setting::get('sys_name', 'OwnForm') ?: 'OwnForm',
            'sysLogo'       => Setting::get('sys_logo'),
            'loginSubtitle' => Setting::get('login_subtitle', '自建表单收集系统'),
            'copyright'     => Setting::get('sys_copyright'),
            'icp'           => Setting::get('sys_icp'),
        ]);
    }

    public function read()
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        $out = [];
        foreach (self::KEYS as $k => $def) {
            $val = Setting::get($k, $def);
            // 敏感键只返回掩码，绝不把明文密钥下发到浏览器
            $out[$k] = Setting::isSecretKey($k) ? \app\logic\Secret::mask($val) : $val;
        }
        // 标记哪些敏感项已配置，供前端决定「留空即不修改」的交互
        foreach (self::KEYS as $k => $def) {
            if (Setting::isSecretKey($k)) {
                $out[$k . '_set'] = Setting::get($k, '') !== '';
            }
        }
        return $this->ok($out);
    }

    /**
     * POST api/sys/cleanup — 手动执行数据清理（范围与天数由前端指定），写入操作日志
     * 参数：scopes=['expired','recycle','orphan']，days={expired,recycle,orphan}
     */
    public function cleanup()
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        $scopes = (array)input('post.scopes/a', []);
        $scopes = array_values(array_intersect($scopes, ['expired', 'recycle', 'orphan']));
        if (!$scopes) {
            return $this->fail('请至少选择一项清理范围');
        }
        $daysIn = (array)input('post.days/a', []);
        $days = [];
        foreach (['expired', 'recycle', 'orphan'] as $k) {
            $days[$k] = max(1, min(3650, (int)($daysIn[$k] ?? 0)));
        }

        $res = \app\logic\Retention::manualClean($scopes, $days);
        $parts = [];
        if (in_array('expired', $scopes, true)) {
            $parts[] = '过期提交 ' . $res['expired'] . ' 条（' . $days['expired'] . ' 天）';
        }
        if (in_array('recycle', $scopes, true)) {
            $parts[] = '回收站 ' . $res['recycle'] . ' 条（' . $days['recycle'] . ' 天）';
        }
        if (in_array('orphan', $scopes, true)) {
            $parts[] = '孤儿附件 ' . $res['uploads'] . ' 个（' . $days['orphan'] . ' 天）';
        }
        \app\logic\OpLog::write('sys', '手动执行数据清理', implode('，', $parts));
        return $this->ok($res);
    }

    public function save()
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        $data = $this->input();
        foreach (self::KEYS as $k => $def) {
            if (!array_key_exists($k, $data)) {
                continue;
            }
            $v = (string)$data[$k];

            // 敏感键：前端回传掩码占位符时保持原值不变（实现「只写不读」）
            if (Setting::isSecretKey($k) && \app\logic\Secret::isMask($v)) {
                continue;
            }

            if ($k === 'webhook_url' && $v !== '' && !self::isSafeUrl($v)) {
                return $this->fail('Webhook 地址不合法：仅支持 http/https，且不能指向内网地址');
            }
            // AI 与云存储端点同样禁止内网地址，避免 SSRF 与凭证外发
            if (in_array($k, ['ai_base_url', 'oss_endpoint'], true) && $v !== ''
                && !self::isSafeUrl($v, true)) {
                return $this->fail('该地址不合法：仅支持 https，且不能指向内网地址');
            }
            if ($k === 'webhook_format' && !in_array($v, ['raw', 'dingtalk', 'wecom', 'feishu'], true)) {
                return $this->fail('通知格式不支持');
            }
            if ($k === 'storage_type' && !in_array($v, ['local', 'cos', 'oss', 'qiniu'], true)) {
                return $this->fail('存储类型不支持');
            }
            if ($k === 'sms_provider' && !in_array($v, ['', 'aliyun', 'tencent'], true)) {
                return $this->fail('短信服务商不支持');
            }
            if ($k === 'log_keep_days' && ($v === '' || (int)$v < 0 || (int)$v > 3650)) {
                return $this->fail('日志保留天数需为 0-3650 的整数（0 为永久）');
            }
            if ($k === 'upload_quota_mb' && ($v === '' || (int)$v < 0 || (int)$v > 102400)) {
                return $this->fail('附件容量上限需为 0-102400 的整数 MB');
            }
            if ($k === 'client_ip_header' && !in_array($v, ['REMOTE_ADDR', 'X-Real-IP', 'X-Forwarded-For'], true)) {
                return $this->fail('不支持的客户端 IP 头');
            }
            if ($k === 'trusted_proxies' && $v !== '' && !self::isValidProxyList($v)) {
                return $this->fail('可信代理列表格式不合法：仅支持 IP、CIDR 网段或 IP 区间，逗号分隔');
            }
            if ($k === 'mail_secure' && !in_array($v, ['ssl', 'tls', 'none'], true)) {
                return $this->fail('邮件加密方式不支持');
            }
            if ($k === 'mail_port' && ($v === '' || (int)$v < 1 || (int)$v > 65535)) {
                return $this->fail('邮件端口不合法');
            }
            if ($k === 'mail_host' && $v !== '' && !self::isSafeSmtpHost($v)) {
                return $this->fail('SMTP 主机不合法：不能指向内网或回环地址');
            }
            Setting::set($k, $v);
        }
        \app\logic\OpLog::write('sys', '修改系统设置', implode(',', array_keys(array_intersect_key($data, self::KEYS))));
        return $this->ok([], '已保存');
    }

    /**
     * 测试云存储连通性（上传并删除一个测试文件）
     */
    public function testStorage()
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        $type = Setting::get('storage_type', 'local');
        if ($type === 'local') {
            return $this->ok([], '当前为本地存储，无需测试');
        }
        try {
            $key = 'storage-test/' . date('Ymd') . '/' . bin2hex(random_bytes(4)) . '.txt';
            $tmp = app()->getRuntimePath() . 'storage_test_' . bin2hex(random_bytes(4)) . '.txt';
            file_put_contents($tmp, 'ownform storage test ' . date('Y-m-d H:i:s'));
            $url = \app\logic\Storage::put($tmp, $key, 'text/plain');
            @unlink($tmp);
            return $this->ok(['url' => $url], '上传成功：' . $url);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 关于系统：运行环境 / 健康检测 / 数据统计（登录即可见）
     */
    public function about()
    {
        $prefix = config('database.connections.mysql.prefix') ?: 'of_';
        $dbName = config('database.connections.mysql.database');

        // 数据库版本与大小
        $dbVersion = '-';
        $dbSize = 0;
        try {
            $dbVersion = Db::query('SELECT VERSION() v')[0]['v'] ?? '-';
            if ($dbName) {
                $row = Db::query(
                    "SELECT COALESCE(SUM(data_length + index_length),0) s FROM information_schema.tables WHERE table_schema = ?",
                    [$dbName]
                );
                $dbSize = (int)($row[0]['s'] ?? 0);
            }
        } catch (\Throwable $e) {
        }

        // 业务统计
        $stats = [
            'forms'       => 0,
            'submissions' => 0,
            'uploads'     => 0,
            'uploadBytes' => 0,
            'users'       => 0,
            'logs'        => 0,
            'firstFormAt' => null,
            'lastLogin'   => null,
        ];
        try {
            $prefix = config('database.connections.mysql.prefix');
            $stats['forms'] = (int)Db::table($prefix . 'forms')->whereNull('deleted_at')->count();
            $stats['submissions'] = (int)Db::table($prefix . 'form_submissions')->count();
            $stats['users'] = (int)Db::table($prefix . 'users')->count();
            $stats['logs'] = (int)Db::table($prefix . 'logs')->count();
            $up = Db::table($prefix . 'uploads')->field('COUNT(*) c, COALESCE(SUM(size),0) s')->find();
            $stats['uploads'] = (int)($up['c'] ?? 0);
            $stats['uploadBytes'] = (int)($up['s'] ?? 0);
            $stats['firstFormAt'] = Db::table($prefix . 'forms')->whereNull('deleted_at')->min('created_at') ?: null;
            $stats['lastLogin'] = Db::table($prefix . 'users')->max('last_login_time') ?: null;
        } catch (\Throwable $e) {
        }

        // 环境检测
        $ext = function (string $name) {
            return ['name' => $name, 'ok' => extension_loaded($name)];
        };
        $runtimeWritable = is_writable(runtime_path());
        $uploadDir = app()->getRootPath() . 'public/storage/uploads';
        $env = [
            ['name' => 'PHP 版本', 'ok' => version_compare(PHP_VERSION, '8.0.0', '>='), 'value' => PHP_VERSION],
            ['name' => 'MySQL 版本', 'ok' => $dbVersion !== '-', 'value' => $dbVersion],
            ['name' => 'PDO MySQL', 'ok' => extension_loaded('pdo_mysql'), 'value' => extension_loaded('pdo_mysql') ? '已加载' : '未安装'],
            ['name' => 'mbstring', 'ok' => extension_loaded('mbstring'), 'value' => extension_loaded('mbstring') ? '已加载' : '未安装'],
            ['name' => 'gd（图片/验证码）', 'ok' => extension_loaded('gd'), 'value' => extension_loaded('gd') ? '已加载' : '未安装'],
            ['name' => 'fileinfo（上传检测）', 'ok' => extension_loaded('fileinfo'), 'value' => extension_loaded('fileinfo') ? '已加载' : '未安装'],
            ['name' => 'curl（通知/AI）', 'ok' => extension_loaded('curl'), 'value' => extension_loaded('curl') ? '已加载' : '未安装'],
            ['name' => 'runtime 目录可写', 'ok' => $runtimeWritable, 'value' => $runtimeWritable ? '可写' : '不可写'],
            ['name' => '上传目录可写', 'ok' => is_dir($uploadDir) && is_writable($uploadDir), 'value' => is_writable($uploadDir) ? '可写' : '不可写'],
        ];

        // 磁盘空间（public 所在盘）
        $diskTotal = @disk_total_space(app()->getRootPath() . 'public');
        $diskFree = @disk_free_space(app()->getRootPath() . 'public');

        return $this->ok([
            'sysName'    => Setting::get('sys_name', 'OwnForm') ?: 'OwnForm',
            'version'    => 'v' . \app\logic\License::currentVersion(),
            'phpVersion' => PHP_VERSION,
            'dbVersion'  => $dbVersion,
            'serverTime' => date('Y-m-d H:i:s'),
            'timezone'   => config('app.default_timezone') ?: date_default_timezone_get(),
            'os'         => PHP_OS_FAMILY,
            'env'        => $env,
            'disk'       => [
                'total' => (int)$diskTotal,
                'free'  => (int)$diskFree,
            ],
            'dbSize'     => $dbSize,
            'stats'      => $stats,
        ]);
    }

/**
     * SMTP 主机白名单校验
     */
    private static function isSafeSmtpHost(string $host): bool
    {
        $host = trim($host, '[]');
        if ($host === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $host)) {
            return false;
        }
        if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host)) {
            return !\app\logic\Ip::isPrivateAddress($host);
        }
        return !in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)
            && !str_ends_with(strtolower($host), '.local')
            && !str_ends_with(strtolower($host), '.internal');
    }

    /**
     * 外发 URL 安全校验（防 SSRF）
     *
     * filter_var(FILTER_VALIDATE_URL) 会放行 http://169.254.169.254/ 这类内网与
     * 云元数据地址，因此还必须做协议白名单 + 私网网段拒绝。
     *
     * @param bool $httpsOnly 仅允许 https（用于携带密钥的 AI / 云存储端点）
     */
    private static function isSafeUrl(string $url, bool $httpsOnly = false): bool
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme === '') {
            return false;
        }
        if ($httpsOnly && $scheme !== 'https') {
            return false;
        }
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return false;
        }
        // IP 字面量：直接判定网段，命中私网/回环/元数据地址即拒绝
        if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host)
            || str_contains($host, ':')) {
            return !\app\logic\Ip::isPrivateAddress($host);
        }
        // 域名：不在此处做 DNS 解析（会有 TOCTOU 与性能问题），
        // 但要拦住 localhost 与常见的内网专用后缀
        return !in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)
            && !str_ends_with(strtolower($host), '.local')
            && !str_ends_with(strtolower($host), '.internal');
    }

    /**
     * 可信代理列表格式校验：每项必须是 IP、CIDR 或 IP 区间
     */
    private static function isValidProxyList(string $list): bool
    {
        foreach (array_filter(array_map('trim', explode(',', $list))) as $rule) {
            if (str_contains($rule, '/')) {
                [$subnet, $bits] = explode('/', $rule, 2);
                if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return false;
                }
                if (!ctype_digit((string)$bits) || (int)$bits > 32) {
                    return false;
                }
            } elseif (str_contains($rule, '-')) {
                [$from, $to] = array_map('trim', explode('-', $rule, 2));
                if (!filter_var($from, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                    || !filter_var($to, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return false;
                }
            } elseif (!filter_var($rule, FILTER_VALIDATE_IP)) {
                return false;
            }
        }
        return true;
    }

    /**
 * 发送测试通知
     */
    public function testNotify()
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        $url = Setting::get('webhook_url');
        if ($url === '') {
            return $this->fail('请先填写 Webhook 地址并保存');
        }
        \app\logic\Notify::fireFormSubmit(
            ['id' => 0, 'title' => 'OwnForm 测试通知'],
            ['说明' => '这是一条测试通知，收到即表示配置成功'],
            0
        );
        return $this->ok([], '测试消息已发送，请到对应群/接收端确认');
    }

    /**
     * 发送测试邮件
     */
    public function testMail()
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        $to = trim((string)($this->input()['to'] ?? ''));
        $res = \app\logic\Mail::send(
            $to,
            'OwnForm 邮件通知测试',
            '<p>这是一封测试邮件，收到即表示 SMTP 配置成功。</p><p style="color:#909399">发送时间：' . $this->now() . '</p>'
        );
        return $res['ok'] ? $this->ok([], $res['msg']) : $this->fail($res['msg']);
    }
}
