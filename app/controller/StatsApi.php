<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\logic\FieldUtil;
use think\facade\Db;

/**
 * 单表单统计分析
 */
class StatsApi extends BaseController
{
    /** 参与聚合的最大提交条数 */
    private const MAX_ROWS = 20000;

    public function index(int $formId)
    {
        $form = $this->loadVisibleForm($formId);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        $fields = FieldUtil::extractFields(json_decode((string)$form['fields_json'], true) ?: []);

        $needReview = self::needReview($form);
        $sq = Db::name('form_submissions')->where('form_id', $formId)->where('status', 1)->whereNull('deleted_at');
        // own 范围授权：统计仅聚合归属自己渠道的提交
        $perm = $this->permOf($form);
        if (!$perm['owner'] && $perm['scope'] === 'own') {
            $ownIds = $this->ownChannelIds($formId);
            $sq->whereIn('channel_id', $ownIds ?: [0]);
        }
        $pendingScope = function ($q) use ($perm, $formId) {
            if (!$perm['owner'] && $perm['scope'] === 'own') {
                $ownIds = $this->ownChannelIds($formId);
                $q->whereIn('channel_id', $ownIds ?: [0]);
            }
        };

        $summary = ['total' => 0, 'today' => 0, 'uniqueIp' => 0, 'device' => ['pc' => 0, 'mobile' => 0, 'other' => 0]];
        $deviceRows = (clone $sq)
            ->field("device, COUNT(*) c, SUM(created_at >= '" . date('Y-m-d 00:00:00') . "') today, COUNT(DISTINCT ip) uip")
            ->group('device')->select()->toArray();
        foreach ($deviceRows as $r) {
            $key = in_array($r['device'], ['pc', 'mobile'], true) ? $r['device'] : 'other';
            $summary['device'][$key] += (int)$r['c'];
            $summary['today'] += (int)$r['today'];
            $summary['uniqueIp'] += (int)$r['uip'];
        }
        $summary['total'] = array_sum($summary['device']);
        $summary['pending'] = 0;
        if ($needReview) {
            $pq = Db::name('form_submissions')->where('form_id', $formId)->whereNull('deleted_at')->where('status', 0);
            $pendingScope($pq);
            $summary['pending'] = $pq->count();
        }

        // 近 14 日趋势
        $trendRows = (clone $sq)
            ->whereTime('created_at', '>=', date('Y-m-d 00:00:00', strtotime('-13 days')))
            ->field("DATE(created_at) d, COUNT(*) c")->group('d')->select()->toArray();
        $trendMap = [];
        foreach ($trendRows as $r) {
            $trendMap[$r['d']] = (int)$r['c'];
        }
        $trend = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $trend[] = ['date' => $d, 'count' => $trendMap[$d] ?? 0];
        }

        // 按字段聚合：分批流式解码，避免一次性把 N 条 MEDIUMTEXT
        // 全部 json_decode 进内存（2 万条 × 数十 KB 可轻松 OOM）
        $dataArray = [];
        $lastId = 0;
        $count = 0;
        $batch = 500;
        while ($count < self::MAX_ROWS) {
            $rows = (clone $sq)->where('id', '<', $count ? $lastId : PHP_INT_MAX)
                ->order('id', 'desc')->limit($batch)
                ->field('id, data_json')->select()->toArray();
            if (!$rows) {
                break;
            }
            foreach ($rows as $r) {
                $lastId = (int)$r['id'];
                $dataArray[] = json_decode((string)$r['data_json'], true) ?: [];
                $count++;
            }
            unset($rows);
        }
        $truncated = $count >= self::MAX_ROWS;

        $fieldStats = [];
        foreach ($fields as $f) {
            $fieldStats[] = self::statField($f, $dataArray);
        }

