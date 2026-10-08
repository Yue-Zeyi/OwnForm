<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\logic\OpLog;
use app\logic\PromoUtil;
use think\facade\Db;
use think\facade\Log;

/**
 * 引流中心管理（v2：活码系统 + 短链，数据按 user_id 隔离）
 */
class PromoApi extends BaseController
{
    /** 推广类型：活码 / 短链 */
    private const KINDS = ['qrcode', 'short'];

    /**
     * 域名池（登录用户可取）：供活码/短链的分享链接选择发布域名
     */
    public function domains()
    {
        $pool = \app\logic\Setting::get('site_domains', '');
        $domains = array_values(array_filter(array_map('trim', explode(',', $pool))));
        return $this->ok([
            'domains' => $domains,
            'current' => $this->request->host(),
        ]);
    }

    /**
     * 列表（kind=qrcode|short 分 Tab 展示）
     */
    public function index()
    {
        $page    = max(1, (int)input('page', 1));
        $size    = min(100, max(1, (int)input('size', 20)));
        $keyword = trim((string)input('keyword', ''));
        $kind    = (string)input('kind', 'qrcode');
        $deleted = (int)input('deleted', 0) === 1;

        $query = $deleted
            ? Db::name('promos')->whereNotNull('deleted_at')->when(!$this->isAdmin(), fn($q) => $q->where('user_id', $this->uid()))
            : $this->promoQuery();

        if ($keyword !== '') {
            $query->whereLike('name', '%' . self::like($keyword) . '%');
        }
        if (in_array($kind, self::KINDS, true)) {
            $query->where('type', $kind);
        }

        $total = (clone $query)->count();
        $list  = $query->order('id', 'desc')
            ->field('id, name, type, code, rotate, flow, mode, domain, title, bottom_type, bottom_text, bottom_target, click_count, status, created_at')
            ->page($page, $size)
            ->select()->toArray();

        // 列表附带条目（卡片缩略图/链接展示用）
        $ids = array_map(static fn($r) => (int)$r['id'], $list);
        $itemsMap = [];
        if ($ids) {
            $items = Db::name('promo_items')->whereIn('promo_id', $ids)
                ->field('id, promo_id, kind, target, scan_limit, weight, device, time_from, time_to, scans, longpress')
                ->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
            foreach ($items as $it) {
                $itemsMap[(int)$it['promo_id']][] = $it;
            }
        }

        foreach ($list as &$row) {
            $row['items'] = $itemsMap[(int)$row['id']] ?? [];
            $row['shareUrl'] = $deleted ? '' : self::buildShareUrl($row['code'], $row['domain']);
        }
        unset($row);

        return $this->ok(['list' => $list, 'total' => $total, 'page' => $page, 'size' => $size, 'deleted' => $deleted ? 1 : 0]);
    }

    /**
     * 详情（含条目）
     */
    public function read(int $id)
    {
        $promo = $this->loadOwnedPromo($id);
        if (!$promo) {
            return $this->fail('推广位不存在', 404);
        }
        $items = Db::name('promo_items')->where('promo_id', $id)
            ->field('id, kind, target, scan_limit, weight, device, time_from, time_to, scans, longpress')
            ->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        return $this->ok($this->present($promo, $items));
    }

    /**
     * 创建
     */
    public function save()
    {
        $data = $this->input();
        [$payload, $items] = $this->readInput($data);
        if ($payload['name'] === '') {
            return $this->fail('请填写名称');
        }
        if (!$items) {
            return $this->fail($payload['type'] === 'short' ? '至少添加一条目标链接' : '至少上传一张二维码图片');
        }

        Db::startTrans();
        try {
            $payload['user_id'] = $this->uid();
            $payload['code'] = PromoUtil::genCode();
            $payload['click_count'] = 0;
            $payload['status'] = 1;
            $payload['created_at'] = $payload['updated_at'] = $this->now();
            $id = (int)Db::name('promos')->insertGetId($payload);
            $this->saveItems($id, $items);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('promo create failed: ' . $e->getMessage());
            return $this->fail('创建失败，请稍后重试');
        }
        OpLog::write('promo', $payload['type'] === 'short' ? '创建短链' : '创建活码', $payload['name'] . '（#' . $id . '）');
        return $this->ok([
            'id' => $id,
            'code' => $payload['code'],
            'shareUrl' => self::buildShareUrl($payload['code'], $payload['domain']),
        ], '创建成功');
    }

