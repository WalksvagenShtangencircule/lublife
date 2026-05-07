#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

REF="${1:-main}"
SKIP_DB_BACKUP="${SKIP_DB_BACKUP:-0}"
SKIP_COMPOSER="${SKIP_COMPOSER:-0}"
BACKUP_ROOT="${BACKUP_ROOT:-/opt/backups/smartaccess}"
TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
BACKUP_DIR="${BACKUP_ROOT}/${TIMESTAMP}"

PRESERVE_PATHS=(
  "client/config/config.json"
  "server/config/config.json"
  "client/modules/custom"
  "server/mobile/ext/custom"
  "server/mobile/address/custom"
  "static/portal/keys"
  "static/custom"
  "asterisk/custom"
  "install/kamailio/kamailio-local.cfg"
  "install/kamailio/kamctlrc"
)

echo "[safe-update] repo: ${REPO_DIR}"
echo "[safe-update] ref: ${REF}"
echo "[safe-update] backup dir: ${BACKUP_DIR}"

mkdir -p "${BACKUP_DIR}/files"

cd "${REPO_DIR}"

for path in "${PRESERVE_PATHS[@]}"; do
  if [ -e "${path}" ]; then
    mkdir -p "${BACKUP_DIR}/files/$(dirname "${path}")"
    cp -a "${path}" "${BACKUP_DIR}/files/${path}"
    echo "[safe-update] preserved: ${path}"
  fi
done

git status --short > "${BACKUP_DIR}/git-status-before.txt"
git rev-parse HEAD > "${BACKUP_DIR}/git-revision-before.txt"

if [ "${SKIP_DB_BACKUP}" != "1" ]; then
  echo "[safe-update] running DB backup via cli.php --backup-db"
  php "${REPO_DIR}/server/cli.php" --backup-db
else
  echo "[safe-update] DB backup skipped (SKIP_DB_BACKUP=1)"
fi

echo "[safe-update] syncing git metadata"
git fetch --tags origin

if git show-ref --verify --quiet "refs/heads/${REF}"; then
  git checkout "${REF}"
elif git show-ref --verify --quiet "refs/remotes/origin/${REF}"; then
  git checkout -B "${REF}" "origin/${REF}"
else
  git checkout --detach "${REF}"
fi

if git show-ref --verify --quiet "refs/remotes/origin/${REF}"; then
  git pull --ff-only origin "${REF}"
fi

if [ "${SKIP_COMPOSER}" != "1" ]; then
  echo "[safe-update] running composer install"
  composer install --working-dir="${REPO_DIR}/server" --no-interaction --prefer-dist
else
  echo "[safe-update] composer step skipped (SKIP_COMPOSER=1)"
fi

echo "[safe-update] restoring preserved files"
cp -a "${BACKUP_DIR}/files/." "${REPO_DIR}/"

echo "[safe-update] running post-update maintenance"
php "${REPO_DIR}/server/cli.php" --init-db
php "${REPO_DIR}/server/cli.php" --init-clickhouse-db
php "${REPO_DIR}/server/cli.php" --clear-cache
php "${REPO_DIR}/server/cli.php" --reindex

git rev-parse HEAD > "${BACKUP_DIR}/git-revision-after.txt"
git status --short > "${BACKUP_DIR}/git-status-after.txt"

cat <<EOF
[safe-update] done
[safe-update] before: $(cat "${BACKUP_DIR}/git-revision-before.txt")
[safe-update] after:  $(cat "${BACKUP_DIR}/git-revision-after.txt")
[safe-update] backup: ${BACKUP_DIR}
EOF
