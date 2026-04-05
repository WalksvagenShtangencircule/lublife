#!/usr/bin/env bash
# Включает мосты Snowflake для Tor (цензура, «застревание» на ~10–15 % bootstrap).
# Создаёт пул /etc/tor/rbt-bridge-pool.txt и собирает torrc (см. tor_bridge_pool_apply.sh).
# Если мост устарел — добавьте строки Bridge: https://bridges.torproject.org/
# Запуск: sudo bash install/scripts/tor_enable_snowflake.sh
set -euo pipefail

if [[ "${EUID:-}" -ne 0 ]]; then
  echo "Запустите с sudo." >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y snowflake-client tor

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
POOL="${RBT_TOR_POOL:-/etc/tor/rbt-bridge-pool.txt}"
EX="$SCRIPT_DIR/tor_bridge_pool.example.txt"

if [[ ! -f "$POOL" ]]; then
  if [[ -f "$EX" ]]; then
    cp -a "$EX" "$POOL"
    echo "Создан $POOL из примера."
  else
    mkdir -p "$(dirname "$POOL")"
    {
      echo "# Пул мостов RBT (snowflake)"
      echo "Bridge snowflake 192.95.36.142:80 2B280B23E1107CE834953B9846990F765555"
    } >"$POOL"
    echo "Создан минимальный $POOL"
  fi
fi

bash "$SCRIPT_DIR/tor_bridge_pool_apply.sh"

TOR_UNIT="tor@default.service"
if systemctl cat "$TOR_UNIT" &>/dev/null; then
  systemctl restart "$TOR_UNIT" && echo "Перезапущено: $TOR_UNIT"
elif systemctl cat tor.service &>/dev/null; then
  systemctl restart tor.service && echo "Перезапущено: tor.service"
else
  echo "Не удалось найти tor@default или tor.service — перезапустите Tor вручную." >&2
fi

echo "Проверка: curl --socks5-hostname 127.0.0.1:9050 https://check.torproject.org/api/ip --max-time 120"
echo "Автопроверка и ротация: sudo bash $SCRIPT_DIR/tor_bridge_monitor_install.sh"

exit 0
