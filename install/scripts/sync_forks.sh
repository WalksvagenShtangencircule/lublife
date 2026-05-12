#!/usr/bin/env bash
set -euo pipefail

# Массовая синхронизация форков через cherry-pick коммита из SmartAccess.
#
# Пример:
#   ./install/scripts/sync_forks.sh 29a4a5d41
#
# Переменные окружения (опционально):
#   BASE_DIR=/tmp/fork-sync
#   UPSTREAM_REPO=git@github.com:WalksvagenShtangencircule/SmartAccess.git
#   UPSTREAM_REMOTE_NAME=smartaccess
#   TARGET_BRANCH=main

BASE_DIR="${BASE_DIR:-/tmp/fork-sync}"
UPSTREAM_REPO="${UPSTREAM_REPO:-git@github.com:WalksvagenShtangencircule/SmartAccess.git}"
UPSTREAM_REMOTE_NAME="${UPSTREAM_REMOTE_NAME:-smartaccess}"
TARGET_BRANCH="${TARGET_BRANCH:-main}"

COMMIT_SHA="${1:-}"
if [[ -z "${COMMIT_SHA}" ]]; then
  echo "Usage: $0 <commit-sha>"
  exit 2
fi

FORKS=(
  komfortkluch
  citihome
  domosky
  lublife
  dom360
)

# Временная identity для cherry-pick (без изменения git config).
export GIT_AUTHOR_NAME="${GIT_AUTHOR_NAME:-WalksvagenShtangencircule}"
export GIT_AUTHOR_EMAIL="${GIT_AUTHOR_EMAIL:-WalksvagenShtangencircule@users.noreply.github.com}"
export GIT_COMMITTER_NAME="${GIT_COMMITTER_NAME:-WalksvagenShtangencircule}"
export GIT_COMMITTER_EMAIL="${GIT_COMMITTER_EMAIL:-WalksvagenShtangencircule@users.noreply.github.com}"

mkdir -p "${BASE_DIR}"

echo "== Fork sync started =="
echo "Commit: ${COMMIT_SHA}"
echo "Branch: ${TARGET_BRANCH}"
echo

for repo in "${FORKS[@]}"; do
  echo "=== ${repo} ==="
  repo_dir="${BASE_DIR}/${repo}"
  origin_url="git@github.com:WalksvagenShtangencircule/${repo}.git"

  if [[ -d "${repo_dir}/.git" ]]; then
    git -C "${repo_dir}" fetch origin
  else
    git clone "${origin_url}" "${repo_dir}"
  fi

  if git -C "${repo_dir}" remote get-url "${UPSTREAM_REMOTE_NAME}" >/dev/null 2>&1; then
    git -C "${repo_dir}" remote set-url "${UPSTREAM_REMOTE_NAME}" "${UPSTREAM_REPO}"
  else
    git -C "${repo_dir}" remote add "${UPSTREAM_REMOTE_NAME}" "${UPSTREAM_REPO}"
  fi

  git -C "${repo_dir}" fetch "${UPSTREAM_REMOTE_NAME}" "${TARGET_BRANCH}"
  git -C "${repo_dir}" checkout "${TARGET_BRANCH}"
  git -C "${repo_dir}" pull --ff-only origin "${TARGET_BRANCH}"

  if git -C "${repo_dir}" merge-base --is-ancestor "${COMMIT_SHA}" HEAD; then
    echo "Already contains ${COMMIT_SHA}"
  else
    git -C "${repo_dir}" cherry-pick "${COMMIT_SHA}"
    git -C "${repo_dir}" push origin "${TARGET_BRANCH}"
    echo "Cherry-pick + push completed"
  fi

  echo "HEAD: $(git -C "${repo_dir}" log --oneline -n 1)"
  echo
done

echo "== Fork sync completed =="
