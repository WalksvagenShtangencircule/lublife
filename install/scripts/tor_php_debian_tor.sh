#!/usr/bin/env bash
# Даёт пользователю php-fpm (www-data) доступ к ControlSocket Tor и cookie NEWNYM.
# На Debian/Ubuntu: сокет /run/tor/control и /run/tor/control.authcookie принадлежат группе debian-tor.
# Запуск: sudo bash install/scripts/tor_php_debian_tor.sh
set -euo pipefail

if [[ "${EUID:-}" -ne 0 ]]; then
  echo "Запустите с sudo." >&2
  exit 1
fi

if ! getent group debian-tor &>/dev/null; then
  echo "Группа debian-tor не найдена — установите пакет tor." >&2
  exit 1
fi

PHP_USER="${PHP_USER:-www-data}"
if ! id "$PHP_USER" &>/dev/null; then
  echo "Пользователь $PHP_USER не найден — задайте PHP_USER=…" >&2
  exit 1
fi

usermod -a -G debian-tor "$PHP_USER"
echo "Добавлено: $PHP_USER в группу debian-tor (нужен новый сеанс / перезапуск php-fpm)."

mapfile -t UNITS < <(systemctl list-units --type=service --state=running --no-legend 2>/dev/null | awk '/php.*-fpm/ {print $1}' || true)
if ((${#UNITS[@]})); then
  for u in "${UNITS[@]}"; do
    systemctl restart "$u" && echo "Перезапущено: $u"
  done
else
  echo "Активный php-fpm не найден — перезапустите вручную, например: sudo systemctl restart php8.3-fpm"
fi

exit 0
