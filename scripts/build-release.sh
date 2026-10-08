#!/usr/bin/env bash
# OwnForm 版本打包脚本：构建前端 → 汇集运行文件 → 产出宝塔部署包与更新包
# 用法: ./scripts/build-release.sh 0.0.1
set -e
VER="${1:?用法: build-release.sh <版本号>}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAGE="$(mktemp -d)/ownform"
VERDIR="$ROOT/version/$VER"
mkdir -p "$VERDIR"

echo "[1/4] 构建前端 (admin-src -> public/admin)"
cd "$ROOT/admin-src"
pnpm build >/dev/null

echo "[2/4] 汇集运行文件"
cd "$ROOT"
mkdir -p "$STAGE"
rsync -a "$ROOT/" "$STAGE/" \
  --exclude "admin-src/" \
  --exclude "version/" \
  --exclude "scripts/" \
  --exclude "runtime/" \
  --exclude "updates/" \
  --exclude ".git/" \
  --exclude ".zcode/" \
  --exclude "config/db_local.php" \
  --exclude "config/secret_key.php" \
  --exclude "install/installed.lock" \
  --exclude "public/storage/uploads/" \
  --exclude ".env" \
  --exclude ".travis.yml" \
  --exclude ".zcodeignore" \
  --exclude ".DS_Store"

# 保留必要空目录（runtime 需同时带 .htaccess 防直访）
mkdir -p "$STAGE/runtime" "$STAGE/runtime/log" "$STAGE/runtime/session" "$STAGE/runtime/cache" "$STAGE/public/storage/uploads" "$STAGE/install"
touch "$STAGE/runtime/.gitkeep" "$STAGE/public/storage/uploads/.gitkeep" "$STAGE/install/.gitkeep"
cp "$ROOT/runtime/.htaccess" "$STAGE/runtime/.htaccess" 2>/dev/null || printf 'deny from all\n' > "$STAGE/runtime/.htaccess"
# 管理后台入口禁止缓存（前端构建会清空 public/admin，此文件由打包脚本生成）
cat > "$STAGE/public/admin/.htaccess" <<'HTACCESS'
# 管理后台 SPA：入口文档禁止缓存（升级覆盖文件后浏览器立即生效），
# 带内容哈希的 static/ 资源可长期缓存
<IfModule mod_headers.c>
  <FilesMatch "index\.html$">
    Header set Cache-Control "no-cache, no-store, must-revalidate"
  </FilesMatch>
</IfModule>
HTACCESS
rm -f "$STAGE/install/installed.lock" "$STAGE/config/db_local.php" "$STAGE/config/secret_key.php"

echo "[3/4] 附带部署文档、升级 SQL 与 Nginx 配置样例"
cp "$ROOT/scripts/宝塔部署说明.txt" "$STAGE/部署说明-宝塔.txt" 2>/dev/null || true
mkdir -p "$STAGE/scripts"
cp "$ROOT/scripts/nginx-site.conf" "$STAGE/scripts/" 2>/dev/null || true
# 数据库增量升级脚本全部随包（按文件名版本号顺序执行）
for f in "$ROOT"/scripts/upgrade-*.sql; do
  [ -e "$f" ] && cp "$f" "$STAGE/scripts/" || true
done

echo "[4/4] 打包（根目录直出：解压即覆盖到站点根，无多余一层目录）"
cd "$STAGE"
# zip 对已存在的包是「合并更新」语义，不先删会把上一轮的旧哈希
# chunk 一起留在包里越滚越大，必须先移除旧包
rm -f "$VERDIR/ownform-$VER-宝塔部署包.zip" "$VERDIR/ownform-$VER-更新包.zip"
zip -rq "$VERDIR/ownform-$VER-宝塔部署包.zip" .
# 更新包：覆盖式更新用（不含安装器与用户数据目录）
rm -f "$STAGE/install/.gitkeep"
zip -rq "$VERDIR/ownform-$VER-更新包.zip" . -x "install/*" "public/storage/uploads/*"

rm -rf "$(dirname "$STAGE")"
echo "完成: $VERDIR"
ls -lh "$VERDIR"
