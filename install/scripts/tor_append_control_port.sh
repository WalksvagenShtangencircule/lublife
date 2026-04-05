#!/usr/bin/env bash
# Идемпотентно добавляет ControlPort и cookie-аутентификацию в torrc (управление цепочками / NEWNYM).
# Запуск: sudo bash install/scripts/tor_append_control_port.sh
set -euo pipefail
TORRC="${TORRC:-/etc/tor/torrc}"
if [[ ! -f "$TORRC" ]]; then
  echo "Нет файла $TORRC — установите пакет tor." >&2
  exit 1
fi
append_if_missing () {
  local line="$1"
  if grep -qF -- "$line" "$TORRC" 2>/dev/null; then
    echo "уже есть: $line"
  else
    echo "$line" >> "$TORRC"
    echo "добавлено: $line"
  fi
}
append_if_missing "ControlPort 127.0.0.1:9051"
append_if_missing "CookieAuthentication 1"
echo "Перезапуск: sudo systemctl restart tor@default  (или tor.service)"
exit 0
