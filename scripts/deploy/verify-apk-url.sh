#!/usr/bin/env bash
set -euo pipefail
url="${1:?url}"
tmp="$(mktemp)"
code=$(curl -sS -L -o "$tmp" -w '%{http_code}' --max-time 120 "$url" || true)
ct=$(file -b --mime-type "$tmp" || true)
size=$(wc -c < "$tmp")
echo "APK URL HTTP $code size=$size mime=$ct"
if [[ "$code" != "200" ]]; then
  rm -f "$tmp"
  exit 1
fi
# Android APK is a ZIP
if ! file "$tmp" | grep -qiE 'Zip|Android|Java archive'; then
  echo "Downloaded body is not an APK"
  head -c 200 "$tmp" || true
  rm -f "$tmp"
  exit 1
fi
echo "apk_url_sha256=$(sha256sum "$tmp" | awk '{print $1}')"
rm -f "$tmp"
