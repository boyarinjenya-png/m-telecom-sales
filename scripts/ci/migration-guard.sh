#!/usr/bin/env bash
# Fail deploy/CI if migration sources contain destructive SQL.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"

targets=(
  backend/src/Core/Migrator.php
  database/schema.sql
  database/seed.sql
  backend/bin/migrate.php
)

pattern='(^|[^A-Za-z_])(DROP[[:space:]]+TABLE|TRUNCATE([[:space:]]+TABLE)?|DROP[[:space:]]+DATABASE|DELETE[[:space:]]+FROM[[:space:]]+clients)([^A-Za-z_]|$)'

found=0
for f in "${targets[@]}"; do
  [[ -f "$f" ]] || continue
  # Ignore SQL comments lines starting with --
  if grep -nEi "$pattern" "$f" | grep -vE '^[[:space:]]*[0-9]+:[[:space:]]*--' >/tmp/mig-guard.out; then
    echo "Destructive SQL pattern in $f:"
    cat /tmp/mig-guard.out
    found=1
  fi
done

# seed-local must never be invoked by deploy scripts
if grep -RniE 'seed-local\.php' scripts/deploy .github/workflows 2>/dev/null | grep -v 'migration-guard' | grep -v '#'; then
  echo "Deploy scripts must not call seed-local.php"
  found=1
fi

if [[ "$found" -ne 0 ]]; then
  echo "Migration guard FAILED"
  exit 1
fi
echo "Migration guard OK (no DROP TABLE / TRUNCATE / destructive client deletes)"
