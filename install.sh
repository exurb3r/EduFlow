#!/usr/bin/env bash
# install.sh — interactive TUI installer for OpenSinop (macOS/Linux)
# Usage:
#   bash <(curl -fsSL https://raw.githubusercontent.com/koamishin/KoamiStarterKit/main/install.sh)
#   curl -fsSL .../install.sh | bash

set -Eeuo pipefail

ROOT="$(pwd)"
ENV_FILE="$ROOT/.env"
COMPOSE_FILE="$ROOT/docker-compose.yml"
COMPOSE_URL="https://raw.githubusercontent.com/koamishin/KoamiStarterKit/main/docker-compose.yml"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

if [ -t 1 ]; then COLOR=1; else COLOR=0; fi
c() { [ "$COLOR" = 1 ] && printf '%b' "$1$2$RESET" || printf '%s' "$2"; }

title() {
    echo; printf '%s\n' "$(c "$CYAN" "==============================================================================")"
    printf '%s\n' "$(c "$CYAN$BOLD" "$1")" | sed 's/^/  /'
    printf '%s\n' "$(c "$CYAN" "==============================================================================")"
}
info() { printf '  %s\n' "$1"; }
warn() { printf '  %s\n' "$(c "$YELLOW" "[warn] $1")"; }
ok() { printf '  %s\n' "$(c "$GREEN" "[ok]   $1")"; }
err() { printf '  %s\n' "$(c "$RED" "[error] $1")"; }

tty_read() {  # read into var name passed as $1
    local __var="$1"
    if exec 2>/dev/null 3</dev/tty; then
        IFS= read -r "$__var" <&3 || true
        exec 3<&- 2>/dev/null || true
    else
        IFS= read -r "$__var" || true
    fi
}

ask() {
    local prompt="$1" default="${2:-}" value
    if [ -n "$default" ]; then
        printf '  %s [%s]: ' "$prompt" "$default" >&2
    else
        printf '  %s: ' "$prompt" >&2
    fi
    tty_read value
    value="${value:-$default}"
    printf '%s' "$value"
}

ask_secret() {
    local prompt="$1" default="${2:-}" value
    if [ -n "$default" ]; then
        printf '  %s [hidden]: ' "$prompt" >&2
    else
        printf '  %s: ' "$prompt" >&2
    fi
    if exec 2>/dev/null 3</dev/tty; then
        IFS= read -rs value <&3 || true
        exec 3<&- 2>/dev/null || true
        echo >&2
    else
        IFS= read -rs value || true
        echo >&2
    fi
    value="${value:-$default}"
    printf '%s' "$value"
}

ask_choice() {
    # prompt default choice1 choice2 ...
    local prompt="$1" default="$2"; shift 2
    local choices=("$@")
    printf '  %s\n' "$(c "$BOLD" "$prompt")" >&2
    local i=1
    for ch in "${choices[@]}"; do
        if [ "$ch" = "$default" ]; then
            printf '    %d. %s (default)\n' "$i" "$ch" >&2
        else
            printf '    %d. %s\n' "$i" "$ch" >&2
        fi
        i=$((i+1))
    done
    printf '  Select: ' >&2
    local value idx
    tty_read value
    value="${value:-$default}"
    if [[ "$value" =~ ^[0-9]+$ ]] && [ "$value" -ge 1 ] 2>/dev/null && [ "$value" -le "${#choices[@]}" ]; then
        printf '%s' "${choices[$((value-1))]}"
        return
    fi
    for ch in "${choices[@]}"; do
        [ "$ch" = "$value" ] && { printf '%s' "$value"; return; }
    done
    printf '%s' "$default"
}

yes_no() {
    local prompt="$1" default="${2:-yes}" ans
    printf '  %s [%s]: ' "$prompt" "$default" >&2
    tty_read ans
    ans="${ans:-$default}"
    case "$(printf '%s' "$ans" | tr '[:upper:]' '[:lower:]')" in
        y|yes|true|1) printf 'yes' ;;
        *) printf 'no' ;;
    esac
}

cmd_exists() { command -v "$1" >/dev/null 2>&1; }

redis_ping() {
    local host="$1" port="$2" pass="$3"
    if [[ -z "$pass" || "$pass" == "null" ]]; then pass=""; fi
    if cmd_exists nc; then
        local out
        if [ -n "$pass" ]; then
            out=$(printf 'AUTH %s\r\nPING\r\n' "$pass" | nc -w 3 "$host" "$port" 2>/dev/null || true)
        else
            out=$(printf 'PING\r\n' | nc -w 3 "$host" "$port" 2>/dev/null || true)
        fi
        printf '%s' "$out" | grep -qi pong && return 0 || return 1
    fi
    if (exec 3<>"/dev/tcp/$host/$port") 2>/dev/null; then
        exec 3<>"/dev/tcp/$host/$port" 2>/dev/null || return 1
        if [ -n "$pass" ]; then printf 'AUTH %s\r\n' "$pass" >&3; fi
        printf 'PING\r\n' >&3
        local line
        IFS= read -t 3 -r line <&3 || true
        exec 3<&- 3>&- 2>/dev/null || true
        printf '%s' "$line" | grep -qi pong
        return $?
    fi
    return 2
}

