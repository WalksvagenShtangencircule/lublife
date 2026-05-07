#!/bin/sh
# Настраивает драйвер merge для атрибута merge=ours (.gitattributes).
# Запустите один раз после git clone на машине деплоя или после получения обновлений .gitattributes.

set -e
cd "$(dirname "$0")/.."
git config merge.ours.driver true
git config merge.ours.name "deployment overlay (keep our version)"
echo "OK: merge.ours включён для этого репозитория ($(pwd))"
