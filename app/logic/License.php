<?php
declare(strict_types=1);

namespace app\logic;

use think\facade\Db;
use think\facade\Log;
use ZipArchive;

/**
 * 授权与在线更新客户端
 *
 * - 激活：向授权服务器提交 license + 当前域名，换取 token（此后心跳/更新都携带）
 * - 心跳：verify 上报当前版本（授权后台可见客户端版本与最近在线时间）
 * - 热更新：manifest（新版+sha256）→ 下载 → 校验 → 解压覆盖 → 执行升级 SQL → 写版本号
 *
 * 安全：全部走 HTTPS（生产）；包下载 URL 由服务器按授权签发；应用前做 sha256 比对。
 */
class License
{
    /** 当前版本（config/version.php 唯一真源） */
    public static function currentVersion(): string
    {
        static $v = null;
        if ($v === null) {
            $v = (string)(@include app()->getRootPath() . 'config/version.php');
        }
        return $v ?: '0.0.1';
    }

    /** 递归删除目录（在线更新工作区清理用） */
    private static function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f);
        }
        @rmdir($dir);
    }

    public static function serverUrl(): string
    {
        return rtrim(trim((string)Setting::get('update_server_url')), '/');
    }

    public static function licenseCode(): string
    {
        return trim((string)Setting::get('license_code'));
    }

    public static function domain(): string
    {
        $domains = array_filter(array_map('trim', explode(',', (string)Setting::get('site_domains'))));
        if ($domains) {
            return preg_replace('#^https?://#', '', strtolower($domains[0]));
        }
        return strtolower((string)request()->host(true));
    }

    private static function token(): string
    {
        static $t = null;
        if ($t === null) {
            $t = trim((string)Setting::get('license_token'));
        }
        return $t ?: '';
    }

    public static function isConfigured(): bool
    {
        return self::serverUrl() !== '' && self::licenseCode() !== '';
    }

    /** 宽限期（天）：最后一次成功校验后允许继续正常运行的时间 */
    public const GRACE_DAYS = 30;

    /**
     * 授权运行状态：
     *   ok       有效授权（心跳在宽限期内）
     *   grace    宽限期内（曾激活但近期心跳失败/未验证）
     *   locked   超出宽限期（后台只读 + 访客提交拦截）
     * 未启用授权体系（服务器地址未配置）视为 ok——避免老用户升级后被锁
     */
    public static function runtimeState(): string
    {
        if (!self::isConfigured()) {
            return 'ok';
        }
        if (!self::token()) {
            return 'grace'; // 有配置无令牌 = 未完成激活
        }
        $last = (string)Setting::get('license_last_ok', '');
        if ($last === '') {
            return 'grace';
        }
        $days = (time() - strtotime($last)) / 86400;
        return $days <= self::GRACE_DAYS ? 'ok' : 'locked';
    }

    /**
     * 每日心跳：后台请求时静默调用（缓存节流），失败不影响当天使用
     * 全部失败时保留 license_last_ok 不动（宽限期自然流逝）
     */
    public static function dailyCheck(): void
    {
        if (!self::isConfigured() || Cache::get('license_checked_' . date('Ymd'))) {
            return;
        }
        Cache::set('license_checked_' . date('Ymd'), 1, 86400);
        try {
            $res = self::call('/api/verify');
            if (!empty($res['ok'])) {
                Setting::set('license_last_ok', date('Y-m-d H:i:s'));
                if (!empty($res['expire_at'])) {
                    Setting::set('license_expire', (string)$res['expire_at']);
                }
            }
        } catch (\Throwable $e) {
            // 服务器不可达：离线码兜底（配置了才尝试）
            $offline = trim((string)Setting::get('license_offline_code'));
            if ($offline !== '') {
                try {
                    $res = self::call('/api/offline-verify', ['offline' => $offline]);
                    if (!empty($res['ok'])) {
                        Setting::set('license_last_ok', date('Y-m-d H:i:s'));
                    }
                } catch (\Throwable $e2) {
                    /* 双通道都失败：留待宽限期机制处理 */
                }
            }
        }
    }

    /**
     * 只读锁判定（lock 时业务侧调用）：锁定状态下禁止写操作
     */
    public static function isLocked(): bool
    {
        return self::runtimeState() === 'locked';
    }

    /**
     * 离线激活：客户粘贴离线码（安装向导/关于系统在服务器不可达时使用）
     */
    public static function activateOffline(string $offlineCode): array
    {
        try {
            $res = self::call('/api/offline-verify', ['offline' => $offlineCode]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => '无法连接授权服务器，请检查网络'];
        }
        if (empty($res['ok'])) {
            return ['ok' => false, 'msg' => (string)($res['msg'] ?? '离线码无效')];
        }
        Setting::set('license_offline_code', trim($offlineCode));
        Setting::set('license_last_ok', date('Y-m-d H:i:s'));
        return ['ok' => true, 'grace_days' => (int)($res['grace_days'] ?? 90)];
    }

    /**
     * 服务器 API 请求（POST，表单）
     */
    private static function call(string $path, array $extra = []): array
    {
        $body = http_build_query(array_merge([
            'license' => self::licenseCode(),
            'domain'  => self::domain(),
            'token'   => self::token(),
            'version' => self::currentVersion(),
        ], $extra));
        // 授权中心部署形态各异（伪静态 / PATH_INFO / 子目录），
        // 依次尝试三种 URL 形态，任一返回合法 JSON 即成功并记忆
        $candidates = [
            self::serverUrl() . $path,                              // 伪静态 / PATH_INFO
            self::serverUrl() . '/index.php?s=' . urlencode($path), // ?s= 伪静态
            self::serverUrl() . '/index.php' . $path,               // PATH_INFO 显式
        ];
        $static = trim((string)Setting::get('license_api_url'));
        if ($static !== '') {
            array_unshift($candidates, $static . $path);
        }
        $lastErr = '授权服务器无有效响应';
        foreach ($candidates as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $raw = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false) {
                $lastErr = '连接失败' . ($err ? '：' . $err : '');
                continue;
            }
            $data = json_decode((string)$raw, true);
            if (!is_array($data)) {
                $lastErr = '响应异常(HTTP ' . $status . ')——请检查授权中心伪静态配置';
                continue;
            }
            // 记住可用形态，后续直接命中
            if ($static === '') {
                Setting::set('license_api_url', rtrim($url, '/'));
            }
            $data['_status'] = $status;
            return $data;
        }
        throw new \RuntimeException($lastErr);
    }

    /**
     * 激活：服务器绑定域名并签发 token
     */
    public static function activate(string $licenseCode): array
    {
        Setting::set('license_code', $licenseCode);
        $res = self::call('/api/activate');
        if (empty($res['ok'])) {
            Setting::set('license_code', '');
            return ['ok' => false, 'msg' => (string)($res['msg'] ?? '激活失败')];
        }
        Setting::set('license_token', (string)($res['token'] ?? ''));
        Setting::set('license_domain', (string)($res['domain'] ?? self::domain()));
        Setting::set('license_expire', (string)($res['expire_at'] ?? ''));
        OpLog::write('sys', '授权激活', '授权码 ' . $licenseCode . ' 绑定 ' . self::domain());
        return ['ok' => true, 'expire' => $res['expire_at'] ?? ''];
    }

    /**
     * 心跳校验（设置页打开授权 tab 时触发，节流 1 小时）
     */
    public static function verify(): array
    {
        if (!self::isConfigured() || !self::token()) {
            return ['ok' => false, 'msg' => '未配置授权'];
        }
        try {
            $res = self::call('/api/verify');
            return ['ok' => (bool)($res['ok'] ?? false), 'msg' => (string)($res['msg'] ?? ''),
                    'expire' => (string)($res['expire_at'] ?? '')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    /**
     * 检查更新：返回最新包信息（无更新时 update=false）
     */
    public static function checkUpdate(): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'msg' => '请先配置更新服务器与授权码'];
        }
        $res = self::call('/api/manifest');
        if (empty($res['ok'])) {
            return ['ok' => false, 'msg' => (string)($res['msg'] ?? '检查失败')];
        }
        return [
            'ok'        => true,
            'update'    => (bool)($res['update'] ?? false),
            'current'   => self::currentVersion(),
            'version'   => (string)($res['version'] ?? ''),
            'notes'     => (string)($res['notes'] ?? ''),
            'url'       => (string)($res['url'] ?? ''),
            'sha256'    => (string)($res['sha256'] ?? ''),
        ];
    }

    /**
     * 应用热更新：下载 → sha256 → 解压 → 覆盖 → 升级 SQL → 写版本号
     */
    public static function applyUpdate(string $version, string $url, string $sha256): array
    {
        if (!self::isConfigured() || $version === '' || $url === '') {
            return ['ok' => false, 'msg' => '更新信息不完整'];
        }
        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'msg' => 'PHP 缺少 zip 扩展，无法在线更新'];
        }
        $root = app()->getRootPath();
        $work = $root . 'runtime' . DIRECTORY_SEPARATOR . 'update_work';
        if (is_dir($work)) {
            self::rmdirRecursive($work);
        }
        @mkdir($work, 0755, true);

        // 1) 下载
        $zipPath = $work . DIRECTORY_SEPARATOR . 'update.zip';
        $ch = curl_init(self::serverUrl() . $url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $bin = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if (!$bin) {
            return ['ok' => false, 'msg' => '更新包下载失败：' . $err];
        }
        file_put_contents($zipPath, $bin);

        // 2) 完整性校验
        if ($sha256 !== '' && strtolower(hash_file('sha256', $zipPath)) !== strtolower($sha256)) {
            return ['ok' => false, 'msg' => '更新包校验失败（SHA256 不符），已中止'];
        }

        // 3) 解压
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'msg' => '更新包解压失败'];
        }
        $zip->extractTo($work . DIRECTORY_SEPARATOR . 'files');
        $zip->close();
        $filesDir = $work . DIRECTORY_SEPARATOR . 'files';
        if (!is_dir($filesDir)) {
            return ['ok' => false, 'msg' => '更新包结构异常'];
        }

        // 4) 升级 SQL（包内 scripts/upgrade-*.sql，按文件名顺序执行）
        $sqlDir = $filesDir . DIRECTORY_SEPARATOR . 'scripts';
        $sqlDone = [];
        if (is_dir($sqlDir)) {
            try {
                $cfg = config('database.connections.' . config('database.default'));
                $pdo = new \PDO(
                    "mysql:host={$cfg['hostname']};dbname={$cfg['database']};charset=utf8mb4",
                    $cfg['username'], $cfg['password'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                $sqls = glob($sqlDir . DIRECTORY_SEPARATOR . 'upgrade-*.sql') ?: [];
                sort($sqls);
                foreach ($sqls as $file) {
                    $content = (string)file_get_contents($file);
                    // 语句以行尾分号分隔（升级 SQL 内不出现跨行字符串）
                    foreach (preg_split('/;\s*\n/', $content) ?: [] as $stmt) {
                        $stmt = trim($stmt);
                        if ($stmt === '' || str_starts_with($stmt, '--')) continue;
                        $pdo->exec($stmt);
                    }
                    $sqlDone[] = basename($file);
                }
            } catch (\Throwable $e) {
                Log::write('[update] SQL 执行失败: ' . $e->getMessage(), 'notice');
                return ['ok' => false, 'msg' => '升级 SQL 执行失败：' . $e->getMessage() . '（文件已保留，可手动恢复）'];
            }
        }

        // 5) 覆盖文件（排除用户数据与运行时）
        $copied = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($filesDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            $rel = substr((string)$file, strlen($filesDir) + 1);
            $rel = str_replace('\\', '/', $rel);
            if (str_starts_with($rel, 'runtime/') || str_starts_with($rel, 'public/storage/uploads/')
                || str_ends_with($rel, '.env') || str_starts_with($rel, 'config/db_local.php')
                || str_starts_with($rel, 'config/secret_key.php') || str_starts_with($rel, 'install/')) {
                continue;
            }
            $target = $root . $rel;
            $dir = dirname($target);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            if (@copy((string)$file, $target)) {
                @chmod($target, 0644);
                $copied++;
            }
        }

        // 6) 写版本号
        file_put_contents($root . 'config' . DIRECTORY_SEPARATOR . 'version.php',
            "<?php\n/** 系统版本号（在线更新与授权校验的唯一版本真源） */\nreturn '" . $version . "';\n", LOCK_EX);

        // 7) 清理与加速生效
        if (function_exists('opcache_reset')) @opcache_reset();
        self::rmdirRecursive($work);

        Log::write('[update] 热更新完成 v' . $version . '：覆盖 ' . $copied . ' 个文件，SQL ' . ($sqlDone ? implode(',', $sqlDone) : '无'), 'notice');
        OpLog::write('sys', '在线热更新', '升级至 v' . $version . '（覆盖 ' . $copied . ' 文件' . ($sqlDone ? '，执行 ' . implode(',', $sqlDone) : '') . '）');

        return ['ok' => true, 'version' => $version, 'copied' => $copied, 'sql' => $sqlDone];
    }
}