    /**
     * 更新
     */
    public function update(int $id)
    {
        $promo = $this->loadOwnedPromo($id);
        if (!$promo) {
            return $this->fail('推广位不存在', 404);
        }
        $data = $this->input();
        [$payload, $items] = $this->readInput($data, $promo);
        if ($payload['name'] === '') {
            return $this->fail('请填写名称');
        }
        if (!$items) {
            return $this->fail($payload['type'] === 'short' ? '至少保留一条目标链接' : '至少保留一张二维码图片');
        }

        Db::startTrans();
        try {
            $payload['updated_at'] = $this->now();
            Db::name('promos')->where('id', $id)->update($payload);
            $this->saveItems($id, $items);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('promo update failed: ' . $e->getMessage());
            return $this->fail('保存失败，请稍后重试');
        }
        OpLog::write('promo', $payload['type'] === 'short' ? '编辑短链' : '编辑活码', $promo['name'] . '（#' . $id . '）');
        return $this->ok([], '已保存');
    }

    /**
     * 启用 / 停用
     */
    public function status(int $id)
    {
        $promo = $this->loadOwnedPromo($id);
        if (!$promo) {
            return $this->fail('推广位不存在', 404);
        }
        $status = (int)($this->input()['status'] ?? 1);
        if (!in_array($status, [0, 1], true)) {
            return $this->fail('状态值不合法');
        }
        Db::name('promos')->where('id', $id)->update(['status' => $status, 'updated_at' => $this->now()]);
        OpLog::write('promo', $status === 1 ? '启用' : '停用', $promo['name'] . '（#' . $id . '）');
        return $this->ok(['status' => $status], $status === 1 ? '已启用' : '已停用');
    }

    /**
     * 删除（进回收站）
     */
    public function delete(int $id)
    {
        $promo = $this->loadOwnedPromo($id);
        if (!$promo) {
            return $this->fail('推广位不存在', 404);
        }
        Db::name('promos')->where('id', $id)->update(['deleted_at' => $this->now(), 'status' => 0]);
        OpLog::write('promo', '删除', $promo['name'] . '（#' . $id . '）');
        return $this->ok([], '已删除');
    }

    /**
     * 回收站恢复
     */
    public function restore(int $id)
    {
        $promo = Db::name('promos')->whereNotNull('deleted_at')->where('id', $id)
            ->when(!$this->isAdmin(), fn($q) => $q->where('user_id', $this->uid()))
            ->find();
        if (!$promo) {
            return $this->fail('回收站中不存在该记录', 404);
        }
        Db::name('promos')->where('id', $id)->update(['deleted_at' => null, 'updated_at' => $this->now()]);
        OpLog::write('promo', '回收站恢复', $promo['name'] . '（#' . $id . '）');
        return $this->ok([], '已恢复');
    }

    /**
     * 彻底删除（连同条目与点击日志）
     */
    public function purge(int $id)
    {
        $promo = Db::name('promos')->where('id', $id)
            ->when(!$this->isAdmin(), fn($q) => $q->where('user_id', $this->uid()))
            ->find();
        if (!$promo) {
            return $this->fail('推广位不存在', 404);
        }
        Db::startTrans();
        try {
            Db::name('promo_items')->where('promo_id', $id)->delete();
            Db::name('promo_logs')->where('promo_id', $id)->delete();
            Db::name('promos')->where('id', $id)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('删除失败，请稍后重试');
        }
        OpLog::write('promo', '彻底删除', $promo['name'] . '（#' . $id . '）');
        return $this->ok([], '已彻底删除');
    }

    /**
     * 统计：趋势 / 条目分发占比（含长按）/ 设备占比 / 明细
     */
    public function stats(int $id)
    {
        $promo = $this->loadOwnedPromo($id);
        if (!$promo) {
            return $this->fail('推广位不存在', 404);
        }
        $items = Db::name('promo_items')->where('promo_id', $id)
            ->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        $itemsById = [];
        foreach ($items as $i => $it) {
            $it['label'] = $it['kind'] === 'img'
                ? '二维码 ' . ($i + 1)
                : self::shortHost((string)$it['target']);
            $itemsById[(int)$it['id']] = $it;
        }

        $base = Db::name('promo_logs')->where('promo_id', $id);

        // 近 30 日趋势
        $rows = (clone $base)
            ->where('created_at', '>=', date('Y-m-d 00:00:00', strtotime('-29 days')))
            ->field("DATE(created_at) as d, COUNT(*) as c")
            ->group('d')->select()->toArray();
        $map = [];
        foreach ($rows as $r) {
            $map[$r['d']] = (int)$r['c'];
        }
        $trend = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $trend[] = ['date' => $d, 'count' => $map[$d] ?? 0];
        }

        $today = (clone $base)->whereTime('created_at', 'today')->count();

