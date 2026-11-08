#!/bin/sh
# Parse every plugin PHP file with PHP 7.4 (the minimum supported version).
# Exits non-zero when any file fails, or when no file was checked.
docker run --rm -v "$PWD":/app -w /app php:7.4-cli sh -c '
fail=0
count=0
for f in $(find . -name "*.php" -not -path "./vendor/*" -not -path "./node_modules/*" -not -path "./reference/*" -not -path "./bin/*" -not -path "./test-results/*"); do
  count=$((count + 1))
  if ! out=$(php -l "$f" 2>&1); then
    echo "$out"
    fail=1
  fi
done
[ "$count" -gt 0 ] || { echo "lint-php74: no PHP files found"; exit 1; }
echo "lint-php74: checked $count files"
exit $fail
'
