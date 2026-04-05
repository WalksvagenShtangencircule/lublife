#!/usr/bin/env bash
# Периодическая проверка Tor SOCKS; при серии сбоев — ротация пула мостов и перезапуск Tor.
# Переменные: RBT_TOR_POOL, RBT_TOR_STATE_DIR (/var/lib/rbt-tor), RBT_TOR_HEALTHCHECK_MAX_FAILS (2),
# RBT_TOR_HEALTHCHECK_URL, RBT_TOR_HEALTHCHECK_TIMEOUT, RBT_TOR_SOCKS
# Запуск: sudo bash install/scripts/tor_bridge_monitor.sh
set -euo pipefail

if [[ "${EUID:-}" -ne 0 ]]; then
  echo "Запустите с sudo." >&2
  exit 1
fi

STATE_DIR="${RBT_TOR_STATE_DIR:-/var/lib/rbt-tor}"
POOL="${RBT_TOR_POOL:-/etc/tor/rbt-bridge-pool.txt}"
MAX_FAILS="${RBT_TOR_HEALTHCHECK_MAX_FAILS:-2}"
FAIL_FILE="$STATE_DIR/healthcheck_fail_count"

mkdir -p "$STATE_DIR"
umask 022

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export RBT_TOR_POOL

if [[ ! -f "$POOL" ]]; then
  logger -t rbt-tor-monitor "нет пула $POOL — пропуск"
  exit 0
fi

if bash "$SCRIPT_DIR/tor_bridge_healthcheck.sh"; then
  echo 0 >"$FAIL_FILE"
  logger -t rbt-tor-monitor "healthcheck OK"
  exit 0
fi

n=0
if [[ -f "$FAIL_FILE" ]]; then
  n=$(tr -dc '0-9' <"$FAIL_FILE" || echo 0)
  [[ -z "$n" ]] && n=0
fi
n=$((n + 1))
echo "$n" >"$FAIL_FILE"
logger -t rbt-tor-monitor "healthcheck FAIL ($n/$MAX_FAILS)"

if (( n < MAX_FAILS )); then
  exit 0
fi

echo 0 >"$FAIL_FILE"

if ! bash "$SCRIPT_DIR/tor_bridge_pool_rotate.sh"; then
  logger -t rbt-tor-monitor "ротация не выполнена (нужно ≥2 моста в пуле?)"
  exit 0
fi

logger -t rbt-tor-monitor "выполнена ротация мостов и перезапуск Tor"
exit 0
