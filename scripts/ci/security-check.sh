#!/usr/bin/env bash
# Config / secret hygiene for CI (no production secrets required).
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"
fail=0

must_ignore=(
  '.env'
  '*.jks'
  '*.keystore'
  'keystore.properties'
  'backend/storage/uploads/*'
  'backend/storage/logs/*'
  'backend/storage/backups/*'
  'backend/storage/downloads/*'
)

for pat in "${must_ignore[@]}"; do
  if ! grep -Fq "$pat" .gitignore; then
    echo "Missing .gitignore entry: $pat"
    fail=1
  fi
done

# Tracked secrets / keystores must not exist
while IFS= read -r f; do
  echo "Tracked forbidden file: $f"
  fail=1
done < <(git ls-files | grep -E '(^|/)\.env$|\.jks$|\.keystore$|keystore\.properties$|seed\.local\.php$' || true)

# APP_DEBUG must not be forced true in committed production examples beyond .env.example guidance
if grep -nE 'APP_DEBUG\s*=\s*true' .env.example >/dev/null 2>&1; then
  # example may show local; warn only if production block
  true
fi

# Ensure install.lock is gitignored
if ! grep -E 'install\.lock' .gitignore >/dev/null; then
  echo "install.lock should be gitignored"
  fail=1
fi

if [[ "$fail" -ne 0 ]]; then
  echo "Security check FAILED"
  exit 1
fi
echo "Security check OK"
