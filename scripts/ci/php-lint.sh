#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"
fail=0
while IFS= read -r -d '' f; do
  if ! php -l "$f" >/dev/null; then
    echo "SYNTAX FAIL: $f"
    fail=1
  fi
done < <(find backend database tests index.php install.php -type f -name '*.php' -print0 2>/dev/null)
if [[ "$fail" -ne 0 ]]; then
  exit 1
fi
echo "PHP syntax OK"
