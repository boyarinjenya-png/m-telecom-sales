#!/usr/bin/env bash
# Build Debug APK with the production-compatible debug.keystore from Actions secrets.
# Restores the runner keystore afterward. Never prints secret values.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"

: "${ANDROID_DEBUG_KEYSTORE_BASE64:?}"
: "${ANDROID_KEY_ALIAS:?}"
: "${ANDROID_KEYSTORE_PASSWORD:?}"
: "${ANDROID_KEY_PASSWORD:?}"
EXPECTED_CERT_SHA256="${EXPECTED_CERT_SHA256:-22B0A65B5BB6BD813F4B664D1CF3EB4F4323FC8894060DC3DA4AC8D7D495AE71}"
VERSION_CODE_OVERRIDE="${VERSION_CODE_OVERRIDE:-2}"

ANDROID_DIR="${HOME}/.android"
mkdir -p "$ANDROID_DIR"
ORIG="${ANDROID_DIR}/debug.keystore"
BAK="${ANDROID_DIR}/debug.keystore.ci-backup.$$"
TMP_KS="$(mktemp)"
cleanup() {
  rm -f "$TMP_KS"
  if [[ -f "$BAK" ]]; then
    mv -f "$BAK" "$ORIG"
  fi
}
trap cleanup EXIT

if [[ -f "$ORIG" ]]; then
  cp -a "$ORIG" "$BAK"
fi

echo "$ANDROID_DEBUG_KEYSTORE_BASE64" | base64 -d > "$TMP_KS"
# Validate fingerprint before replacing runner keystore
fp=$(keytool -list -v -keystore "$TMP_KS" -storepass "$ANDROID_KEYSTORE_PASSWORD" -alias "$ANDROID_KEY_ALIAS" 2>/dev/null \
  | awk -F': ' '/SHA256:/{print $2; exit}' | tr -d ' :' | tr '[:lower:]' '[:upper:]')
exp=$(echo "$EXPECTED_CERT_SHA256" | tr -d ' :' | tr '[:lower:]' '[:upper:]')
if [[ "$fp" != "$exp" ]]; then
  echo "Keystore SHA-256 mismatch. Refusing to build. got=${fp:-empty} expected=$exp"
  exit 1
fi

cp -a "$TMP_KS" "$ORIG"

chmod +x android/gradlew
(
  cd android
  ./gradlew --no-daemon assembleDebug -PversionCodeOverride="$VERSION_CODE_OVERRIDE"
)

APK="android/app/build/outputs/apk/debug/app-debug.apk"
if [[ ! -f "$APK" ]]; then
  echo "APK missing after build"
  exit 1
fi

APKSIGNER=$(ls "${ANDROID_HOME:-/usr/local/lib/android/sdk}"/build-tools/*/apksigner 2>/dev/null | sort | tail -1)
AAPT=$(ls "${ANDROID_HOME:-/usr/local/lib/android/sdk}"/build-tools/*/aapt 2>/dev/null | sort | tail -1)
if [[ -z "$APKSIGNER" || -z "$AAPT" ]]; then
  echo "apksigner or aapt not found"
  exit 1
fi

# assembleDebug on the runner can ignore ~/.android/debug.keystore and sign with a
# freshly generated debug key. Re-sign with the keystore whose fingerprint was checked above.
echo "Re-signing APK with the verified debug keystore"
"$APKSIGNER" sign \
  --ks "$TMP_KS" \
  --ks-key-alias "$ANDROID_KEY_ALIAS" \
  --ks-pass env:ANDROID_KEYSTORE_PASSWORD \
  --key-pass env:ANDROID_KEY_PASSWORD \
  "$APK"

certs=$("$APKSIGNER" verify --print-certs "$APK" 2>&1)
echo "$certs" | grep -qi 'Verified using' || true
got=$(echo "$certs" | awk '/SHA-256 digest:/{print $NF; exit}' | tr -d ' :' | tr '[:lower:]' '[:upper:]')
if [[ "$got" != "$exp" ]]; then
  echo "APK certificate mismatch. got=${got:-empty} expected=$exp"
  exit 1
fi

badging=$("$AAPT" dump badging "$APK")
echo "$badging" | grep -q "name='kz.mtelecom.sales.debug'" || {
  echo "Package name mismatch"; exit 1;
}
vc=$(echo "$badging" | sed -n "s/.*versionCode='\([0-9]*\)'.*/\1/p" | head -1)
if [[ -z "$vc" || "$vc" -le 1 ]]; then
  echo "versionCode must be > 1 for upgrade over production (got ${vc:-empty})"
  exit 1
fi

mkdir -p artifacts/apk
cp -a "$APK" artifacts/apk/m-telecom-sales.apk
apk_sha=$(sha256sum artifacts/apk/m-telecom-sales.apk | awk '{print $1}')
size=$(wc -c < artifacts/apk/m-telecom-sales.apk)
{
  echo "package=kz.mtelecom.sales.debug"
  echo "versionCode=$vc"
  echo "size=$size"
  echo "apk_sha256=$apk_sha"
  echo "cert_sha256=$got"
} | tee artifacts/apk/metadata.txt

echo "Signed debug APK OK"
