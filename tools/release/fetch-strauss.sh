#!/usr/bin/env bash
# Puts the pinned Strauss release at bin/strauss.phar and refuses anything whose SHA-256 differs from the pinned value.
# An existing file is checked too, so a tampered local copy is never run. Bump VERSION and SHA256 together (the hash is
# the digest GitHub publishes for the release asset).
set -euo pipefail
cd "$(dirname "$0")/../.."

VERSION=0.31.1
SHA256=17dc2baf27b9a3f52834e3db729c8365d8915a80e37a087d253cf705387aa887
TARGET=bin/strauss.phar

sha() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

if [ -f "$TARGET" ] && [ "$(sha "$TARGET")" = "$SHA256" ]; then
  exit 0
fi

mkdir -p bin
TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT
curl -sSfL -o "$TMP" "https://github.com/BrianHenryIE/strauss/releases/download/${VERSION}/strauss.phar"
if [ "$(sha "$TMP")" != "$SHA256" ]; then
  echo "fetch-strauss: checksum mismatch for strauss ${VERSION}; refusing to use it" >&2
  exit 1
fi
mv "$TMP" "$TARGET"
chmod 644 "$TARGET"
