#!/usr/bin/env bash
# Builds the release package: ds-cookie-consent.zip containing the single top folder ds-cookie-consent/.
#
# Usage:  bash tools/release/build-zip.sh [output-dir]      (default output: the repository root)
# Env:    DSCC_RELEASE_TAG  tag being released (for example v1.0.0); the plugin version must match it.
#         DSCC_ZIP_FAST=1   skip composer/npm and pack the committed build outputs (local checks only, never a release).
#
# The working tree is never modified: everything happens in a temporary copy.
#   1. copy the sources           2. composer install --no-dev + Strauss (prefixed vendor libraries)
#   3. npm ci + bundle assets     4. copy what .distignore allows into ds-cookie-consent/
#   5. verify the package         6. zip, re-extract and verify the zip itself
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
OUT=${1:-$ROOT}
NAME=ds-cookie-consent
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT INT TERM

for tool in rsync zip unzip php; do
  command -v "$tool" >/dev/null 2>&1 || { echo "build-zip: $tool is required" >&2; exit 1; }
done

STAGE=$WORK/stage
PKG=$WORK/$NAME
mkdir -p "$STAGE" "$PKG" "$OUT"
OUT=$(cd "$OUT" && pwd)

echo "==> copying sources"
rsync -a \
  --exclude=.git --exclude=node_modules --exclude=vendor --exclude='*.zip' --exclude=.DS_Store \
  --exclude=test-results --exclude=playwright-report \
  "$ROOT"/ "$STAGE"/

if [ "${DSCC_ZIP_FAST:-0}" = "1" ]; then
  echo "==> FAST mode: using the committed vendor-prefixed and assets output (not for releases)"
else
  command -v composer >/dev/null 2>&1 || { echo "build-zip: composer is required" >&2; exit 1; }
  command -v npm >/dev/null 2>&1 || { echo "build-zip: npm is required" >&2; exit 1; }
  echo "==> composer install --no-dev and namespace prefixing"
  (cd "$STAGE" && composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts && composer run-script prefix-namespaces)
  echo "==> bundling assets"
  (cd "$STAGE" && npm ci --ignore-scripts --no-audit --no-fund && npm run build)
fi

echo "==> assembling $NAME/"
rsync -a --exclude-from="$ROOT/.distignore" --exclude=.DS_Store "$STAGE"/ "$PKG"/

php "$ROOT/tools/release/verify-package.php" "$PKG" "${DSCC_RELEASE_TAG:-}"

echo "==> zipping"
rm -f "$OUT/$NAME.zip"
(cd "$WORK" && zip -q -X -r "$OUT/$NAME.zip" "$NAME")

echo "==> verifying the zip"
CHECK=$WORK/check
mkdir -p "$CHECK"
unzip -q "$OUT/$NAME.zip" -d "$CHECK"
[ "$(ls "$CHECK")" = "$NAME" ] || { echo "build-zip: the zip must contain exactly one top folder named $NAME" >&2; exit 1; }
php "$ROOT/tools/release/verify-package.php" "$CHECK/$NAME" "${DSCC_RELEASE_TAG:-}"

echo "built $OUT/$NAME.zip ($(wc -c <"$OUT/$NAME.zip" | tr -d ' ') bytes)"
