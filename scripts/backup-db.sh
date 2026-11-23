#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Firol — nightly MariaDB backup
#
# Usage:
#   ./scripts/backup-db.sh
#
# Reads DB credentials from backend/.env (same variables as the PHP app).
# Writes a gzip-compressed SQL dump to BACKUP_DIR and removes dumps older
# than KEEP_DAYS days.
#
# Cron setup (WebSupport cPanel → Cron Jobs, or crontab -e):
#   0 2 * * * /usr/home/awgefy/public_html/app.poapp.sk/scripts/backup-db.sh \
#             >> /usr/home/awgefy/db-backups/backup.log 2>&1
# ---------------------------------------------------------------------------
set -euo pipefail

# Never stop silently: under `set -e` a failing step used to end the script
# with no output at all, so the cron log stayed empty while no dump was made.
trap 'rc=$?; echo "[$(date -Iseconds)] [ERROR] backup failed (line $LINENO, exit $rc) — no dump written" >&2; rm -f "${FILE:-}"' ERR

# ── Config ──────────────────────────────────────────────────────────────────

# Where dumps land. Must be OUTSIDE the webroot so they are never publicly
# accessible. On WebSupport this is one level above public_html.
BACKUP_DIR="${BACKUP_DIR:-/usr/home/awgefy/db-backups}"

# Delete dumps older than this many days.
KEEP_DAYS="${KEEP_DAYS:-60}"

# ── Resolve paths ────────────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="$ROOT/backend/.env"

if [[ ! -f "$ENV_FILE" ]]; then
    echo "[ERROR] .env not found at $ENV_FILE" >&2
    exit 1
fi

# ── Parse credentials from .env ──────────────────────────────────────────────

# Reads a key the way phpdotenv (the app) does, closely enough for these
# values. Works for
#   KEY=value          KEY=value # comment   (comment needs a space before #)
#   KEY="value"        KEY='value'
#     KEY=value        (indented — the production .env had this, and the old
#                       `^KEY=` match found nothing, so no backup was ever made)
#   export KEY=value
# A missing key prints nothing and is not an error (DB_HOST / DB_PORT have
# defaults below; DB_NAME / DB_USER are checked).
env_val() {
    local key="$1" line
    line=$(grep -E "^[[:space:]]*(export[[:space:]]+)?${key}[[:space:]]*=" "$ENV_FILE" | head -1 || true)
    [[ -n "$line" ]] || return 0
    line="${line%$'\r'}"
    line="${line#*=}"
    line="${line#"${line%%[![:space:]]*}"}"
    case "$line" in
        \"*) line="${line#\"}"; line="${line%%\"*}" ;;
        \'*) line="${line#\'}"; line="${line%%\'*}" ;;
        *)   line=$(printf '%s' "$line" | sed -E 's/[[:space:]]+#.*$//; s/[[:space:]]+$//') ;;
    esac
    printf '%s' "$line"
}

DB_HOST="${DB_HOST:-$(env_val DB_HOST)}"
DB_PORT="${DB_PORT:-$(env_val DB_PORT)}"
DB_NAME="${DB_NAME:-$(env_val DB_NAME)}"
DB_USER="${DB_USER:-$(env_val DB_USER)}"
DB_PASS="${DB_PASS:-$(env_val DB_PASS)}"

DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"

if [[ -z "$DB_NAME" || -z "$DB_USER" ]]; then
    echo "[ERROR] DB_NAME or DB_USER is empty — check $ENV_FILE" >&2
    exit 1
fi

# ── Run dump ─────────────────────────────────────────────────────────────────

mkdir -p "$BACKUP_DIR"

DATE=$(date +%Y-%m-%d)
FILE="$BACKUP_DIR/firol_${DATE}.sql.gz"

# MYSQL_PWD avoids the password showing up in the process list (ps aux).
# --single-transaction: consistent InnoDB snapshot without table locks.
# --no-tablespaces: not required but avoids PROCESS privilege issues on
#   shared hosts that restrict tablespace visibility.
export MYSQL_PWD="$DB_PASS"

mysqldump \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --user="$DB_USER" \
    --single-transaction \
    --no-tablespaces \
    --routines \
    --triggers \
    "$DB_NAME" \
  | gzip -9 \
  > "$FILE"

unset MYSQL_PWD

SIZE=$(du -h "$FILE" | cut -f1)
echo "[$(date -Iseconds)] Backup OK → $FILE ($SIZE)"

# ── Cleanup old dumps ────────────────────────────────────────────────────────

REMOVED=$(find "$BACKUP_DIR" -name "firol_*.sql.gz" -mtime "+${KEEP_DAYS}" -print -delete | wc -l)
if [[ "$REMOVED" -gt 0 ]]; then
    echo "[$(date -Iseconds)] Removed $REMOVED dump(s) older than ${KEEP_DAYS} days"
fi
