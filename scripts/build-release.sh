#!/usr/bin/env bash
# ============================================================
# Olive Note - リリースビルドスクリプト
#
# 使い方:
#   bash scripts/build-release.sh
#   bash scripts/build-release.sh 1.2.3       # バージョンを上書き
#
# 出力:
#   build/olivenote-X.Y.Z.zip      （顧客に配布するパッケージ）
#   build/manifest.json            （アップデート用マニフェスト）
#   build/CHECKSUMS.txt
# ============================================================
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PKG_DIR="$ROOT_DIR/olivenote-package"
BUILD_DIR="$ROOT_DIR/build"

# バージョン: 引数 > VERSION ファイル
if [[ -n "${1:-}" ]]; then
    VERSION="$1"
    echo "$VERSION" > "$PKG_DIR/app/VERSION"
else
    VERSION=$(cat "$PKG_DIR/app/VERSION" | tr -d '[:space:]')
fi

ZIP_NAME="olivenote-${VERSION}.zip"
ZIP_PATH="$BUILD_DIR/$ZIP_NAME"

echo "🌿 Olive Note v${VERSION} をビルドします"

# クリーンアップ
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/staging/olivenote"

# パッケージファイルを staging へコピー
# - config/config.php は除外（顧客が自分で設定）
# - data/backups, data/cache, data/tmp の中身は除外
# - installer.locked は除外
rsync -av \
    --exclude='config/config.php' \
    --exclude='data/backups/*' \
    --exclude='data/cache/*' \
    --exclude='data/tmp/*' \
    --exclude='data/MAINTENANCE.lock' \
    --exclude='installer.locked' \
    --exclude='.DS_Store' \
    --exclude='Thumbs.db' \
    "$PKG_DIR/" "$BUILD_DIR/staging/olivenote/"

# .gitkeep は維持（フォルダ自体は必要）
find "$BUILD_DIR/staging/olivenote/data" -type d -exec touch {}/.gitkeep \;

# ZIPに固める
cd "$BUILD_DIR/staging"
if command -v zip >/dev/null 2>&1; then
    zip -r -q "$ZIP_PATH" olivenote
else
    # zipコマンドがない場合はPython
    python3 -c "
import shutil
shutil.make_archive('${ZIP_PATH%.zip}', 'zip', '.', 'olivenote')
"
fi
cd "$ROOT_DIR"

# SHA256
SHA256=$(sha256sum "$ZIP_PATH" | awk '{print $1}')
SIZE=$(stat -c%s "$ZIP_PATH" 2>/dev/null || stat -f%z "$ZIP_PATH")

# CHECKSUMS.txt
echo "${SHA256}  ${ZIP_NAME}" > "$BUILD_DIR/CHECKSUMS.txt"

# CHANGELOG から該当バージョンの抜粋を取得
CHANGELOG="$ROOT_DIR/CHANGELOG.md"
NOTES=""
if [[ -f "$CHANGELOG" ]]; then
    NOTES=$(awk -v ver="$VERSION" '
        $0 ~ "^## \\["ver"\\]"   { found=1; next }
        found && /^## \[/        { exit }
        found                    { print }
    ' "$CHANGELOG" | sed 's/"/\\"/g' | awk 'BEGIN{ORS="\\n"} {print}')
fi

# manifest.json
cat > "$BUILD_DIR/manifest.json" <<EOF
{
  "latest": "${VERSION}",
  "download_url": "https://github.com/Ha-llelujah3528/olivenote/releases/download/v${VERSION}/${ZIP_NAME}",
  "sha256": "${SHA256}",
  "size_bytes": ${SIZE},
  "min_php_version": "8.0",
  "changelog": "${NOTES}",
  "published_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
EOF

# クリーンアップ
rm -rf "$BUILD_DIR/staging"

echo "✅ ビルド完了:"
echo "   ZIP:      $ZIP_PATH (${SIZE} bytes)"
echo "   SHA256:   $SHA256"
echo "   Manifest: $BUILD_DIR/manifest.json"
echo ""
echo "次のステップ:"
echo "  1. ZIPを GitHub Release にアップロード"
echo "  2. manifest.json を main ブランチにコミット (or GitHub Pages にホスト)"
echo "  3. 顧客サーバーのアップデーター画面が manifest.json を取得して通知"
