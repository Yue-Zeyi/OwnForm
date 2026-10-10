<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;

/**
 * 安装向导
 */
class Install extends BaseController
{
    private string $lockFile = '';
    private string $dbConfig = '';
    private string $secretFile = '';

    protected function initialize()
    {
        $root           = app()->getRootPath();
        $this->lockFile = $root . 'install' . DIRECTORY_SEPARATOR . 'installed.lock';
        $this->dbConfig = $root . 'config' . DIRECTORY_SEPARATOR . 'db_local.php';
        $this->secretFile = $root . 'config' . DIRECTORY_SEPARATOR . 'secret_key.php';
    }

    /**
     * 安装页
     *
     * 已安装时返回 404：否则该页面对外公开，等于主动提示攻击者
     * 「删除 install/installed.lock 即可重装并接管系统」。
     */
    public function page()
    {
        if (is_file($this->lockFile)) {
            // 返回真实 404 状态：已安装后该入口不应存在，
            // 避免向外部暴露"重装即可接管"的攻击面
            return response(
                '<!doctype html><meta charset="utf-8"><title>404</title>'
                . '<p style="padding:40px;font-family:sans-serif">系统已安装</p>',
                404,
                ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache, private']
            );
        }
        $file = app()->getRootPath() . 'public/static/install/index.html';
        if (!is_file($file)) {
            return '安装页面文件缺失';
        }
        return response((string)file_get_contents($file), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache, private',
        ]);
    }

    /**
     * 安装状态 + 环境检测
     */
    public function status()
    {
        return $this->ok([
            'installed' => is_file($this->lockFile),
            'checks'    => $this->envChecks(),
        ]);
    }

    private function envChecks(): array
    {
        $runtimeDir = app()->getRuntimePath();
        $uploadDir  = app()->getRootPath() . 'public/storage/uploads';
        return [
            ['name' => 'PHP 版本 ≥ 8.0', 'ok' => version_compare(PHP_VERSION, '8.0.0', '>='), 'value' => PHP_VERSION],
            ['name' => 'PDO MySQL 扩展', 'ok' => extension_loaded('pdo_mysql'), 'value' => extension_loaded('pdo_mysql') ? '已加载' : '未安装'],
            ['name' => 'mbstring 扩展', 'ok' => extension_loaded('mbstring'), 'value' => extension_loaded('mbstring') ? '已加载' : '未安装'],
            ['name' => 'gd 扩展（图片处理）', 'ok' => extension_loaded('gd'), 'value' => extension_loaded('gd') ? '已加载' : '未安装'],
            ['name' => 'fileinfo 扩展', 'ok' => extension_loaded('fileinfo'), 'value' => extension_loaded('fileinfo') ? '已加载' : '未安装'],
            ['name' => 'runtime 目录可写', 'ok' => is_writable($runtimeDir), 'value' => 'runtime/'],
            ['name' => '上传目录可写', 'ok' => is_dir($uploadDir) ? is_writable($uploadDir) : @mkdir($uploadDir, 0755, true), 'value' => 'public/storage/uploads/'],
            ['name' => 'config 目录可写', 'ok' => is_writable(app()->getConfigPath()), 'value' => 'config/'],
        ];
    }

    /**
     * 执行安装
     */
    /**
     * POST api/install/license-check — 安装期授权验证（服务端直连授权服务器）
     * 在线激活：/api/install-activate 绑定域名签发 token；
     * 离线码：/api/offline-verify 兜底校验。
     * 成功后把服务器地址/授权码/token 暂存 session，安装完成时落库。
     */
    public function licenseCheck()
    {
        $server  = rtrim(trim((string)input('post.server', '')), '/');
        $license = trim((string)input('post.license', ''));
        $offline = trim((string)input('post.offline', ''));
        if ($server === '' || $license === '') {
            return $this->fail('请填写授权服务器与授权码');
        }
        if (!preg_match('#^https?://#', $server)) {
            return $this->fail('授权服务器地址需以 http(s):// 开头');
        }
        $domain = strtolower((string)$this->request->host(true));
        $post = function (string $path) use ($server, $license, $domain, $offline) {
            $ch = curl_init($server . $path);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'license' => $license, 'domain' => $domain, 'offline' => $offline,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $raw = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) throw new \RuntimeException('无法连接授权服务器：' . $err);
            return json_decode((string)$raw, true) ?: [];
        };
        try {
            // 1) 在线激活（含离线码透传核销）
            $res = $post('/api/install-activate');
            if (empty($res['ok']) && $offline !== '') {
                // 2) 在线失败（服务器不可达等）走离线码校验兜底
                $res = $post('/api/offline-verify');
            }
        } catch (\RuntimeException $e) {
            if ($offline === '') {
                return $this->fail($e->getMessage() . '。无法在线验证时可向系统提供方索取离线激活码');
            }
            try {
                $res = $post('/api/offline-verify');
            } catch (\RuntimeException $e2) {
                return $this->fail('离线校验也失败：' . $e2->getMessage());
            }
        } catch (\Throwable $e) {
            return $this->fail('授权验证异常：' . $e->getMessage());
        }
        if (empty($res['ok'])) {
            return $this->fail((string)($res['msg'] ?? '授权验证失败'), 403);
        }
        session('install_license', [
            'server'  => $server,
            'code'    => $license,
            'token'   => (string)($res['token'] ?? ''),
            'expire'  => (string)($res['expire_at'] ?? ''),
            'offline' => $offline,
        ]);
        return $this->ok(['expire' => (string)($res['expire_at'] ?? ''), 'token' => (string)($res['token'] ?? '')]);
    }

    public function run()
    {
        if (is_file($this->lockFile)) {
            return $this->fail('系统已安装。如需重装，请删除 install/installed.lock 后重新部署');
        }
        // 先占锁再执行：is_file() 检查与写锁之间存在竞态窗口，
        // 两个并发请求都可能通过检查并重复安装
        $lockHandle = @fopen($this->lockFile, 'x');
        if ($lockHandle === false) {
            return $this->fail('系统已安装，或已有安装任务正在执行');
        }
        try {
            $result = $this->doInstall();
        } catch (\Throwable $e) {
            // 异常路径：释放锁后原样抛出
            @fclose($lockHandle);
            @unlink($this->lockFile);
            throw $e;
        }
        // doInstall 的常规失败（数据库连不上/建表失败等）以 fail 响应返回，
        // 同样必须释放锁——否则 installed.lock 残留，安装向导被锁死，
        // 只能上服务器手工删文件才能重装
        if ($result instanceof \think\Response\Json) {
            $decoded = json_decode((string)$result->getContent(), true);
            // 解析失败或 code 非 0 都视为安装失败 → 释放锁
            if (!is_array($decoded) || ($decoded['code'] ?? 1) !== 0) {
                @fclose($lockHandle);
                @unlink($this->lockFile);
            }
        }
        return $result;
    }

    /**
     * 安装主流程（调用前须已持有安装锁）
     */
    private function doInstall()
    {
        $data = $this->input();
        $dbHost = trim((string)($data['dbHost'] ?? '127.0.0.1'));
        $dbPort = trim((string)($data['dbPort'] ?? '3306'));
        $dbName = trim((string)($data['dbName'] ?? ''));
        $dbUser = trim((string)($data['dbUser'] ?? ''));
        $dbPass = (string)($data['dbPass'] ?? '');
        $dbPrefix = trim((string)($data['dbPrefix'] ?? 'of_')) ?: 'of_';
        $adminUser = trim((string)($data['adminUser'] ?? ''));
        $adminPass = (string)($data['adminPass'] ?? '');
        // 安装期授权校验：session 里必须有通过验证的授权信息
        $lic = session('install_license');
        if (!is_array($lic) || empty($lic['token'])) {
            return $this->fail('请先完成授权验证（第 2 步顶部）');
        }

        if ($dbName === '' || $dbUser === '') {
            return $this->fail('请填写数据库名和用户名');
        }
        if (!preg_match('/^[a-zA-Z][\w]{2,29}$/', $adminUser)) {
            return $this->fail('管理员账号需为 3-30 位字母开头的字母数字下划线');
        }
        if (mb_strlen($adminPass) < 6) {
            return $this->fail('管理员密码至少 6 位');
        }
        if (!preg_match('/^[a-zA-Z_][\w]*$/', $dbPrefix)) {
            return $this->fail('表前缀格式不合法');
        }

        // 连接测试
        try {
            $pdo = new \PDO(
                "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]
            );
        } catch (\Throwable $e) {
            return $this->fail('数据库连接失败：' . $e->getMessage());
        }

        // 建库
        try {
            $safeDbName = preg_replace('/[^\w]/', '', $dbName);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$safeDbName}`");
        } catch (\Throwable $e) {
            return $this->fail('创建数据库失败：' . $e->getMessage());
        }

        // 检查是否已有数据
        $exists = $pdo->query("SHOW TABLES LIKE '{$dbPrefix}forms'")->fetch();
        if ($exists) {
            return $this->fail("数据库中已存在前缀为 {$dbPrefix} 的表，请更换表前缀或清空数据库");
        }

        // 导入表结构
        $schemaFile = app()->getRootPath() . 'install/schema.sql';
        $sql = (string)file_get_contents($schemaFile);
        $sql = str_replace('{{prefix}}', $dbPrefix, $sql);
        try {
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt !== '') {
                    $pdo->exec($stmt);
                }
            }
        } catch (\Throwable $e) {
            return $this->fail('建表失败：' . $e->getMessage());
        }

        // 创建管理员（首用户固定为 admin 角色）
        try {
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("INSERT INTO `{$dbPrefix}users` (username, password, nickname, role, created_at, updated_at, status) VALUES (?, ?, ?, 'admin', ?, ?, 1)")
                ->execute([$adminUser, password_hash($adminPass, PASSWORD_BCRYPT), '管理员', $now, $now]);
        } catch (\Throwable $e) {
            return $this->fail('创建管理员失败：' . $e->getMessage());
        }

        // 写数据库配置
        // 先备份既有配置：安装器若被重跑，直接覆盖会把线上库连接指向别处
        if (is_file($this->dbConfig)) {
            @copy($this->dbConfig, $this->dbConfig . '.bak');
        }
        $config = "<?php\n\n// 由安装向导生成（含数据库凭据，禁止提交到版本库，禁止 Web 访问）\nreturn " . var_export([
            'hostname' => $dbHost,
            'hostport' => $dbPort,
            'database' => $safeDbName,
            'username' => $dbUser,
            'password' => $dbPass,
            'charset'  => 'utf8mb4',
            'prefix'   => $dbPrefix,
        ], true) . ";\n";
        if (@file_put_contents($this->dbConfig, $config) === false) {
            return $this->fail('无法写入 config/db_local.php，请检查目录权限');
        }
        // 收紧权限：该文件被 config/database.php 无条件 include，
        // 保持 0644 等于让同机其他用户读到数据库凭据
        @chmod($this->dbConfig, 0640);

        // 生成敏感配置主密钥（云存储/短信/邮件/AI 密钥的加密依据）
        if (!is_file($this->secretFile)) {
            $secret = "<?php\n\n// 由安装向导生成：敏感配置加密主密钥，请勿泄露\nreturn '" . bin2hex(random_bytes(32)) . "';\n";
            if (@file_put_contents($this->secretFile, $secret) !== false) {
                @chmod($this->secretFile, 0640);
            }
        }

        // 验证新库可正常连接（直接用 PDO，避免同请求内框架配置缓存问题）
        try {
            new \PDO(
                "mysql:host={$dbHost};port={$dbPort};dbname={$safeDbName};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]
            );
        } catch (\Throwable $e) {
            return $this->fail('配置已写入，但连接测试失败：' . $e->getMessage());
        }

        // 锁文件已由 run() 以独占方式创建，这里只补写时间戳
        file_put_contents($this->lockFile, date('Y-m-d H:i:s') . "\ninstalled_at=" . date('c') . "\n", FILE_APPEND);

        // 授权配置落库：安装期验证通过的服务器地址 / 授权码 / 令牌 / 到期
        try {
            $lic = session('install_license');
            if (is_array($lic)) {
                $set = function (string $k, string $v) {
                    \app\logic\Setting::set($k, $v);
                };
                $set('update_server_url', (string)($lic['server'] ?? ''));
                $set('license_code', (string)($lic['code'] ?? ''));
                $set('license_token', (string)($lic['token'] ?? ''));
                $set('license_expire', (string)($lic['expire'] ?? ''));
                $set('license_last_ok', date('Y-m-d H:i:s'));
                $set('license_domain', strtolower((string)$this->request->host(true)));
            }
        } catch (\Throwable $e) {
            // 落库失败不阻断安装完成提示，但记录日志便于排查
            \think\facade\Log::write('[install] 授权配置写入失败: ' . $e->getMessage(), 'notice');
        }

        return $this->ok([
            'adminUser' => $adminUser,
            'tips'      => '安装完成。请立即删除 install 目录，并将 config 目录设为只读（chmod 555）。',
        ], '安装完成');
    }
}
