<?php
declare(strict_types=1);

/**
 * 引流中心 v2 一次性数据迁移（0.0.6 → 0.0.7）
 *
 * 旧版 promos.targets JSON（url/image/form/page 多目标）
 * → 新版 promo_items（img 图片 / url 链接）：
 *   - image 目标 → 活码图片（img）
 *   - url 目标 → 短网址轮询链接（url）
 *   - form/page 目标（v2 已移除该形态）→ 转为站内绝对链接（url）
 * 同时：type=link → short；mode=shell → guide。
 * 全部条目迁完后删除 promos.targets 旧列。
 *
 * 执行：php scripts/migrate-promo-v2.php（可重复执行，幂等）
 */

$root = dirname(__DIR__);
$config = include $root . '/config/db_local.php';
if (!is_array($config) || empty($config['database'])) {
    fwrite(STDERR, "读取 config/db_local.php 失败\n");
    exit(1);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['hostname'], $config['hostport'] ?? 3306, $config['database']),
    $config['username'],
    $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$prefix = $config['prefix'] ?? 'of_';

// 旧列已删除 = 已迁移过
$cols = $pdo->query("SHOW COLUMNS FROM `{$prefix}promos` LIKE 'targets'")->fetchAll();
if (!$cols) {
    echo "targets 列不存在，无需迁移\n";
    exit(0);
}

$rows = $pdo->query("SELECT id, type, mode, targets FROM `{$prefix}promos`")->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare(
    "INSERT INTO `{$prefix}promo_items`
       (`promo_id`, `sort`, `kind`, `target`, `scan_limit`, `weight`, `device`, `time_from`, `time_to`, `scans`)
     VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, 0)"
);
$migrated = 0;
foreach ($rows as $row) {
    $targets = json_decode((string)$row['targets'], true);
    if (!is_array($targets)) {
        continue;
    }
    $sort = 0;
    foreach ($targets as $t) {
        if (!is_array($t) || !isset($t['type'], $t['target'])) {
            continue;
        }
        $type = (string)$t['type'];
        $target = trim((string)$t['target']);
        if ($target === '') {
            continue;
        }
        // v2 形态：活码图片 / 链接；form/page 转为站内路径链接
        if ($type === 'image') {
            $kind = 'img';
        } elseif ($type === 'form') {
            $kind = 'url';
            $target = '/s/' . $target;
        } elseif ($type === 'page') {
            $kind = 'url';
            $target = '/p/' . $target;
        } else {
            $kind = 'url';
        }
        // v1 目标上的分流规则原样迁移
        $weight = min(100, max(1, (int)($t['weight'] ?? 1)));
        $device = in_array($t['device'] ?? 'all', ['all', 'ios', 'android', 'pc'], true)
            ? ($t['device'] ?? 'all') : 'all';
        $from = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($t['time_from'] ?? ''))
            ? (string)$t['time_from'] : '';
        $to = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($t['time_to'] ?? ''))
            ? (string)$t['time_to'] : '';
        if (($from !== '') !== ($to !== '')) {
            $from = $to = '';
        }
        $stmt->execute([
            (int)$row['id'], $sort++, $kind, mb_substr($target, 0, 1000),
            $weight, $device, $from, $to,
        ]);
        $migrated++;
    }
    // 类型与跳转方式对齐 v2 语义
    $newType = $row['type'] === 'link' ? 'short' : 'qrcode';
    $newMode = $row['mode'] === 'shell' ? 'guide' : ($row['mode'] === 'guide' ? 'guide' : 'direct');
    $pdo->prepare("UPDATE `{$prefix}promos` SET `type` = ?, `mode` = ? WHERE `id` = ?")
        ->execute([$newType, $newMode, (int)$row['id']]);
}

// 迁移完成后删除旧列（幂等：开头已校验存在性）
$pdo->exec("ALTER TABLE `{$prefix}promos` DROP COLUMN `targets`");
echo "迁移完成：{$migrated} 条条目，promos 共 " . count($rows) . " 行；targets 旧列已删除\n";
