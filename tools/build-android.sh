#!/usr/bin/env bash
# Builds the release APK teachers and students install (docs/HOSTING.md §6.2,
# manual "สร้างแอป Android"). Checks the two private files first:
#
#   app/android/app/google-services.json   Firebase config: push notifications
#   app/android/key.properties + keystore  the project's signing key
#
# Either may be missing (the APK still works: no push / debug signature),
# but the script says so, because an APK signed with the debug key cannot be
# installed over one signed with the project key.
#
#   tools/build-android.sh                       # https://teacherhelper.phuwish.com
#   tools/build-android.sh https://example.org   # another server
#   GOOGLE_SIGNIN_CLIENT_ID=<web client id> tools/build-android.sh
set -euo pipefail
API_BASE_URL="${1:-https://teacherhelper.phuwish.com}"
API_BASE_URL="${API_BASE_URL%/}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP="$ROOT/app"
case "$API_BASE_URL" in https://*) ;; *) echo "API_BASE_URL must start with https:// (got $API_BASE_URL)" >&2; exit 1 ;; esac

if [ -f "$APP/android/app/google-services.json" ]; then
  pkg=$(python3 -c "import json,sys; d=json.load(open(sys.argv[1])); print(','.join(c['client_info']['android_client_info']['package_name'] for c in d['client']))" "$APP/android/app/google-services.json")
  case ",$pkg," in *,com.eduvision.app,*) echo "push: on (google-services.json for com.eduvision.app)" ;;
    *) echo "google-services.json has no Android app com.eduvision.app (found: $pkg)" >&2; exit 1 ;; esac
else
  echo "push: OFF (no app/android/app/google-services.json)"
fi

if [ -f "$APP/android/key.properties" ]; then
  store=$(grep '^storeFile=' "$APP/android/key.properties" | cut -d= -f2-)
  [ -f "$APP/android/$store" ] || [ -f "$store" ] || { echo "key.properties points at $store, which does not exist" >&2; exit 1; }
  echo "signing: project key ($store)"
else
  echo "signing: DEBUG key (no app/android/key.properties) - fine for testing, not for handing out"
fi

DEFINES=(--dart-define=API_BASE_URL="$API_BASE_URL")
if [ -n "${GOOGLE_SIGNIN_CLIENT_ID:-}" ]; then
  DEFINES+=(--dart-define=GOOGLE_SIGNIN_CLIENT_ID="$GOOGLE_SIGNIN_CLIENT_ID")
  echo "google sign-in: on"
else
  echo "google sign-in: off (set GOOGLE_SIGNIN_CLIENT_ID to the Web client id to turn it on)"
fi

cd "$APP"
flutter build apk --release --split-per-abi "${DEFINES[@]}"
APK="$APP/build/app/outputs/flutter-apk/app-arm64-v8a-release.apk"
echo
echo "APK: $APK ($(du -h "$APK" | cut -f1))"
if command -v keytool >/dev/null 2>&1 && command -v unzip >/dev/null 2>&1; then
  tmp=$(mktemp -d); unzip -qo "$APK" 'META-INF/*' -d "$tmp" 2>/dev/null || true
  cert=$(ls "$tmp"/META-INF/*.RSA "$tmp"/META-INF/*.EC 2>/dev/null | head -1 || true)
  if [ -n "$cert" ]; then keytool -printcert -file "$cert" | grep -E 'SHA1:|SHA256:' | sed 's/^\s*/signed with /'; else
    apksigner=$(ls "${ANDROID_HOME:-$HOME/Library/Android/sdk}"/build-tools/*/apksigner 2>/dev/null | tail -1)
    [ -n "$apksigner" ] && "$apksigner" verify --print-certs "$APK" | grep -E 'SHA-1|SHA-256' | head -2
  fi
  rm -rf "$tmp"
fi