        // 条目维度统计：分发量（日志聚合）+ 长按量（items.longpress）
        $byItem = (clone $base)->group('item_id')
            ->field('item_id, COUNT(*) c')->select()->toArray();
        $itemStats = [];
        foreach ($items as $i => $it) {
            $itemStats[] = [
                'id'        => (int)$it['id'],
                'label'     => $itemsById[(int)$it['id']]['label'] ?? ('条目 ' . ($i + 1)),
                'kind'      => $it['kind'],
                'target'    => $it['target'],
                'count'     => (int)$it['scans'],
                'longpress' => (int)$it['longpress'],
                'scanLimit' => (int)$it['scan_limit'],
                'weight'    => (int)($it['weight'] ?? 1),
                'device'    => (string)($it['device'] ?? 'all'),
                'timeFrom'  => (string)($it['time_from'] ?? ''),
                'timeTo'    => (string)($it['time_to'] ?? ''),
                'exhausted' => (int)$it['scan_limit'] > 0 && (int)$it['scans'] >= (int)$it['scan_limit'],
            ];
        }
        // item_id=0 的日志：并流模式每次访问都记 0（正常现象）；
        // 非并流出现则是「历史条目」（配置被整体替换前的旧日志）
        foreach ($byItem as $r) {
            if (!isset($itemsById[(int)$r['item_id']]) && (int)$r['c'] > 0) {
                $label = !empty($promo['flow']) ? '并流访问' : '历史条目';
                $itemStats[] = [
                    'id' => 0, 'label' => $label, 'kind' => '', 'target' => '',
                    'count' => (int)$r['c'], 'longpress' => 0, 'scanLimit' => 0, 'exhausted' => false,
                ];
            }
        }

        // 设备占比
        $byDevice = (clone $base)->group('device')
            ->field('device, COUNT(*) c')->select()->toArray();
        $deviceStats = [];
        foreach ($byDevice as $r) {
            $deviceStats[] = ['label' => $r['device'] ?: 'other', 'count' => (int)$r['c']];
        }

        // 明细（最近 200 条）
        $details = (clone $base)->order('id', 'desc')->limit(200)
            ->field('item_id, target_url, device, ip, referer, created_at')
            ->select()->toArray();
        foreach ($details as &$d) {
            $d['item_label'] = $itemsById[(int)$d['item_id']]['label'] ?? '-';
        }
        unset($d);