        return $this->ok([
            'form'      => ['id' => (int)$form['id'], 'title' => $form['title'], 'createdAt' => $form['created_at']],
            'summary'   => $summary,
            'trend'     => $trend,
            'fields'    => $fieldStats,
            'truncated' => $truncated,
        ]);
    }

    private static function needReview(array $form): bool
    {
        $settings = json_decode((string)$form['settings_json'], true) ?: [];
        return !empty($settings['needReview']);
    }

    private static function statField(array $field, array $dataArray): array
    {
        $type = $field['type'];
        $key  = $field['field'];

        // 收集该字段全部值
        $values = [];
        $answered = 0;
        foreach ($dataArray as $data) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $v = $data[$key];
            $isEmpty = $v === '' || $v === null || $v === [];
            if (!$isEmpty) {
                $answered++;
            }
            $values[] = $v;
        }

        $base = [
            'field'    => $key,
            'title'    => $field['title'] ?: $key,
            'type'     => $type,
            'required' => $field['required'],
            'answered' => $answered,
        ];

        if (in_array($type, ['radio', 'select', 'checkbox'], true)) {
            $countMap = [];
            foreach ($field['options'] as $opt) {
                $countMap[(string)$opt['value']] = 0;
            }
            foreach ($values as $v) {
                $arr = is_array($v) ? $v : [$v];
                foreach ($arr as $item) {
                    $s = is_scalar($item) ? (string)$item : json_encode($item, JSON_UNESCAPED_UNICODE);
                    $countMap[$s] = ($countMap[$s] ?? 0) + 1;
                }
            }
            $distribution = [];
            foreach ($field['options'] as $opt) {
                $distribution[] = ['label' => $opt['label'], 'value' => $countMap[(string)$opt['value']]];
            }
            // 未知值（选项被删除过等）
            $knownValues = array_map(fn($o) => (string)$o['value'], $field['options']);
            $other = 0;
            foreach ($countMap as $v => $c) {
                if (!in_array($v, $knownValues, true) && $c > 0) {
                    $other += $c;
                }
            }
            if ($other > 0) {
                $distribution[] = ['label' => '其他', 'value' => $other];
            }
            $base['chart'] = 'pie';
            $base['distribution'] = $distribution;
            return $base;
        }

        if (in_array($type, ['inputNumber', 'slider', 'rate'], true)) {
            $nums = [];
            foreach ($values as $v) {
                foreach ((is_array($v) ? $v : [$v]) as $item) {
                    if (is_numeric($item)) {
                        $nums[] = (float)$item;
                    }
                }
            }
            $base['chart'] = 'number';
            $base['count'] = count($nums);
            if ($nums) {
                $base['avg'] = round(array_sum($nums) / count($nums), 2);
                $base['min'] = min($nums);
                $base['max'] = max($nums);
                // 简单分桶
                $buckets = 5;
                $span = ($base['max'] - $base['min']) ?: 1;
                $hist = array_fill(0, $buckets, 0);
                foreach ($nums as $n) {
                    $idx = min($buckets - 1, (int)floor(($n - $base['min']) / ($span / $buckets)));
                    $hist[$idx]++;
                }
                $distribution = [];
                for ($i = 0; $i < $buckets; $i++) {
                    $lo = $base['min'] + $span / $buckets * $i;
                    $hi = $base['min'] + $span / $buckets * ($i + 1);
                    $distribution[] = [
                        'label' => round($lo, 2) . ' ~ ' . round($hi, 2),
                        'value' => $hist[$i],
                    ];
                }
                $base['distribution'] = $distribution;
            } else {
                $base['avg'] = 0;
                $base['min'] = 0;
                $base['max'] = 0;
                $base['distribution'] = [];
            }
            return $base;
        }

        // input / textarea / date / time / cascader / upload / city 等：高频值 Top10
        $freq = [];
        foreach ($values as $v) {
            if ($v === '' || $v === null || $v === []) {
                continue;
            }
            $arr = is_array($v) ? $v : [$v];
            foreach ($arr as $item) {
                if (is_array($item)) {
                    $s = $item['name'] ?? $item['url'] ?? '';
                } else {
                    $s = (string)$item;
                }
                $s = trim($s);
                if ($s === '') {
                    continue;
                }
                $freq[$s] = ($freq[$s] ?? 0) + 1;
            }
        }
        arsort($freq);
        $top = array_slice(array_map(fn($k, $c) => ['label' => $k, 'value' => $c], array_keys($freq), array_values($freq)), 0, 10);
        $base['chart'] = 'rank';
        $base['unique'] = count($freq);
        $base['distribution'] = $top;
        return $base;
    }
}
