#!/usr/bin/env bash
# Собирает /etc/tor/torrc.d/50-rbt-snowflake.conf из пула строк Bridge (только snowflake).
# Запуск: sudo bash install/scripts/tor_bridge_pool_apply.sh
set -euo pipefail

if [[ "${EUID:-}" -ne 0 ]]; then
  echo "Запустите с sudo." >&2
  exit 1
fi

POOL="${RBT_TOR_POOL:-/etc/tor/rbt-bridge-pool.txt}"
SNIP="${RBT_TOR_SNIP:-/etc/tor/torrc.d/50-rbt-snowflake.conf}"
SF_BIN="${RBT_SNOWFLAKE_BIN:-/usr/bin/snowflake-client}"

if [[ ! -f "$POOL" ]]; then
  echo "Нет файла пула: $POOL — создайте из install/scripts/tor_bridge_pool.example.txt" >&2
  exit 1
fi

mapfile -t lines < <(grep -v '^[[:space:]]*#' "$POOL" | sed '/^[[:space:]]*$/d' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
bridges=()
for ln in "${lines[@]}"; do
  if [[ "$ln" =~ ^Bridge[[:space:]]+snowflake ]]; then
    bridges+=("$ln")
  elif [[ "$ln" =~ ^Bridge[[:space:]] ]]; then
    echo "Пропуск не-snowflake строки (нужен отдельный torrc/obfs4): $ln" >&2
  fi
done

if ((${#bridges[@]} == 0)); then
  echo "В $POOL нет строк «Bridge snowflake …»" >&2
  exit 1
fi

mkdir -p "$(dirname "$SNIP")"
umask 022
{
  echo "# Собрано tor_bridge_pool_apply.sh из $POOL — не править вручную"
  echo "UseBridges 1"
  echo "ClientTransportPlugin snowflake exec $SF_BIN"
  printf '%s\n' "${bridges[@]}"
} >"$SNIP"

echo "Записано: $SNIP (${#bridges[@]} мостов)"
