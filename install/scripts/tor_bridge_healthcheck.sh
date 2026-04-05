#!/usr/bin/env bash
# Проверка: HTTPS через Tor SOCKS (без ответа — код выхода 1).
set -euo pipefail

SOCKS="${RBT_TOR_SOCKS:-127.0.0.1:9050}"
URL="${RBT_TOR_HEALTHCHECK_URL:-https://check.torproject.org/api/ip}"
TMO="${RBT_TOR_HEALTHCHECK_TIMEOUT:-120}"

if curl -sS -f -o /dev/null --connect-timeout 30 --max-time "$TMO" \
  --socks5-hostname "$SOCKS" "$URL" 2>/dev/null; then
  exit 0
fi
exit 1
