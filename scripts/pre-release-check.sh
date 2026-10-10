#!/usr/bin/env bash
# OwnForm 发版前自检：发版（build-release.sh）之前必跑
# 用法: ./scripts/pre-release-check.sh
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
PHP="${PHP_BIN:-/Applications/EServer/childApp/php/php-8.2/bin/php}"
FAIL=0

echo "════ OwnForm 发版前自检 ════"

echo "[1/6] PHP 语法（app/config/route 全部文件）"
N=0; BAD=""
for f in $(find app config route -name "*.php"); do
    N=$((N+1))
    $PHP -l "$f" >/dev/null 2>&1 || { BAD="$BAD $f"; FAIL=1; }
done
[ -z "$BAD" ] && echo "  ✓ $N 个文件" || { echo "  ✗ 语法错误:$BAD"; }

echo "[2/6] 路由 → 控制器方法存在性"
$PHP -r '
$routes = file_get_contents("route/app.php");
preg_match_all("/Route::(get|post|put|delete)\((?:\"|\x27)([^\"]+?)(?:\"|\x27)\s*,\s*(?:\"|\x27)([\w\\\\]+)\/(\w+)(?:\"|\x27)/", $routes, $m, PREG_SET_ORDER);
$miss = [];
foreach ($m as $r) {
    $file = "app/controller/{$r[3]}.php";
    if (!is_file($file) || !preg_match("/function\s+{$r[4]}\s*\(/", file_get_contents($file))) $miss[] = $r[2];
}
if ($miss) { echo "  ✗ " . implode(", ", $miss) . "\n"; exit(1); }
echo "  ✓ " . count($m) . " 条路由\n";' || FAIL=1

echo "[3/6] 前端构建产物与源码一致性（关键文件落盘验证）"
# 防止"改完没生效"类事故：检查最近 2 小时内改过的 .vue/.php 是否已反映到构建产物
RECENT=$(find admin-src/src -name "*.vue" -o -name "*.ts" -mmin -120 2>/dev/null | wc -l | tr -d ' ')
if [ "$RECENT" -gt 0 ]; then
    NEWEST_SRC=$(find admin-src/src -type f -newer public/admin/index.html 2>/dev/null | wc -l | tr -d ' ')
    if [ "$NEWEST_SRC" -gt 0 ]; then
        echo "  ⚠ 源码比构建产物新（$NEWEST_SRC 个文件），请先 pnpm build 再打包"; FAIL=1
    else
        echo "  ✓ 构建产物为最新"
    fi
else
    echo "  ✓ 近 2 小时无源码改动"
fi

echo "[4/6] 敏感文件不入包检查"
LEAK=$(git ls-files 2>/dev/null | grep -vE "\.example" | grep -cE "db_local|secret_key|installed\.lock|(^|/)\.env$|^runtime/|^version/|node_modules" || true)
[ "$LEAK" -eq 0 ] && echo "  ✓ 无敏感/生成文件入库" || { echo "  ✗ 发现 $LEAK 个不该入库的文件"; FAIL=1; }

echo "[5/6] schema.sql 与实际库结构比对"
$PHP -r '
$sql = file_get_contents("install/schema.sql");
preg_match_all("~CREATE TABLE IF NOT EXISTS `\{\{prefix\}\}(\w+)` \((.*?)\)\s*ENGINE~s", $sql, $m, PREG_SET_ORDER);
$cfg = include "config/db_local.php";
$pdo = new PDO("mysql:host={$cfg["hostname"]};dbname={$cfg["database"]}", $cfg["username"], $cfg["password"]);
$probs = [];
foreach ($m as $t) {
    $cols = [];
    foreach (preg_split("/\n/", $t[2]) as $line) {
        if (preg_match("~^\s*`(\w+)`~", $line, $c) && !preg_match("/^(PRIMARY|KEY|UNIQUE|CONSTRAINT)/", trim($line))) $cols[] = $c[1];
    }
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $st->execute([$cfg["prefix"] . $t[1]]);
    $actual = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$actual) { $probs[] = "{$t[1]} 表不存在"; continue; }
    foreach ($cols as $c) if (!in_array($c, $actual)) $probs[] = "{$t[1]}.{$c} 缺失";
}
if ($probs) { echo "  ✗ " . implode("; ", $probs) . "\n"; exit(1); }
echo "  ✓ " . count($m) . " 张表与库一致\n";' || FAIL=1

echo "[6/6] 版本文件与文档一致"
VER=$(grep -oE "return '[^']*'" config/version.php | sed "s/return //; s/'//g")
DOC_VER=$(grep -oE "版本: 0\.0\.[0-9]+" scripts/宝塔部署说明.txt | head -1 | grep -oE "0\.0\.[0-9]+")
[ "$VER" = "$DOC_VER" ] && echo "  ✓ v$VER（文档一致）" || { echo "  ✗ version.php=$VER 但文档=$DOC_VER"; FAIL=1; }

echo "════════════════════════"
if [ $FAIL -eq 0 ]; then
    echo "全部通过 ✓ 可以执行 ./scripts/build-release.sh <版本号>"
else
    echo "存在失败项 ✗ 请修复后再打包"
    exit 1
fi
