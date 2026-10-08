<?php
declare(strict_types=1);

namespace app\logic;

use think\facade\Cache;

/**
 * 内置图像验证码（GD 自绘，session 校验，一次性）
 *
 * 防护要点：
 *   - 生成接口限频，避免被自动化流水线无限取码
 *   - 单个 token 的尝试次数上限，防止拿到码后暴力枚举
 *   - 提高字符空间与图像复杂度（5 位、扭曲曲线、字符粘连）
 */
class Captcha
{
    /** 验证码在 session 中的存储期（秒） */
    private const TTL = 300;

    /** 验证码位数 */
    private const LENGTH = 5;

    /** 单个 token 最多允许的校验次数 */
    private const MAX_ATTEMPTS = 3;

    /** 每 IP 每分钟最多生成次数 */
    private const MAKE_PER_MINUTE = 10;

    /** 生成频率的存储键前缀 */
    private const RATE_PREFIX = 'captcha_make_';

    public static function token(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * 生成验证码：返回 ['token' => xx, 'img' => data:image/png;base64,...]
     *
     * @access public
     */
    public static function make(?string $ip = null): array
    {
        $ip = $ip !== null ? $ip : \app\logic\Ip::get();
        $rateKey = self::RATE_PREFIX . md5($ip);
        $made = (int)Cache::store('file')->get($rateKey, 0);
        if ($made >= self::MAKE_PER_MINUTE) {
            return ['error' => '验证码获取过于频繁，请稍后再试'];
        }
        Cache::store('file')->set($rateKey, $made + 1, 60);

        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $token = self::token();
        session('of_captcha_' . $token, [
            'code'     => $code,
            'expire'   => time() + self::TTL,
            'attempts' => 0,
        ]);

        return ['token' => $token, 'img' => self::draw($code)];
    }

    /**
     * 校验（尝试次数超限即销毁，无论成败都一次性作废）
     */
    public static function check(string $token, string $input): bool
    {
        $token = preg_replace('/[^a-f0-9]/', '', $token);
        if ($token === '') {
            return false;
        }
        $key  = 'of_captcha_' . $token;
        $data = session($key);
        session($key, null);
        if (!$data || !is_array($data) || ($data['expire'] ?? 0) < time()) {
            return false;
        }
        // 尝试次数超限：直接判失败，防止拿到 token 后暴力枚举
        if ((int)($data['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            return false;
        }
        return hash_equals(strtoupper((string)$data['code']), strtoupper(trim($input)));
    }

    /**
     * 记一次校验失败（累计到上限后作废该 token）
     *
     * check() 已是一次性消费，此处用于统计同一 token 的重复尝试。
     */
    public static function recordFailure(string $token): void
    {
        $token = preg_replace('/[^a-f0-9]/', '', $token);
        if ($token === '') {
            return;
        }
        $key = 'of_captcha_try_' . $token;
        $n = (int)Cache::store('file')->get($key, 0) + 1;
        Cache::store('file')->set($key, $n, self::TTL);
        if ($n >= self::MAX_ATTEMPTS) {
            session('of_captcha_' . $token, null);
        }
    }

    private static function draw(string $code): string
    {
        $w = 150; $h = 48;
        $img = imagecreatetruecolor($w, $h);
        // 透明底 + 浅色背景，避免纯白背景被二值化后字符轮廓过于规整
        $bg = imagecolorallocate($img, random_int(240, 252), random_int(240, 252), random_int(242, 255));
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);

        // 干扰网格
        for ($i = 0; $i < 6; $i++) {
            $c = imagecolorallocatealpha($img, random_int(150, 220), random_int(150, 220), random_int(150, 220), random_int(80, 120));
            imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }

        // 噪点加密
        for ($i = 0; $i < 400; $i++) {
            $c = imagecolorallocate($img, random_int(100, 220), random_int(100, 220), random_int(100, 220));
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), $c);
        }

        // 干扰曲线（用二次贝塞尔折线近似），比直线更难被视觉模型剥离
        self::drawWavyLine($img, $w, $h);

        $len   = strlen($code);
        $charW = (int)(($w - 16) / $len);
        for ($i = 0; $i < $len; $i++) {
            $x   = 8 + $i * $charW + random_int(-2, 3);
            $y   = random_int(6, 12);
            $tmp = imagecreatetruecolor(24, 32);
            $tbg = imagecolorallocate($tmp, 255, 255, 255);
            imagefilledrectangle($tmp, 0, 0, 24, 32, $tbg);
            // 颜色必须在 $tmp 上分配：GD 的颜色索引是「每图像」局部值，
            // 拿 $img 上分配的颜色去画 $tmp 会取到错误索引（表现为字符带黑底方块）
            $c = imagecolorallocate($tmp, random_int(20, 90), random_int(20, 90), random_int(40, 120));
            // TTF 字体优先，内置点阵字体仅作兜底
            $ttf = self::findFont();
            if ($ttf !== null) {
                imagettftext($tmp, random_int(18, 22), random_int(-25, 25), 2, 24, $c, $ttf, $code[$i]);
            } else {
                imagestring($tmp, 5, 4, 8, $code[$i], $c);
            }
            imagecolortransparent($tmp, $tbg);

            $angle   = random_int(-28, 28);
            $rotated = imagerotate($tmp, $angle, 0);
            imagecopymerge($img, $rotated, (int)$x, (int)$y, 0, 0, imagesx($rotated), imagesy($rotated), 88);
            imagedestroy($tmp);
            if ($rotated instanceof \GdImage) {
                imagedestroy($rotated);
            }
        }

        ob_start();
        imagepng($img);
        $bin = (string)ob_get_clean();
        imagedestroy($img);
        return 'data:image/png;base64,' . base64_encode($bin);
    }

