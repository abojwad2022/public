#!/usr/bin/env bash
# shot.sh — capture a live screenshot of the local Yazan site (yazan.local) headlessly.
# Claude uses this to SEE pages directly; the user never needs to paste a screenshot.
#
# Usage:
#   bash shot.sh <path-or-url> [desktop|mobile|full] [out.png]
# Examples:
#   bash shot.sh /store/                 # desktop 1440-wide viewport shot
#   bash shot.sh /store/ mobile          # 390-wide mobile viewport
#   bash shot.sh / full                  # full-page (tall) desktop capture
#   bash shot.sh product/the-adeni-ember desktop /c/tmp/ring.png
#
# Notes:
# - Maps yazan.local -> 127.0.0.1 and ignores the local TLS cert, so no browser tool needed.
# - Default output goes to this session's scratchpad; the resolved PNG path is printed last.
# - Local reassigns ports on restart but this hits nginx on :80, so it is not port-sensitive.

set -euo pipefail

CHROME="/c/Program Files/Google/Chrome/Application/chrome.exe"
[ -x "$CHROME" ] || CHROME="/c/Program Files (x86)/Microsoft/Edge/Application/msedge.exe"

ARG="${1:-/}"
MODE="${2:-desktop}"
OUT="${3:-}"

# Build the URL: accept a full URL, a leading-slash path, or a bare slug.
case "$ARG" in
  http://*|https://*) URL="$ARG" ;;
  /*)                 URL="http://yazan.local${ARG}" ;;
  *)                  URL="http://yazan.local/${ARG}" ;;
esac

case "$MODE" in
  mobile) SIZE="390,844"  ; FULL="" ;;
  full)   SIZE="1440,900" ; FULL="--headless=new" ; FULLFLAG="--screenshot" ; FULLPAGE="1" ;;
  *)      SIZE="1440,2200"; FULL="" ;;
esac

if [ -z "$OUT" ]; then
  SCRATCH="${CLAUDE_SCRATCHPAD:-/c/Users/Nebras/AppData/Local/Temp/claude/shots}"
  mkdir -p "$SCRATCH"
  SLUG=$(echo "$ARG" | sed 's#https\?://##; s#[^a-zA-Z0-9]#_#g; s#^_*##; s#_*$##')
  [ -z "$SLUG" ] && SLUG="home"
  OUT="${SCRATCH}/${SLUG}_${MODE}.png"
fi

# Convert the Git-Bash path to a Windows path Chrome understands.
WINOUT=$(echo "$OUT" | sed -E 's#^/c/#C:/#; s#^/([a-z])/#\U\1:/#')

"$CHROME" \
  --headless=new --disable-gpu --hide-scrollbars \
  --window-size="$SIZE" \
  ${FULLPAGE:+--screenshot="$WINOUT"} \
  ${FULLPAGE:+--} \
  --host-resolver-rules="MAP yazan.local 127.0.0.1" \
  --ignore-certificate-errors \
  --virtual-time-budget=4000 \
  --screenshot="$WINOUT" \
  "$URL" >/dev/null 2>&1 || true

if [ -s "$OUT" ]; then
  echo "OK  $URL  ($MODE)"
  echo "$OUT"
else
  echo "FAILED to capture $URL" >&2
  exit 1
fi