        return $this->ok([
            'name'       => $promo['name'],
            'type'       => $promo['type'],
            'total'      => (int)$promo['click_count'],
            'today'      => $today,
            'trend'      => $trend,
            'itemStats'  => $itemStats,
            'deviceStats'=> $deviceStats,
            'details'    => $details,
        ]);
    }

    // ---------- 内部方法 ----------

    /**
     * promos 行 + items 组装为前端详情结构
     */
    private function present(array $promo, array $items): array
    {
        return [
            'id'         => (int)$promo['id'],
            'name'       => $promo['name'],
            'type'       => $promo['type'],
            'code'       => $promo['code'],
            'rotate'     => $promo['rotate'] ?: 'seq',
            'flow'       => (int)$promo['flow'],
            'mode'       => $promo['mode'] ?: 'direct',
            'domain'     => $promo['domain'],
            'title'      => $promo['title'],
            'tip'        => $promo['tip'],
            'bottomType'   => $promo['bottom_type'] ?? 'none',
            'bottomText'   => $promo['bottom_text'] ?? '',
            'bottomTarget' => $promo['bottom_target'] ?? '',
            'fallback'     => $promo['fallback'],
            'items'      => $items,
            'clickCount' => (int)$promo['click_count'],
            'status'     => (int)$promo['status'],
            'shareUrl'   => self::buildShareUrl($promo['code'], $promo['domain']),
            'createdAt'  => $promo['created_at'],
        ];
    }

    /**
     * 读取并校验输入：[promos 载荷, items 列表]
     */
    private function readInput(array $data, array $existing = []): array
    {
        $type = in_array($data['type'] ?? '', self::KINDS, true)
            ? $data['type'] : ($existing['type'] ?? 'qrcode');

        $name = mb_substr(trim((string)($data['name'] ?? '')), 0, 100);
        $rotate = ($data['rotate'] ?? '') === PromoUtil::R_RAND ? PromoUtil::R_RAND : PromoUtil::R_SEQ;
        $domain = trim((string)($data['domain'] ?? ($existing['domain'] ?? '')));

        // 发布域名必须为空（当前域名）或在站点域名池内，防止拼出任意域名的链接
        if ($domain !== '') {
            $pool = \app\logic\Setting::get('site_domains', '');
            $domains = array_map('trim', explode(',', $pool));
            if (!in_array($domain, $domains, true)) {
                $domain = '';
            }
        }

        $payload = [
            'name'    => $name,
            'type'    => $type,
            'rotate'  => $rotate,
            'domain'  => $domain,
        ];

        $items = PromoUtil::normalizeItems($data['items'] ?? [], $type);
        if ($type === 'short') {
            // 短链：跳转方式 direct 直接 302 / guide 中转引导页
            $payload['mode'] = ($data['mode'] ?? '') === 'guide' ? 'guide' : 'direct';
            // 类型切换残留清理：活码专属字段随类型一并清空，
            // 否则改回活码时旧文案会"复活"
            $payload['flow'] = 0;
            $payload['title'] = '';
            $payload['tip'] = '';
            $payload['fallback'] = '';
            $payload['bottom_type'] = 'none';
            $payload['bottom_text'] = '';
            $payload['bottom_target'] = '';
        } else {
            // 活码：并流开关 + 落地页文案
            $payload['mode'] = 'direct';
            $payload['flow'] = (int)!empty($data['flow']);
            $payload['title'] = mb_substr(trim((string)($data['title'] ?? '')), 0, 100);
            $payload['tip'] = mb_substr(trim((string)($data['tip'] ?? '')), 0, 200);
            $payload['fallback'] = mb_substr(trim((string)($data['fallback'] ?? '')), 0, 200);

            // 底部条：none 不显示 / text 纯文字 / link 跳转按钮
            // link 目标两类：http(s) 外链（协议白名单+拒内网）或站内页面分享码
            $bottomType = in_array($data['bottom_type'] ?? '', ['none', 'text', 'link'], true)
                ? $data['bottom_type'] : 'none';
            $bottomTarget = '';
            if ($bottomType === 'link') {
                $bottomTarget = trim((string)($data['bottom_target'] ?? ''));
                if ($bottomTarget !== '' && preg_match('/^https?:\/\//i', $bottomTarget)) {
                    if (!PromoUtil::isSafeTargetUrl($bottomTarget)) {
                        $bottomTarget = '';
                    }
                } elseif ($bottomTarget !== ''
                    && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $bottomTarget)) {
                    $bottomTarget = '';
                }
            }
            $payload['bottom_type'] = $bottomType;
            $payload['bottom_text'] = $bottomType === 'none'
                ? '' : mb_substr(trim((string)($data['bottom_text'] ?? '')), 0, 100);
            $payload['bottom_target'] = $bottomType === 'link' ? $bottomTarget : '';
        }

        return [$payload, $items];
    }

    /**
     * 重写保存条目（编辑 = 全量替换，sort 按提交顺序）
     *
     * 同 id 条目回迁 scans/longpress：扫码上限进度与长按统计
     * 不因一次编辑而清零（否则改个标题，已满的群又会重新参与分发）。
     */
    private function saveItems(int $promoId, array $items): void
    {
        $oldStats = Db::name('promo_items')->where('promo_id', $promoId)
            ->column('scans, longpress', 'id');
        Db::name('promo_items')->where('promo_id', $promoId)->delete();
        $rows = [];
        foreach ($items as $i => $it) {
            $row = [
                'promo_id'   => $promoId,
                'sort'       => $i,
                'kind'       => $it['kind'],
                'target'     => $it['target'],
                'scan_limit' => $it['scan_limit'],
                'weight'     => $it['weight'],
                'device'     => $it['device'],
                'time_from'  => $it['time_from'],
                'time_to'    => $it['time_to'],
            ];
            $oldId = (int)($it['id'] ?? 0);
            if ($oldId > 0 && isset($oldStats[$oldId])) {
                $row['scans'] = (int)$oldStats[$oldId]['scans'];
                $row['longpress'] = (int)$oldStats[$oldId]['longpress'];
            }
            $rows[] = $row;
        }
        if ($rows) {
            Db::name('promo_items')->insertAll($rows);
        }
    }

    private function promoQuery()
    {
        $q = Db::name('promos')->whereNull('deleted_at');
        if (!$this->isAdmin()) {
            $q->where('user_id', $this->uid());
        }
        return $q;
    }

    private function loadOwnedPromo(int $id): ?array
    {
        return $this->promoQuery()->where('id', $id)->find() ?: null;
    }

    /**
     * 拼接分享链接（实现统一在 PromoUtil::buildShareUrl）
     */
    private static function buildShareUrl(string $code, string $domain): string
    {
        return PromoUtil::buildShareUrl($code, $domain);
    }

    /**
     * 链接展示缩写：主机名 + 路径摘要（超过 40 字符截断）
     */
    private static function shortHost(string $url): string
    {
        $host = (string)parse_url($url, PHP_URL_HOST) ?: $url;
        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
        if ($path !== '') {
            // 路径仅取摘要：多链接同域轮询时靠它区分条目
            $path = mb_strlen($path) > 14 ? mb_substr($path, 0, 14) . '…' : $path;
            $host = $host . '/' . $path;
        }
        return mb_strlen($host) > 40 ? mb_substr($host, 0, 40) . '…' : $host;
    }

    /**
     * LIKE 通配符转义
     */
    private static function like(string $s): string
    {
        // 必须先转反斜杠再转通配符，否则已转义的 \% 会被二次转义
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }
}
