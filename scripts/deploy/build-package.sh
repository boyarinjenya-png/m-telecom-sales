#!/usr/bin/env bash
# Build allowlisted deployment tree under artifacts/deploy/package
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"
out="artifacts/deploy/package"
rm -rf artifacts/deploy
mkdir -p "$out"

copy_file() {
  local src="$1"
  local dest="$out/$src"
  mkdir -p "$(dirname "$dest")"
  cp -a "$src" "$dest"
}

copy_tree_filtered() {
  local dir="$1"
  shift
  find "$dir" -type f "$@" -print0 | while IFS= read -r -d '' f; do
    copy_file "$f"
  done
}

# Root entrypoints / Apache config for /sales
for f in index.php install.php .htaccess .user.ini; do
  [[ -f "$f" ]] && copy_file "$f"
done

# Admin SPA
copy_tree_filtered admin \( -name '*.html' -o -name '*.css' -o -name '*.js' -o -name '*.svg' -o -name '*.png' -o -name '*.webp' -o -name '.htaccess' \)

# Backend public + src + bin + config templates (never .env / seed.local / lock)
copy_tree_filtered backend/public \( -name '*.php' -o -name '.htaccess' \)
# Explicitly exclude install.lock if present locally
rm -f "$out/backend/public/install.lock"

copy_tree_filtered backend/src \( -name '*.php' -o -name '.htaccess' \)
copy_tree_filtered backend/bin \( -name '*.php' -o -name '.htaccess' \)
# Never ship seed-local to be executed, but file may exist in bin — keep code but deploy must not run it
copy_tree_filtered backend/config \( -name '.htaccess' -o -name '.gitkeep' -o -name 'seed.local.example.php' \)

# Storage placeholders only (never uploads/logs/backups/downloads binaries)
for d in backend/storage backend/storage/logs backend/storage/uploads backend/storage/cache backend/storage/backups backend/storage/downloads; do
  mkdir -p "$out/$d"
  [[ -f "$d/.htaccess" ]] && cp -a "$d/.htaccess" "$out/$d/.htaccess"
  [[ -f "$d/.gitkeep" ]] && cp -a "$d/.gitkeep" "$out/$d/.gitkeep"
done
[[ -f backend/storage/.htaccess ]] && cp -a backend/storage/.htaccess "$out/backend/storage/.htaccess"

# Database schema (reference; migrations run via Migrator PHP)
copy_tree_filtered database \( -name '*.sql' -o -name '.htaccess' \)

# Mandatory files check
required=(
  index.php
  backend/public/index.php
  backend/src/bootstrap.php
  backend/src/App.php
  backend/src/Core/Migrator.php
  admin/index.html
  admin/assets/js/app.js
  admin/assets/css/app.css
)
for r in "${required[@]}"; do
  if [[ ! -f "$out/$r" ]]; then
    echo "Missing required package file: $r"
    exit 1
  fi
done

# Refuse to package secrets
if find "$out" -type f \( -name '.env' -o -name 'install.lock' -o -name 'seed.local.php' -o -name '*.jks' -o -name '*.keystore' \) | grep -q .; then
  echo "Refusing to package secrets or install.lock"
  find "$out" -type f \( -name '.env' -o -name 'install.lock' -o -name 'seed.local.php' -o -name '*.jks' -o -name '*.keystore' \)
  exit 1
fi

# Manifest
( cd "$out" && find . -type f | sort > ../manifest.txt )
echo "Deployment package ready: $out ($(wc -l < artifacts/deploy/manifest.txt) files)"
