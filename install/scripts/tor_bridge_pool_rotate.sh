#!/usr/bin/env bash
# Циклически сдвигает snowflake-мосты в пуле (первый в конец) и пересобирает torrc.
# Запуск: sudo bash install/scripts/tor_bridge_pool_rotate.sh
set -euo pipefail

if [[ "${EUID:-}" -ne 0 ]]; then
  echo "Запустите с sudo." >&2
  exit 1
fi

POOL="${RBT_TOR_POOL:-/etc/tor/rbt-bridge-pool.txt}"

if [[ ! -f "$POOL" ]]; then
  echo "Нет $POOL" >&2
  exit 1
fi

mapfile -t lines < <(grep -v '^[[:space:]]*#' "$POOL" | sed '/^[[:space:]]*$/d' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')
bridges=()
for ln in "${lines[@]}"; do
  [[ "$ln" =~ ^Bridge[[:space:]]+snowflake ]] && bridges+=("$ln")
done

if ((${#bridges[@]} < 2)); then
  echo "Ротация бессмысленна: в пуле меньше двух мостов snowflake — добавьте строки в $POOL" >&2
  exit 1
fi

rot=("${bridges[@]:1}" "${bridges[0]}")
{
  echo "# Пул мостов RBT (строки Bridge snowflake; ротация tor_bridge_pool_rotate.sh / tor_bridge_monitor.sh)"
  printf '%s\n' "${rot[@]}"
} >"${POOL}.new"
mv "${POOL}.new" "$POOL"

echo "Пул сдвинут: первый мост в конец (${#rot[@]} строк)."

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
bash "$SCRIPT_DIR/tor_bridge_pool_apply.sh"

TOR_UNIT="tor@default.service"
if systemctl cat "$TOR_UNIT" &>/dev/null; then
  systemctl restart "$TOR_UNIT" && echo "Перезапущено: $TOR_UNIT"
elif systemctl cat tor.service &>/dev/null; then
  systemctl restart tor.service && echo "Перезапущено: tor.service"
else
  echo "Перезапустите tor вручную." >&2
  exit 1
fi

exit 0
