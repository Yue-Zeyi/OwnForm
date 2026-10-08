<?php
declare(strict_types=1);

namespace app\logic;

use think\facade\Cache;
use think\facade\Db;
use think\Request;

/**
 * 引流中心工具（v2：活码 + 短链）
 *
 * 活码：一个二维码长期不变，扫码落地页按轮询方式分发二维码图片，
 *       单张图片达到扫码上限后自动切换下一张（群满自动换群）。
 * 短链：一个短码背后可配多条目标链接（轮询域名），顺序或随机跳转。
 *
 * 顺序轮询指针持久化在缓存（promo_poll_{id} 递增计数），
 * 用计数对条目总数取模定位——分发均匀且稳定。
 */
class PromoUtil
{
    /** 条目类型：img 二维码图片（活码）/ url 目标链接（短链） */
    public const I_IMG = 'img';
    public const I_URL = 'url';

    /** 轮询方式 */
    public const R_SEQ = 'seq';
    public const R_RAND = 'rand';

    private const SLUG_CHARS = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * 生成唯一短码
     *
     * @access public
     */
    public static function genCode(): string
    {
        $max = strlen(self::SLUG_CHARS) - 1;
        do {
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= self::SLUG_CHARS[random_int(0, $max)];
            }
        } while (Db::name('promos')->where('code', $code)->count() > 0);
        return $code;
    }

    /**
     * 校验短码格式
     *
     * @access public
     */
    public static function isValidCode(string $code): bool
    {
        return preg_match('/^[a-z2-9]{10}$/', $code) === 1;
    }

    /**
     * 归一化条目列表（创建/编辑时校验）
     *
     * 每条结构：{ kind, target, scan_limit }
     *
     * @param string $kind promo 类型：qrcode（图片）| short（链接）
     *
     * @access public
     */
    public static function normalizeItems(mixed $items, string $kind): array
    {
        $want = $kind === 'short' ? self::I_URL : self::I_IMG;
        $out = [];
        foreach (array_slice(is_array($items) ? $items : [], 0, 50) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $target = trim((string)($t['target'] ?? ''));
            if ($target === '') {
                continue;
            }
            if ($want === self::I_IMG) {
                // 图片两类地址：本站上传相对路径（上传接口返回值），
                // 或带图片扩展名的 http(s) 外链
                if (str_starts_with($target, '/storage/uploads/')) {
                    // 本站上传目录白名单：放行（展示时按当前域名解析）
                } elseif (!self::isSafeTargetUrl($target)
                    || !preg_match('/\.(png|jpe?g|gif|webp|bmp)(\?|$)/i', $target)) {
                    continue;
                }
            } elseif (!self::isSafeTargetUrl($target)) {
                continue;
            }
            // 分发规则：时段必须成对，半截规则视为无效（与旧版策略一致）
            $from = self::normalizeTime((string)($t['time_from'] ?? ''));
            $to = self::normalizeTime((string)($t['time_to'] ?? ''));
            if (($from !== '') !== ($to !== '')) {
                $from = $to = '';
            }

            $out[] = [
                // id：编辑时用于回迁 scans/longpress 历史计数（非持久化列）
                'id'         => (int)($t['id'] ?? 0),
                'kind'       => $want,
                'target'     => mb_substr($target, 0, 1000),
                // 扫码上限：0 不限；上限用于群满自动切换
                'scan_limit' => min(1000000, max(0, (int)($t['scan_limit'] ?? 0))),
                // 权重：加权轮询配比；设备 + 时段：精准分流规则
                'weight'     => min(100, max(1, (int)($t['weight'] ?? 1))),
                'device'     => in_array($t['device'] ?? 'all', ['all', 'ios', 'android', 'pc'], true)
                    ? ($t['device'] ?? 'all') : 'all',
                'time_from'  => $from,
                'time_to'    => $to,
            ];
        }
        return $out;
    }

    /**
     * 归一化 HH:MM 时间，非法返回空串
     *
     * @access private
     */
    private static function normalizeTime(string $t): string
    {
        $t = trim($t);
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1 ? $t : '';
    }

    /**
     * 从 UA 检测设备类别：ios / android / pc（仅用于统计维度）
     *
     * @access public
     */
    public static function detectDevice(Request $request): string
    {
        $ua = strtolower((string)$request->header('user-agent', ''));
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod')) {
            return 'ios';
        }
        if (str_contains($ua, 'android')) {
            return 'android';
        }
        return 'pc';
    }

    /**
     * 过滤未达扫码上限的条目（上限驱动群满自动切换）
     *
     * @access public
     */
    public static function filterLive(array $items): array
    {
        return array_values(array_filter($items, static fn($i) =>
            (int)($i['scan_limit'] ?? 0) <= 0 || (int)($i['scans'] ?? 0) < (int)$i['scan_limit']
        ));
    }

    /**
     * 按设备与时段规则过滤候选条目
     *
     * 全部被规则过滤掉时回退全量：宁可进入兜底条目，也不能让访客落空。
     *
     * @access public
     */
    public static function filterByRules(array $items, string $device, string $his): array
    {
        $now = str_replace(':', '', $his); // HHMM 便于比较
        $hit = [];
        foreach ($items as $t) {
            if (($t['device'] ?? 'all') !== 'all' && $t['device'] !== $device) {
                continue;
            }
            $from = str_replace(':', '', (string)($t['time_from'] ?? ''));
            $to = str_replace(':', '', (string)($t['time_to'] ?? ''));
            if ($from !== '' && $to !== '') {
                $inWindow = $from <= $to
                    ? ($now >= $from && $now <= $to)
                    : ($now >= $from || $now <= $to); // 跨零点
                if (!$inWindow) {
                    continue;
                }
            }
            $hit[] = $t;
        }
        return $hit ?: $items;
    }

    /**
     * 加权选择：seq 加权轮询（计数器取模，配比稳定收敛）/ rand 加权随机
     *
     * @access public
     */
    public static function pickWeighted(int $promoId, array $items, string $rotate): array
    {
        if (count($items) === 1) {
            return $items[0];
        }
        $total = 0;
        foreach ($items as $c) {
            $total += max(1, (int)($c['weight'] ?? 1));
        }
        if ($rotate === self::R_RAND) {
            $pos = random_int(0, $total - 1);
        } else {
            $counter = (int)Cache::store('file')->inc('promo_poll_' . $promoId);
            $pos = $counter > 0 ? ($counter - 1) % $total : random_int(0, $total - 1);
        }
        $acc = 0;
        foreach ($items as $c) {
            $acc += max(1, (int)($c['weight'] ?? 1));
            if ($pos < $acc) {
                return $c;
            }
        }
        return $items[0];
    }

    /**
     * 选择一个条目：上限过滤 → 设备/时段过滤 → 加权选择
     *
     * 全部条目已达上限时返回 null（调用方展示兜底文案）。
     *
     * @param string $device 当前设备（空 = 不按设备过滤）
     * @param string $his   当前时间 HH:MM（空 = 不按时段过滤）
     *
     * @access public
     */
    public static function pickItem(
        int $promoId,
        array $items,
        string $rotate,
        string $device = '',
        string $his = ''
    ): ?array {
        $live = self::filterLive($items);
        if (!$live) {
            return null;
        }
        $pool = ($device !== '' && $his !== '')
            ? self::filterByRules($live, $device, $his)
            : $live;
        return self::pickWeighted($promoId, $pool, $rotate);
    }

    /**
     * 外链安全校验（仅 http/https，拒绝内网）
     *
     * @access public
     */
    public static function isSafeTargetUrl(string $url): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
        if ($url === '' || mb_strlen($url) > 2000) {
            return false;
        }
        $probe = strtolower(preg_replace('/[\s\x00-\x20]/', '', $url) ?? '');
        $probe = str_replace(['&colon;', '&#58;', '&#x3a;'], ':', $probe);
        if (!str_starts_with($probe, 'http://') && !str_starts_with($probe, 'https://')) {
            return false;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $host = trim((string)parse_url($url, PHP_URL_HOST), '[]');
        if ($host === '') {
            return false;
        }
        // 先把非点分写法（2130706433 / 0x7f000001 / 017700000001 / 127.1）
        // 归一为点分十进制——FILTER_VALIDATE_IP 不认这些形态，
        // 否则 isPrivateAddress 根本不会被调用，内网写法绕过校验
        if (preg_match('/^(\d+|[0-7]+|0x[0-9a-fA-F]+)$/', $host)
            || preg_match('/^\d{1,3}(\.\d{1,3}){1,3}$/', $host)) {
            $long = ip2long($host);
            if ($long === false) {
                return false;
            }
            $host = long2ip($long);
        }
        // IP 字面量直接判网段；域名不做 DNS 解析（TOCTOU 取舍，与全站策略一致）
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !Ip::isPrivateAddress($host);
        }
        return !in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)
            && !str_ends_with(strtolower($host), '.local')
            && !str_ends_with(strtolower($host), '.internal');
    }

    /**
     * 活码/短链访问地址
     *
     * @access public
     */
    public static function shareUrl(string $code): string
    {
        return request()->domain() . '/q/' . $code;
    }

    /**
     * 拼接分享链接：指定发布域名则用该域名（落地/备用域名策略），
     * 否则用当前域名。域名为空或不在域名池时由调用方先行校验。
     *
     * @access public
     */
    public static function buildShareUrl(string $code, string $domain = ''): string
    {
        if ($domain !== '') {
            $scheme = str_starts_with($domain, 'http') ? '' : 'https://';
            return $scheme . $domain . '/q/' . $code;
        }
        return self::shareUrl($code);
    }
}
