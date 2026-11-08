#!/usr/bin/env bash
# Builds the deep-scan Docker image and runs it once with --help, which proves the image starts and the entry point
# and its lib/ files are in place. CI runs the same two commands.
set -euo pipefail
cd "$(dirname "$0")/../.."
TAG=dscc-deep-scan:check
docker build -t "$TAG" tools/deep-scan
docker run --rm "$TAG" --help
docker rmi "$TAG" >/dev/null
echo "deep-scan image: ok"
