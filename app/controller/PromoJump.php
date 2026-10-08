<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\logic\Ip;
use app\logic\PromoUtil;
use think\facade\Cache;
use think\facade\Db;
use think\Response;

/**
 * 引流中心 · 公开入口（v2：活码系统 + 短链）
 *
 * /q/{code}        活码 → 落地页展示二维码图（长按识别）；
 *                  短链 → 302 直接跳转 / 中转引导页
 * /api/q/{code}/press  长按识别上报（活码图片）
 *
 * 安全边界：
 *   - 跳转目标只认后台配置值（不接收 URL 参数覆盖），天然防开放重定向
 *   - 图片/外链目标保存时已做协议白名单 + 内网拦截
 *   - 落地页与中转页只注入配置生成的地址，无用户可控的 HTML 拼接
 */
class PromoJump extends BaseController
{
    /** 点击日志保留天数 */
    private const LOG_KEEP_DAYS = 90;

    public function jump(string $code)
    {
        $promo = $this->loadPromo($code);
        if (!$promo) {
            return $this->plainPage('链接不存在或已停用');
        }
        $items = Db::name('promo_items')->where('promo_id', (int)$promo['id'])
            ->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        if (!$items) {
            return $this->plainPage('链接尚未配置内容');
        }

        if (($promo['type'] ?? 'qrcode') === 'short') {
            return $this->jumpShort($promo, $items);
        }
        return $this->jumpQrcode($promo, $items);
    }

    /**
     * 长按识别上报：活码落地页图片被长按（微信内长按识码的前置动作）
     */
    public function press(string $code)
    {
        $promo = $this->loadPromo($code);
        if (!$promo || ($promo['type'] ?? '') !== 'qrcode') {
            return $this->ok([], 'ok');
        }
        $itemId = (int)input('item', 0);
        $hit = Db::name('promo_items')->where('promo_id', (int)$promo['id'])
            ->where('id', $itemId)->where('kind', 'img')->find();
        if (!$hit) {
            return $this->ok([], 'ok');
        }
        try {
            // 同 IP 同图片 60 秒去重，防刷新灌水
            $key = 'promo_press_' . md5(Ip::get($this->request) . '|' . $itemId);
            if (!Cache::store('file')->get($key)) {
                Cache::store('file')->set($key, 1, 60);
                Db::name('promo_items')->where('id', $itemId)->inc('longpress')->update();
            }
        } catch (\Throwable $e) {
            // 统计失败不影响响应
        }
        return $this->ok([], 'ok');
    }

    // ---------- 内部方法 ----------

    /**
     * 短链：轮询选链接 → 记录 → 直接 302 / 中转页
     */
    private function jumpShort(array $promo, array $items): Response
    {
        $picked = PromoUtil::pickItem(
            (int)$promo['id'],
            $items,
            (string)($promo['rotate'] ?? 'seq'),
            PromoUtil::detectDevice($this->request),
            date('H:i')
        );
        if (!$picked) {
            return $this->plainPage('链接暂不可用，请稍后再试');
        }
        $this->track((int)$promo['id'], (int)$picked['id'], (string)$picked['target']);
        Db::name('promo_items')->where('id', (int)$picked['id'])->inc('scans')->update();

        if (($promo['mode'] ?? 'direct') === 'guide') {
            return $this->guide((string)$picked['target']);
        }
        return redirect((string)$picked['target'], 302);
    }

