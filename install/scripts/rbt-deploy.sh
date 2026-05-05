#!/usr/bin/env bash
set -euo pipefail

MODE="${1:-}"
if [[ "$MODE" == "-h" || "$MODE" == "--help" ]]; then
    MODE=""
else
    shift || true
fi

TARGET_DIR="/opt/rbt"
REPO_URL="git@github.com:WalksvagenShtangencircule/SmartAccess.git"
REF_NAME="main"
RUN_DB_INIT="false"
ADMIN_PASSWORD=""
PHP_BIN="php"
COMPOSER_BIN="composer"
NPM_BIN="npm"

CLIENT_LIB_DIR="client/lib"
SERVER_DIR="server"
CLIENT_DIR="client"
SERVER_CONFIG_SAMPLE="server/config/config.sample.json5"
SERVER_CONFIG="server/config/config.json"
CLIENT_CONFIG_SAMPLE_JSON5="client/config/config.sample.json5"
CLIENT_CONFIG_SAMPLE_JSON="client/config/config.example.json"
CLIENT_CONFIG="client/config/config.json"

usage() {
    cat <<'EOF'
Usage:
  rbt-deploy.sh install [options]
  rbt-deploy.sh update  [options]

Modes:
  install   Clone repo if missing, checkout ref, install dependencies.
  update    Fetch latest changes in existing repo, checkout ref, update dependencies.

Options:
  --target <dir>            Project directory (default: /opt/rbt)
  --repo <url>              Git repository URL
  --ref <branch|tag|sha>    Git ref to checkout (default: main)
  --init-db                 Run server DB initialization commands
  --admin-password <value>  Admin password (required with --init-db)
  --php <bin>               PHP binary (default: php)
  --composer <bin>          Composer binary (default: composer)
  --npm <bin>               NPM binary (default: npm)
  -h, --help                Show help

Examples:
  rbt-deploy.sh install --repo git@github.com:WalksvagenShtangencircule/SmartAccess.git --ref main
  rbt-deploy.sh update --target /opt/rbt --ref chore/upstream-safe-docs-sync
  rbt-deploy.sh install --init-db --admin-password 'super-secret-password'
EOF
}

log() {
    printf '[rbt-deploy] %s\n' "$*"
}

die() {
    printf '[rbt-deploy] ERROR: %s\n' "$*" >&2
    exit 1
}

require_cmd() {
    local cmd="$1"
    command -v "$cmd" >/dev/null 2>&1 || die "Command not found: $cmd"
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --target)
                TARGET_DIR="${2:-}"
                shift 2
                ;;
            --repo)
                REPO_URL="${2:-}"
                shift 2
                ;;
            --ref)
                REF_NAME="${2:-}"
                shift 2
                ;;
            --init-db)
                RUN_DB_INIT="true"
                shift
                ;;
            --admin-password)
                ADMIN_PASSWORD="${2:-}"
                shift 2
                ;;
            --php)
                PHP_BIN="${2:-}"
                shift 2
                ;;
            --composer)
                COMPOSER_BIN="${2:-}"
                shift 2
                ;;
            --npm)
                NPM_BIN="${2:-}"
                shift 2
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            *)
                die "Unknown argument: $1"
                ;;
        esac
    done
}

ensure_repo() {
    if [[ "$MODE" == "install" ]]; then
        if [[ -d "$TARGET_DIR/.git" ]]; then
            log "Repository already exists in $TARGET_DIR, skipping clone"
        else
            log "Cloning $REPO_URL into $TARGET_DIR"
            git clone "$REPO_URL" "$TARGET_DIR"
        fi
    else
        [[ -d "$TARGET_DIR/.git" ]] || die "Repository not found in $TARGET_DIR. Run install mode first."
    fi
}

checkout_ref() {
    log "Fetching remote refs"
    git -C "$TARGET_DIR" fetch --all --tags --prune

    log "Checking out $REF_NAME"
    git -C "$TARGET_DIR" checkout "$REF_NAME"

    if git -C "$TARGET_DIR" show-ref --verify --quiet "refs/remotes/origin/$REF_NAME"; then
        log "Fast-forwarding from origin/$REF_NAME"
        git -C "$TARGET_DIR" pull --ff-only origin "$REF_NAME"
    else
        log "Ref $REF_NAME is not a tracked origin branch, skipping pull"
    fi
}

