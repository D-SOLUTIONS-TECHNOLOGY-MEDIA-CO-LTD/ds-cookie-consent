#!/usr/bin/env bash
# Lints .github/workflows with the pinned actionlint image. CI runs the same image by the same digest.
set -euo pipefail
cd "$(dirname "$0")/../.."
docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667 -color
echo "workflows: ok"