rpc_test() {
    local url="$1"
    [ -n "$url" ] || { printf 'skipped'; return; }
    if ! cmd_exists curl; then printf 'curl not available'; return; fi
    local resp
    resp=$(curl -fsS -m 8 -H 'Content-Type: application/json' \
        -d '{"jsonrpc":"2.0","method":"eth_chainId","params":[],"id":1}' "$url" 2>/dev/null || true)
    if printf '%s' "$resp" | grep -q '"result"'; then
        printf '%s' "$resp" | sed -n 's/.*"result":"*\([^"]*\)".*/\1/p' | head -1
        return 0
    fi
    printf 'FAIL'
}

gen_key() {
    if cmd_exists openssl; then
        printf 'base64:%s' "$(openssl rand -base64 32 2>/dev/null | tr -d '\n')"
    else
        local raw
        raw=$(head -c 32 /dev/urandom | base64 | tr -d '\n')
        printf 'base64:%s' "$raw"
    fi
}


# ---- Argument parsing ----
UPDATE=0
for arg in "$@"; do
    case "$arg" in
        --update|-u) UPDATE=1 ;;
        --help|-h) echo "Usage: install.sh [--update]"; exit 0 ;;
    esac
done

env_value() {
    local key="$1"
    [ -f "$ENV_FILE" ] || return 0
    grep -E "^${key}=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

if [ "$UPDATE" = "1" ]; then
    title "OpenSinop Update"
    if [ ! -f "$ENV_FILE" ]; then
        err "No .env found in $ROOT. Run the installer first."
        exit 1
    fi
    if [ ! -f "$COMPOSE_FILE" ]; then
        info "Downloading docker-compose.yml ..."
        curl -fsSL "$COMPOSE_URL" -o "$COMPOSE_FILE" || { err "download failed"; exit 1; }
    fi
    DB_USER="$(env_value DB_USERNAME)"; DB_USER="${DB_USER:-opensinop}"
    DB_NAME="$(env_value DB_DATABASE)"; DB_NAME="${DB_NAME:-opensinop}"
    DB_CID="$(docker compose ps -q db 2>/dev/null || true)"
    if [ -n "$DB_CID" ]; then
        backup_dir="$ROOT/backups"
        mkdir -p "$backup_dir"
        backup_file="$backup_dir/opensinop-$(date +%Y%m%d-%H%M%S).sql"
        if docker compose exec -T db sh -c "pg_dump -U '$DB_USER' '$DB_NAME'" > "$backup_file" 2>/dev/null; then
            ok "Database backup written to $backup_file"
        else
            warn "Database backup failed; continuing update."
            rm -f "$backup_file"
        fi
    fi
    title "Pulling latest images"
    if ! docker compose pull; then
        err "docker compose pull failed."
        exit 1
    fi
    title "Starting updated containers"
    if ! docker compose up -d; then
        err "docker compose up failed."
        exit 1
    fi
    healthy=0
    for _ in $(seq 1 24); do
        status=$(docker inspect --format '{{.State.Health.Status}}' opensinop 2>/dev/null || true)
        if [ "$status" = "healthy" ]; then healthy=1; break; fi
        if [ "$status" = "unhealthy" ]; then break; fi
        sleep 5
    done
    if [ "$healthy" = "1" ]; then
        ok "Application is healthy."
    else
        warn "Application did not report healthy yet; see docker logs opensinop."
    fi
    APP_URL="$(env_value APP_URL)"; APP_URL="${APP_URL:-http://localhost:8000}"
    info "App URL: $APP_URL"
    info "Note: database migrations run automatically on container start."
    ok "Update complete."
    exit 0
fi

title "OpenSinop TUI Installer (bash)"
info "Interactive setup for Docker-based self-hosting."
info "Press Enter to accept a suggested default."
echo

if ! cmd_exists docker; then
    err "Docker is required but was not found."
    exit 1
fi
ok "Docker found."

# Existing .env backup
if [ -f "$ENV_FILE" ]; then
    cp "$ENV_FILE" "$ROOT/.env.previous" 2>/dev/null || true
    warn "Existing .env backed up to .env.previous"
fi

declare -a FIELDS=()
add() { FIELDS+=("$1=$2"); }

title "Application"
APP_NAME=$(ask "Application name" "OpenSinop")
APP_ENV="production"
APP_DEBUG="false"
APP_PORT=$(ask "Published HTTP port" "8000")
APP_URL=$(ask "Public URL" "http://localhost:$APP_PORT")
APP_KEY="$(gen_key)"
ok "Generated APP_KEY"

title "Database"
DB_CONNECTION=$(ask_choice "Database driver" "pgsql" pgsql mysql sqlite)
if [ "$DB_CONNECTION" = "sqlite" ]; then
    # Keep SQLite inside the persisted storage volume so it survives container recreation.
    DB_HOST=""; DB_PORT=""; DB_DATABASE="/app/storage/database.sqlite"; DB_USERNAME=""; DB_PASSWORD=""
else
    DB_HOST="db"
    DB_PORT="$([ "$DB_CONNECTION" = pgsql ] && echo 5432 || echo 3306)"
    DB_DATABASE="opensinop"
    DB_USERNAME="opensinop"
    DB_PASSWORD="opensinop"
fi

# Common environment defaults (production)
CACHE_STORE="database"
SESSION_DRIVER="database"
QUEUE_CONNECTION="database"
REDIS_HOST="127.0.0.1"
REDIS_PORT="6379"
REDIS_PASSWORD="null"

# Mail defaults
MAIL_MAILER="log"
MAIL_HOST="127.0.0.1"
MAIL_PORT="2525"
MAIL_USERNAME=""
MAIL_PASSWORD=""
MAIL_SCHEME="null"
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="$APP_NAME"

title "School identity"
EDUFLOW_INSTITUTION=$(ask "Institution name" "My School")
EDUFLOW_COUNTRY=$(ask "Country (2-letter code)" "US" | tr '[:lower:]' '[:upper:]')
EDUFLOW_TIMEZONE=$(ask "Timezone" "UTC")
EDUFLOW_CURRENCY=$(ask "Accounting currency" "USD" | tr '[:lower:]' '[:upper:]')

title "Lepton agent (Circle + Arc)"
info "Driver selection:"
info "  • fake   - In-memory simulated ledger (no Circle CLI, wallet, or funds needed; safe for testing/demos)"
info "  • circle - Live onchain rail using Circle Agent Stack & Arc network (requires Circle CLI & MPC wallet)"
LEPTON_DRIVER=$(ask_choice "Lepton driver" "fake" fake circle)
LEPTON_CHAIN=$(ask_choice "Chain" "ARC-TESTNET" ARC-TESTNET ARC-MAINNET)
LEPTON_DRY_RUN=$(ask_choice "Dry run (no real transfers)" "true" true false)
LEPTON_TREASURY_ADDRESS=$(ask "Treasury wallet address (empty for fake)" "")
LEPTON_RPC_URL=$(ask "Arc RPC URL (empty for default)" "")

title "Optional AI advisory"
AI_PROVIDER=$(ask_choice "AI provider" "none" none local openai anthropic)
AI_LOCAL_URL=""; AI_LOCAL_MODEL=""; AI_LOCAL_API_KEY=""; OPENAI_API_KEY=""; ANTHROPIC_API_KEY=""
if [ "$AI_PROVIDER" = "local" ]; then
    AI_PROVIDER="local"
    AI_LOCAL_URL=$(ask "Local OpenAI-compatible URL" "http://127.0.0.1:11434/v1")
    AI_LOCAL_MODEL=$(ask "Local model" "llama3.1")
    AI_LOCAL_API_KEY=$(ask_secret "Local API key (optional)" "")
elif [ "$AI_PROVIDER" = "openai" ]; then AI_PROVIDER="openai"
    OPENAI_API_KEY=$(ask_secret "OpenAI API key" "")
elif [ "$AI_PROVIDER" = "anthropic" ]; then AI_PROVIDER="anthropic"
    ANTHROPIC_API_KEY=$(ask_secret "Anthropic API key" "")
else
    AI_PROVIDER="local"
fi

title "Live network checks"
rpc_target="${LEPTON_RPC_URL:-https://rpc.testnet.arc.io}"
if [ "$LEPTON_CHAIN" = "ARC-MAINNET" ] && [ -z "$LEPTON_RPC_URL" ]; then
    rpc_target="https://rpc.mainnet.arc.io"
fi
res=$(rpc_test "$rpc_target")
if [ "$res" = "FAIL" ]; then
    warn "Arc RPC check failed for $rpc_target."
elif [ -n "$res" ]; then
    ok "Arc RPC reachable ($rpc_target): chainId=$res"
fi

if cmd_exists circle; then ok "Circle CLI found on PATH."; else warn "Circle CLI not found on PATH (needed only for circle driver)."; fi

title "Review"
printf '  APP_NAME=%s\n' "$APP_NAME"
printf '  APP_ENV=%s APP_DEBUG=%s\n' "$APP_ENV" "$APP_DEBUG"
printf '  DB_CONNECTION=%s\n' "$DB_CONNECTION"
printf '  CACHE_STORE=%s SESSION_DRIVER=%s QUEUE_CONNECTION=%s\n' "$CACHE_STORE" "$SESSION_DRIVER" "$QUEUE_CONNECTION"
printf '  LEPTON_DRIVER=%s CHAIN=%s\n' "$LEPTON_DRIVER" "$LEPTON_CHAIN"
echo
if [ "$(yes_no "Write this configuration and start Docker?" "yes")" != "yes" ]; then
    warn "Aborted. No changes were made."
    exit 0
fi

if [ ! -f "$COMPOSE_FILE" ]; then
    info "Downloading docker-compose.yml ..."
    if ! curl -fsSL "$COMPOSE_URL" -o "$COMPOSE_FILE"; then
        err "Could not download compose file."
        exit 1
    fi
    ok "Downloaded docker-compose.yml"
fi

# Write .env
{
    cat <<EOF
APP_NAME="$APP_NAME"
APP_ENV="$APP_ENV"
APP_KEY="$APP_KEY"
APP_DEBUG="$APP_DEBUG"
APP_URL="$APP_URL"
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
BCRYPT_ROUNDS=12
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning
DB_CONNECTION="$DB_CONNECTION"
DB_HOST="$DB_HOST"
DB_PORT="$DB_PORT"
DB_DATABASE="$DB_DATABASE"
DB_USERNAME="$DB_USERNAME"
DB_PASSWORD="$DB_PASSWORD"
CACHE_STORE="$CACHE_STORE"
SESSION_DRIVER="$SESSION_DRIVER"
QUEUE_CONNECTION="$QUEUE_CONNECTION"
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
MAIL_MAILER="$MAIL_MAILER"
MAIL_HOST="$MAIL_HOST"
MAIL_PORT="$MAIL_PORT"
MAIL_USERNAME="$MAIL_USERNAME"
MAIL_PASSWORD="$MAIL_PASSWORD"
MAIL_SCHEME="$MAIL_SCHEME"
MAIL_FROM_ADDRESS="$MAIL_FROM_ADDRESS"
MAIL_FROM_NAME="$MAIL_FROM_NAME"
EDUFLOW_INSTITUTION="$EDUFLOW_INSTITUTION"
EDUFLOW_COUNTRY="$EDUFLOW_COUNTRY"
EDUFLOW_TIMEZONE="$EDUFLOW_TIMEZONE"
EDUFLOW_CURRENCY="$EDUFLOW_CURRENCY"
LEPTON_DRIVER="$LEPTON_DRIVER"
LEPTON_CHAIN="$LEPTON_CHAIN"
LEPTON_DRY_RUN="$LEPTON_DRY_RUN"
LEPTON_TREASURY_ADDRESS="$LEPTON_TREASURY_ADDRESS"
LEPTON_RPC_URL="$LEPTON_RPC_URL"
AI_PROVIDER="$AI_PROVIDER"
AI_LOCAL_URL="$AI_LOCAL_URL"
AI_LOCAL_MODEL="$AI_LOCAL_MODEL"
AI_LOCAL_API_KEY="$AI_LOCAL_API_KEY"
OPENAI_API_KEY="$OPENAI_API_KEY"
ANTHROPIC_API_KEY="$ANTHROPIC_API_KEY"
REDIS_HOST="$REDIS_HOST"
REDIS_PORT="$REDIS_PORT"
REDIS_PASSWORD="$REDIS_PASSWORD"
EOF
} > "$ENV_FILE"
chmod 600 "$ENV_FILE" 2>/dev/null || true
# also persist port in .env for reference
printf 'APP_PORT="%s"\n' "$APP_PORT" >> "$ENV_FILE"
ok "Wrote $ENV_FILE"

title "Starting containers"
info "Running: docker compose up -d"
if ! docker compose up -d; then
    err "docker compose up failed."
    exit 1
fi
ok "Containers started."

title "Health check"
healthy=0
for _ in $(seq 1 24); do
    status=$(docker inspect --format '{{.State.Health.Status}}' opensinop 2>/dev/null || true)
    if [ "$status" = "healthy" ]; then healthy=1; break; fi
    if [ "$status" = "unhealthy" ]; then break; fi
    sleep 5
done
if [ "$healthy" = 1 ]; then ok "Application is healthy."; else warn "Application did not report healthy yet; see docker logs opensinop."; fi

title "Next steps"
info "Open the app at $APP_URL"
info "Create the first administrator:"
printf '  %s\n' "$(c "$BOLD" "docker exec -it opensinop php artisan eduflow:bootstrap-admin --name=\"School Operator\" --email=\"operator@example.org\"")"
info "Circle/Arc features require the Circle CLI and arc-canteen on your workflow host."
ok "Installation complete."
