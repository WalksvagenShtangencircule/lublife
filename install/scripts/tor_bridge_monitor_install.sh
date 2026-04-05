#!/usr/bin/env bash
# Устанавливает пул мостов, timer systemd для проверки и ротации.
# Запуск: sudo bash install/scripts/tor_bridge_monitor_install.sh
set -euo pipefail

if [[ "${EUID:-}" -ne 0 ]]; then
  echo "Запустите с sudo." >&2
  exit 1
fi

RBT="${RBT_ROOT:-/opt/rbt}"
POOL="/etc/tor/rbt-bridge-pool.txt"
EX="$RBT/install/scripts/tor_bridge_pool.example.txt"
SNIP="/etc/tor/torrc.d/50-rbt-snowflake.conf"

mkdir -p /var/lib/rbt-tor
touch /var/lib/rbt-tor/.keep

if [[ ! -f "$POOL" ]]; then
  if [[ -f "$SNIP" ]] && grep -q '^Bridge snowflake' "$SNIP" 2>/dev/null; then
    {
      echo "# Перенесено из $SNIP (tor_bridge_monitor_install.sh)"
      grep '^Bridge snowflake' "$SNIP"
    } >"$POOL"
    echo "Создан $POOL из текущего $SNIP"
  elif [[ -f "$EX" ]]; then
    cp -a "$EX" "$POOL"
    echo "Создан $POOL из примера — добавьте второй мост для ротации."
  else
    echo "Нет $EX" >&2
    exit 1
  fi
fi

bash "$RBT/install/scripts/tor_bridge_pool_apply.sh"

SRC_SVC="$RBT/install/systemd/tor-bridge-monitor.service.example"
SRC_TMR="$RBT/install/systemd/tor-bridge-monitor.timer.example"
install -m 644 "$SRC_SVC" /etc/systemd/system/tor-bridge-monitor.service
install -m 644 "$SRC_TMR" /etc/systemd/system/tor-bridge-monitor.timer
systemctl daemon-reload
systemctl enable --now tor-bridge-monitor.timer
systemctl start tor-bridge-monitor.service || true

echo "Активен: systemctl status tor-bridge-monitor.timer"
echo "Логи: journalctl -u tor-bridge-monitor.service -f"