    /**
     * 画一条随机贝塞尔干扰曲线
     */
    private static function drawWavyLine($img, int $w, int $h): void
    {
        $c = imagecolorallocatealpha($img, random_int(120, 200), random_int(120, 200), random_int(120, 200), random_int(70, 110));
        $x0 = random_int(0, (int)($w / 3));
        $y0 = random_int(0, $h);
        $x1 = random_int((int)($w * 2 / 3), $w);
        $y1 = random_int(0, $h);
        $cx = (int)(($x0 + $x1) / 2 + random_int(-25, 25));
        $cy = random_int(0, $h);
        $prevX = $x0;
        $prevY = $y0;
        for ($t = 1; $t <= 40; $t++) {
            $u = $t / 40;
            $inv = 1 - $u;
            $x = (int)($inv * $inv * $x0 + 2 * $inv * $u * $cx + $u * $u * $x1);
            $y = (int)($inv * $inv * $y0 + 2 * $inv * $u * $cy + $u * $u * $y1);
            imagesetthickness($img, random_int(1, 2));
            imageline($img, $prevX, $prevY, $x, $y, $c);
            $prevX = $x;
            $prevY = $y;
        }
        imagesetthickness($img, 1);
    }

    /**
     * 查找可用的 TTF 字体（优先中文字体以覆盖全部字符）
     */
    private static function findFont(): ?string
    {
        static $cached = false;
        static $font = null;
        if ($cached) {
            return $font;
        }
        $cached = true;

        // 首选：随应用内置的字体 —— 路径在站点根内不受 open_basedir 限制，
        // 也不依赖系统是否安装了字体（各发行版/面板的字体路径差异极大）
        $candidates = [
            app()->getRootPath() . 'public/static/vendor/fonts/DejaVuSans.ttf',
            // 系统字体仅作补充。探测必须加 @ 抑制警告：宝塔等环境会给站点
            // 配置 open_basedir，裸 is_file() 的 E_WARNING 会被 ThinkPHP
            // 转为 ErrorException，直接把验证码接口打成 500
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/wqy/wqy-microhei.ttc',
            '/usr/share/fonts/wenquanyi/wqy-microhei/wqy-microhei.ttc',
            '/Library/Fonts/Arial Unicode.ttf',
            '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
            '/System/Library/Fonts/Helvetica.ttc',
            'C:/Windows/Fonts/arial.ttf',
        ];
        foreach ($candidates as $path) {
            if (@is_file($path) && @is_readable($path)) {
                $font = $path;
                break;
            }
        }
        return $font;
    }
}