install_server_deps() {
    log "Installing server dependencies"
    (cd "$TARGET_DIR/$SERVER_DIR" && "$COMPOSER_BIN" install --no-interaction)
}

clone_if_missing() {
    local repo="$1"
    local dir="$2"
    local branch="${3:-}"

    if [[ -d "$TARGET_DIR/$CLIENT_LIB_DIR/$dir/.git" ]]; then
        log "Client dependency already exists: $dir"
        return 0
    fi

    if [[ -n "$branch" ]]; then
        (cd "$TARGET_DIR/$CLIENT_LIB_DIR" && git clone --branch "$branch" "$repo" "$dir")
    else
        (cd "$TARGET_DIR/$CLIENT_LIB_DIR" && git clone "$repo" "$dir")
    fi
}

install_client_deps() {
    log "Installing client dependencies"

    mkdir -p "$TARGET_DIR/$CLIENT_LIB_DIR"

    clone_if_missing "https://github.com/ColorlibHQ/AdminLTE" "AdminLTE" "v3.2.0"
    clone_if_missing "https://github.com/davidshimjs/qrcodejs" "qrcodejs"
    clone_if_missing "https://github.com/ajaxorg/ace-builds/" "ace-builds"
    clone_if_missing "https://github.com/Leaflet/Leaflet" "Leaflet" "v1.9.2"

    log "Building Leaflet"
    (cd "$TARGET_DIR/$CLIENT_LIB_DIR/Leaflet" && "$NPM_BIN" install && "$NPM_BIN" run build)
}

ensure_configs() {
    log "Ensuring config files exist"

    if [[ ! -f "$TARGET_DIR/$SERVER_CONFIG" && -f "$TARGET_DIR/$SERVER_CONFIG_SAMPLE" ]]; then
        cp "$TARGET_DIR/$SERVER_CONFIG_SAMPLE" "$TARGET_DIR/$SERVER_CONFIG"
    fi

    if [[ ! -f "$TARGET_DIR/$CLIENT_CONFIG" ]]; then
        if [[ -f "$TARGET_DIR/$CLIENT_CONFIG_SAMPLE_JSON5" ]]; then
            cp "$TARGET_DIR/$CLIENT_CONFIG_SAMPLE_JSON5" "$TARGET_DIR/$CLIENT_CONFIG"
        elif [[ -f "$TARGET_DIR/$CLIENT_CONFIG_SAMPLE_JSON" ]]; then
            cp "$TARGET_DIR/$CLIENT_CONFIG_SAMPLE_JSON" "$TARGET_DIR/$CLIENT_CONFIG"
        fi
    fi

    if [[ -f "$TARGET_DIR/$SERVER_CONFIG" ]]; then
        log "Stripping server config (json5 -> json)"
        (cd "$TARGET_DIR" && "$PHP_BIN" server/cli.php --strip-config || true)
    fi
}

run_db_init() {
    [[ "$RUN_DB_INIT" == "true" ]] || return 0
    [[ -n "$ADMIN_PASSWORD" ]] || die "--admin-password is required with --init-db"

    log "Running DB initialization and service tasks"
    (
        cd "$TARGET_DIR" && \
        "$PHP_BIN" server/cli.php --init-db && \
        "$PHP_BIN" server/cli.php --init-clickhouse-db && \
        "$PHP_BIN" server/cli.php --admin-password="$ADMIN_PASSWORD" && \
        "$PHP_BIN" server/cli.php --reindex && \
        "$PHP_BIN" server/cli.php --install-crontabs
    )
}

main() {
    if [[ -z "$MODE" ]]; then
        usage
        exit 0
    fi

    [[ "$MODE" == "install" || "$MODE" == "update" ]] || {
        usage
        die "First argument must be 'install' or 'update'"
    }

    parse_args "$@"

    require_cmd git
    require_cmd "$PHP_BIN"
    require_cmd "$COMPOSER_BIN"
    require_cmd "$NPM_BIN"

    ensure_repo
    checkout_ref
    install_server_deps
    install_client_deps
    ensure_configs
    run_db_init

    log "Done. Mode: $MODE, target: $TARGET_DIR, ref: $REF_NAME"
}

main "$@"
