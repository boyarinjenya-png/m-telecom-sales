#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"

DEBUG_APK="android/app/build/outputs/apk/debug/app-debug.apk"
RELEASE_APK="android/app/build/outputs/apk/release/app-release-unsigned.apk"
# Prefer unsigned name; signed release if present
if [[ -f android/app/build/outputs/apk/release/app-release.apk ]]; then
  RELEASE_APK="android/app/build/outputs/apk/release/app-release.apk"
fi

if [[ ! -f "$DEBUG_APK" ]]; then
  echo "Missing debug APK: $DEBUG_APK"
  exit 1
fi
if [[ ! -f "$RELEASE_APK" ]]; then
  echo "Missing release APK: $RELEASE_APK"
  exit 1
fi

AAPT=$(ls /usr/local/lib/android/sdk/build-tools/*/aapt 2>/dev/null | sort | tail -1 || true)
if [[ -z "${AAPT}" ]]; then
  AAPT=$(ls "$ANDROID_HOME"/build-tools/*/aapt 2>/dev/null | sort | tail -1 || true)
fi
if [[ -z "${AAPT}" ]]; then
  AAPT=$(command -v aapt || true)
fi
if [[ -z "${AAPT}" ]]; then
  echo "aapt not found"
  exit 1
fi

dbg="$("$AAPT" dump badging "$DEBUG_APK")"
rel="$("$AAPT" dump badging "$RELEASE_APK")"

echo "$dbg" | grep -q "name='kz.mtelecom.sales.debug'" || {
  echo "Debug package name mismatch"; echo "$dbg" | head -1; exit 1;
}
echo "$rel" | grep -q "name='kz.mtelecom.sales'" || {
  echo "Release package name mismatch"; echo "$rel" | head -1; exit 1;
}
# Release must NOT be .debug
echo "$rel" | grep -q "name='kz.mtelecom.sales.debug'" && {
  echo "Release APK incorrectly has .debug suffix"; exit 1;
}

vc_dbg=$(echo "$dbg" | sed -n "s/.*versionCode='\([0-9]*\)'.*/\1/p" | head -1)
vc_rel=$(echo "$rel" | sed -n "s/.*versionCode='\([0-9]*\)'.*/\1/p" | head -1)
if [[ -z "$vc_dbg" || -z "$vc_rel" ]]; then
  echo "Could not read versionCode"
  exit 1
fi
if [[ "$vc_dbg" -lt 1 || "$vc_rel" -lt 1 ]]; then
  echo "versionCode must be >= 1 (got debug=$vc_dbg release=$vc_rel)"
  exit 1
fi

echo "Android verify OK"
echo "  debug:  kz.mtelecom.sales.debug versionCode=$vc_dbg size=$(wc -c < "$DEBUG_APK") bytes"
echo "  release: kz.mtelecom.sales versionCode=$vc_rel size=$(wc -c < "$RELEASE_APK") bytes"
