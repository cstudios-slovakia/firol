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

# Strips surrounding quotes and inline comments. Works for both
#   KEY=value
#   KEY="value"
#   KEY='value'
env_val() {
    local key="$1"
    grep -E "^${key}=" "$ENV_FILE" \
        | head -1 \
        | sed -E "s/^${key}=['\"]?//; s/['\"]?\s*(#.*)?$//"
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