    /**
     * 活码：轮询选二维码图 → 落地页展示（长按识别）
     *
     * 并流模式：全部未满的二维码同时展示（群活码多群并推），
     * 无切换概念，items.scans 不递增，仅记访问日志。
     */
    private function jumpQrcode(array $promo, array $items): Response
    {
        if (!empty($promo['flow'])) {
            $live = PromoUtil::filterLive($items);
            if (!$live) {
                return $this->fallbackPage($promo);
            }
            $shown = PromoUtil::filterByRules($live, PromoUtil::detectDevice($this->request), date('H:i'));
            $this->track((int)$promo['id'], 0, (string)$shown[0]['target']);
            return $this->qrcodePage($promo, $shown, true);
        }

        $picked = PromoUtil::pickItem(
            (int)$promo['id'],
            $items,
            (string)($promo['rotate'] ?? 'seq'),
            PromoUtil::detectDevice($this->request),
            date('H:i')
        );
        if (!$picked) {
            return $this->fallbackPage($promo);
        }
        // scans 递增驱动「扫码上限自动切换」：达到上限的图不再参与分发
        Db::name('promo_items')->where('id', (int)$picked['id'])->inc('scans')->update();
        $this->track((int)$promo['id'], (int)$picked['id'], (string)$picked['target']);
        return $this->qrcodePage($promo, [$picked], false);
    }

    private function loadPromo(string $code): ?array
    {
        if (!PromoUtil::isValidCode($code)) {
            return null;
        }
        return Db::name('promos')->where('code', $code)
            ->whereNull('deleted_at')->where('status', 1)->find() ?: null;
    }

    /**
     * 记录点击明细
     *
     * 同 IP 同推广位 60 秒去重（防刷新灌水）；展示照常执行。
     * 每日首次访问触发一次过期日志清理。
     */
    private function track(int $promoId, int $itemId, string $url): void
    {
        try {
            self::cleanOldLogs();
            $ip = Ip::get($this->request);
            $dedupKey = 'promo_seen_' . md5($ip . '|' . $promoId);
            if (Cache::store('file')->get($dedupKey)) {
                return;
            }
            Cache::store('file')->set($dedupKey, 1, 60);

            Db::name('promo_logs')->insert([
                'promo_id'     => $promoId,
                'item_id'      => $itemId,
                'target_url'   => mb_substr($url, 0, 500),
                'device'       => PromoUtil::detectDevice($this->request),
                'ip'           => $ip,
                'ua'           => mb_substr((string)$this->request->header('user-agent', ''), 0, 500),
                'referer'      => mb_substr((string)$this->request->header('referer', ''), 0, 500),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            Db::name('promos')->where('id', $promoId)->inc('click_count')->update();
        } catch (\Throwable $e) {
            // 统计失败不影响跳转
        }
    }

    /** 每日一次：清理过期点击日志 */
    private static function cleanOldLogs(): void
    {
        $flag = 'promo_log_rotate_' . date('Ymd');
        if (Cache::store('file')->get($flag)) {
            return;
        }
        // 分批删除：百万行级时单条无 LIMIT 的 DELETE 会长时间锁表
        do {
            $deleted = Db::name('promo_logs')
                ->where('created_at', '<', date('Y-m-d H:i:s', time() - self::LOG_KEEP_DAYS * 86400))
                ->limit(5000)
                ->delete();
        } while ($deleted >= 5000);
        // 删除成功后再置 flag：若删除抛错，当天后续请求还能重试清理
        Cache::store('file')->set($flag, 1, 86400);
    }

    /**
     * 活码落地页：大图二维码 + 长按识别提示（微信场景优先）
     */
    private function qrcodePage(array $promo, array $items, bool $flow): Response
    {
        $imgs = [];
        foreach ($items as $it) {
            if (($it['kind'] ?? '') !== 'img') {
                continue;
            }
            $imgs[] = [
                'id'  => (int)$it['id'],
                'src' => self::absoluteUrl((string)$it['target']),
            ];
        }
        if (!$imgs) {
            return $this->fallbackPage($promo);
        }

        $title = trim((string)($promo['title'] ?? '')) !== '' ? $promo['title'] : '扫码添加';
        $tip = trim((string)($promo['tip'] ?? '')) !== '' ? $promo['tip'] : '长按识别二维码';
        // 底部条：text 纯文字 / link 整条可点跳转（站内页面拼 /p/ 前缀）
        $bottom = [
            'type'   => in_array($promo['bottom_type'] ?? '', ['text', 'link'], true)
                ? $promo['bottom_type'] : 'none',
            'text'   => trim((string)($promo['bottom_text'] ?? '')),
            'target' => '',
        ];
        if ($bottom['type'] === 'link' && $bottom['text'] !== '') {
            $t = trim((string)($promo['bottom_target'] ?? ''));
            if ($t !== '') {
                $bottom['target'] = preg_match('/^https?:\/\//i', $t) ? $t : '/p/' . $t;
            } else {
                $bottom['type'] = 'text'; // 有文字无目标时降级为纯文字
            }
        }
        $data = json_encode([
            'code' => $promo['code'],
            'imgs' => $imgs,
            'title' => $title,
            'tip' => $tip,
            'bottom' => $bottom,
            'flow' => $flow,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>__TITLE_HTML__</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif;
  background: #f6f7f9; color: #303133; }
/* 微信官方风格：顶部安全验证条通栏 */
.safe { background: #e7f8e7; color: #07c160; font-size: 13px;
  padding: 10px 16px; display: flex; align-items: center; gap: 6px; }
.safe svg { flex-shrink: 0; }
.page { max-width: 420px; margin: 0 auto; padding: 22px 16px calc(28px + env(safe-area-inset-bottom)); text-align: center; }
body.has-bar .page { padding-bottom: calc(88px + env(safe-area-inset-bottom)); }
/* 引导语：公告通知条样式（白卡底 + 中性图标，通栏圆角） */
.tip { display: flex; align-items: flex-start; gap: 7px; background: #fff; color: #595f66;
  border: 1px solid #edf0f3; font-size: 13px; padding: 10px 12px; border-radius: 8px;
  margin: 0 0 16px; text-align: left; line-height: 1.65; }
.tip svg { flex-shrink: 0; margin-top: 2px; }
/* 二维码白卡：通栏窄卡，微圆角轻投影 */
.qcard { background: #fff; width: min(100%, 280px); margin: 0 auto 18px; padding: 14px;
  border-radius: 10px; border: 1px solid #eef0f3;
  box-shadow: 0 1px 2px rgba(31,56,88,.03); }
.qcard img { width: 100%; display: block; }
/* 卡下小字：微信官方风格的信息行 */
.caption { color: #9b9ea3; font-size: 12.5px; margin: 0; line-height: 1.7; }
.flow-badge { display: inline-block; margin: 2px auto 14px; padding: 4px 12px; border-radius: 999px;
  background: #fff; border: 1px solid #edf0f3; color: #7f8389; font-size: 12px; }
/* 底部橙色条：纯文字提示 / 跳转按钮（带箭头） */
.notice { position: fixed; left: 14px; right: 14px; bottom: calc(14px + env(safe-area-inset-bottom));
  background: linear-gradient(90deg, #ff9a2e, #ff6a00); color: #fff; font-size: 14px; font-weight: 600;
  text-align: center; padding: 12px 14px; border-radius: 10px;
  box-shadow: 0 4px 14px rgba(255,106,0,.32); cursor: default; }
.notice.is-link { cursor: pointer; }
.notice.is-link:active { transform: scale(.98); }
.notice .ar { font-weight: 400; margin-left: 4px; }
</style>
</head>
<body>
<div class="safe">
  <svg width="16" height="16" viewBox="0 0 64 64" fill="none"><circle cx="32" cy="32" r="30" fill="#07c160"/><path d="M20 33l8 8 16-17" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/></svg>
  <span>二维码已通过安全验证</span>
</div>
<div class="page">
  <div class="tip">
    <svg width="15" height="15" viewBox="0 0 64 64" fill="none"><path d="M38 12L20 24H12a4 4 0 00-4 4v8a4 4 0 004 4h8l18 12V12z" fill="#86909c"/><path d="M46 24a12 12 0 010 16" stroke="#86909c" stroke-width="4" stroke-linecap="round"/><path d="M52 17a20 20 0 010 30" stroke="#86909c" stroke-width="4" stroke-linecap="round" opacity=".55"/></svg>
    <span id="qp-tip"></span>
  </div>
  <div id="qp-zone"></div>
</div>
<div class="notice" id="qp-bottom" style="display:none"></div>
<script>
var QP = __DATA_JSON__;
(function () {
  document.title = QP.title;
  document.getElementById('qp-tip').textContent = QP.tip;
  var zone = document.getElementById('qp-zone');
  var h = '';
  for (var i = 0; i < QP.imgs.length; i++) {
    h += '<div class="qcard"><img id="qp-img-' + i + '" alt="二维码" src="' + QP.imgs[i].src + '"></div>';
  }
  zone.innerHTML = h;
  if (QP.flow && QP.imgs.length > 1) {
    var b = document.createElement('span');
    b.className = 'flow-badge';
    b.textContent = '共 ' + QP.imgs.length + ' 个二维码，任选一个识别';
    zone.appendChild(b);
  }
  if (QP.bottom && QP.bottom.type !== 'none' && QP.bottom.text) {
    var n = document.getElementById('qp-bottom');
    n.textContent = QP.bottom.text;
    if (QP.bottom.type === 'link' && QP.bottom.target) {
      n.className = 'notice is-link';
      n.setAttribute('role', 'button');
      var ar = document.createElement('span');
      ar.className = 'ar';
      ar.textContent = '→';
      n.appendChild(ar);
      n.addEventListener('click', function () {
        // target 已由服务端拼好（外链原样 / 站内页面带 /p/ 前缀），直接跳转
        location.href = QP.bottom.target;
      });
    }
    n.style.display = 'block';
    document.body.className = 'has-bar';
  }
  // 长按识别上报：触屏长按 0.5s 视为一次（微信内长按识码的前置动作），
  // 同一会话同一图片只报一次；PC 右键菜单也计入
  var pressed = {};
  function report(i) {
    if (pressed[i]) return;
    pressed[i] = 1;
    try { sessionStorage.setItem('qp_' + QP.code + '_' + i, '1'); } catch (e) {}
    var img = QP.imgs[i];
    var body = 'item=' + img.id;
    if (navigator.sendBeacon) {
      navigator.sendBeacon('/api/q/' + QP.code + '/press', new Blob([body], { type: 'application/x-www-form-urlencoded' }));
    } else {
      fetch('/api/q/' + QP.code + '/press', { method: 'POST', body: body, keepalive: true });
    }
  }
  QP.imgs.forEach(function (_, i) {
    var el = document.getElementById('qp-img-' + i);
    try { if (sessionStorage.getItem('qp_' + QP.code + '_' + i)) pressed[i] = 1; } catch (e) {}
    var timer = null;
    el.addEventListener('touchstart', function () {
      timer = setTimeout(function () { report(i); }, 500);
    }, { passive: true });
    el.addEventListener('touchend', function () { if (timer) clearTimeout(timer); });
    el.addEventListener('touchmove', function () { if (timer) clearTimeout(timer); });
    el.addEventListener('contextmenu', function () { report(i); });
  });
})();
</script>
</body>
</html>
HTML;
        $html = str_replace('__DATA_JSON__', $data, $html);
        $html = str_replace('__TITLE_HTML__', htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), $html);
        return $this->hardened(Response::create($html)->contentType('text/html', 'utf-8'), false);
    }

    /**
     * 兜底页：全部二维码达到扫码上限（群满）
     */
    private function fallbackPage(array $promo): Response
    {
        $msg = trim((string)($promo['fallback'] ?? '')) !== ''
            ? $promo['fallback'] : '所有二维码均已满员，请联系管理员补充';
        $msg = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>提示</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif;
  background: #f6f7f9; color: #303133; display: flex; flex-direction: column;
  align-items: center; justify-content: center; padding: 24px 16px; }
.wrap { width: 100%; max-width: 380px; }
.card { background: #fff; border-radius: 16px; overflow: hidden;
  box-shadow: 0 8px 30px rgba(31,56,120,.10); }
.accent { height: 5px; background: linear-gradient(90deg, #409eff, #6f9dff); }
.inner { padding: 32px 28px 30px; text-align: center; font-size: 16px; line-height: 1.9; color: #1f2d3d; }
.foot { margin: 14px 0 0; font-size: 12px; color: #b3bac4; text-align: center; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card"><div class="accent"></div><div class="inner">__MSG__</div></div>
  <p class="foot">页面内容由推广方提供</p>
</div>
</body>
</html>
HTML;
        $html = str_replace('__MSG__', $msg, $html);
        return $this->hardened(Response::create($html)->contentType('text/html', 'utf-8'), false);
    }

    /**
     * 中转引导页：微信等封闭环境提示「右上角浏览器打开」，
     * 非封闭环境（普通浏览器）自动直接跳转。
     */
    private function guide(string $url): Response
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>正在跳转…</title>
<style>
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif;
  background: #f2f4f8; color: #303133;
  display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 24px 16px; }
.wrap { width: 100%; max-width: 380px; }
.card { background: #fff; border-radius: 16px; overflow: hidden;
  box-shadow: 0 8px 30px rgba(31,56,120,.10); }
.accent { height: 5px; background: linear-gradient(90deg, #409eff, #6f9dff); }
.inner { padding: 32px 28px 30px; text-align: center; }
.tip { font-size: 17px; font-weight: 650; margin: 0 0 10px; color: #1f2d3d; }
.how { font-size: 14px; color: #909399; line-height: 1.7; margin: 0 0 22px; }
.how b { color: #409eff; }
.btn { display: block; background: #409eff; color: #fff; text-decoration: none;
  padding: 12px; border-radius: 8px; font-size: 15px; }
.foot { margin: 14px 0 0; font-size: 12px; color: #b3bac4; text-align: center; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="accent"></div>
    <div class="inner">
      <p class="tip">点击右上角 ···</p>
      <p class="how">选择「<b>在浏览器中打开</b>」即可继续访问</p>
      <a class="btn" id="go" href="#" data-url="#" rel="noopener">继续访问</a>
    </div>
  </div>
  <p class="foot">页面内容由推广方提供</p>
</div>
<script>
(function () {
  var url = document.getElementById('go').getAttribute('data-url');
  var go = document.getElementById('go');
  go.setAttribute('href', url);
  // 非微信环境直接跳转
  if (!/MicroMessenger/i.test(navigator.userAgent)) {
    location.replace(url);
  }
})();
</script>
</body>
</html>
HTML;
        // 目标地址经后端保存时校验（http/https + 拒内网），此处仅注入到属性
        $html = str_replace('data-url="#"', 'data-url="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"', $html);
        return $this->hardened(Response::create($html)->contentType('text/html', 'utf-8'), false);
    }

    /** 极简提示页（无样式依赖，任何环境可显示）。无效短码应返回 404 而非 200，避免被搜索引擎收录 */
    private function plainPage(string $msg): Response
    {
        return $this->hardened(
            Response::create(
                '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>提示</title><p style="padding:40px;text-align:center;color:#606266;font-family:sans-serif">'
                . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>',
                'html',
                404
            )->contentType('text/html', 'utf-8'),
            false
        );
    }

    /**
     * 公开页通用安全响应头
     *
     * @param bool $denyFrame 禁止被 iframe 嵌入（防点击劫持）；
     *                        中转页/提示页传 false——它们本身服务于封闭环境跳转
     */
    private function hardened(Response $resp, bool $denyFrame = true): Response
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // 落地页 HTML 禁止启发式缓存：版本升级后浏览器必须立即拿到新页面
            'Cache-Control' => 'no-cache, private',
        ];
        if ($denyFrame) {
            $headers['X-Frame-Options'] = 'DENY';
            $headers['Content-Security-Policy'] = "frame-ancestors 'none'";
        }
        $resp->header($headers);
        return $resp;
    }

    /**
     * 上传目录相对路径补全为绝对地址（落地页图片在任意环境可展示）
     */
    private static function absoluteUrl(string $target): string
    {
        if (str_starts_with($target, '/')) {
            return request()->domain() . $target;
        }
        return $target;
    }
}
