#!/usr/bin/env bash
#
# Deploy AVSCMS to the GCP VM (xbrasil).
#
# Works in two places:
#   - Locally:  ./deploy.sh                     (uses your own gcloud login/SSH keys)
#   - CI (GitHub Actions): runs with GCLOUD_* env vars + a service-account key
#     (see .github/workflows/deploy.yml)
#
# What it does:
#   1. Packages the repo code (excludes runtime dirs, secrets, .git, .github).
#   2. Uploads via `gcloud compute scp` and extracts over the VM deploy dir.
#   3. Preserves the VM's own include/config.local.php and scripts/cookies.txt
#      (panel settings, admin password and live scraper session never get
#      clobbered by a deploy) and re-applies environment-specific values
#      (BASE_URL, ffmpeg/php paths) to the shipped config files.
#   4. Applies idempotent DB migrations.
#   5. Fixes ownership/permissions and creates missing runtime dirs.
#   6. Syncs media/player and scripts/ (shipped in the repo).
#   7. Smoke test run from the VM itself (admin password read on the VM).
#
# Environment overrides (defaults in parentheses):
#   GCLOUD_ZONE       (southamerica-east1-c)
#   GCLOUD_INSTANCE   (xbrasil)
#   GCLOUD_PROJECT    (flashentrega)
#   GCLOUD_SSH_USER   (unset -> gcloud uses your local user; CI sets e.g. matheussturiao)
#   GCLOUD_REMOTE_DIR (/var/www/html/avscms)
#   GCLOUD_SITE_URL   (https://novinhasbr.net)
#
set -euo pipefail

ZONE="${GCLOUD_ZONE:-southamerica-east1-c}"
INSTANCE="${GCLOUD_INSTANCE:-xbrasil}"
PROJECT="${GCLOUD_PROJECT:-flashentrega}"
SSH_USER="${GCLOUD_SSH_USER:-}"
REMOTE_DIR="${GCLOUD_REMOTE_DIR:-/var/www/html/avscms}"
SITE_URL="${GCLOUD_SITE_URL:-https://novinhasbr.net}"
TARBALL="/tmp/avscms-code.$$.tar.gz"   # unique per run so a stale file from a failed run can never block scp

# "user@instance" when SSH_USER is set, otherwise just the instance name
# (gcloud then logs in with the local OS user, like the old manual workflow).
if [ -n "$SSH_USER" ]; then
    TARGET="${SSH_USER}@${INSTANCE}"
else
    TARGET="${INSTANCE}"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
step() { printf '\n==> %s\n' "$*"; }

cd "$SCRIPT_DIR"

# ---------------------------------------------------------------------------
# 1. Package the code (runtime dirs, secrets and git metadata never ship)
# ---------------------------------------------------------------------------
step "Packaging code (excluding media/scripts/tmp/cache/.git/secrets)..."
COPYFILE_DISABLE=1 tar --no-xattrs -czf "$TARBALL" \
    --exclude='./media' \
    --exclude='./scripts' \
    --exclude='./tmp' \
    --exclude='./cache' \
    --exclude='./.git' \
    --exclude='./.github' \
    --exclude='./deploy.sh' \
    --exclude='./.gitignore' \
    --exclude='./include/config.local.php' \
    --exclude='./cookies*.txt' \
    --exclude='*/._*' \
    --exclude='*/.DS_Store' \
    -C "$SCRIPT_DIR" .
ls -lh "$TARBALL"

# ---------------------------------------------------------------------------
# 2. Upload + install on the VM
# ---------------------------------------------------------------------------
step "Uploading to ${TARGET}..."
gcloud compute scp --quiet --strict-host-key-checking=no \
    "$TARBALL" "${TARGET}:${TARBALL}" \
    --zone "$ZONE" --project "$PROJECT"

step "Extracting on the VM and re-applying VM-specific config..."
gcloud compute ssh --quiet --strict-host-key-checking=no \
    --zone "$ZONE" "$TARGET" --project "$PROJECT" \
    --command "SITE_URL='${SITE_URL}' REMOTE_DIR='${REMOTE_DIR}' TARBALL='${TARBALL}' sudo -E bash -s" <<'REMOTE'
set -euo pipefail

cd "${REMOTE_DIR%/*}"   # e.g. /var/www/html

# Keep the VM's own config.local.php (panel settings / admin password live there)
if [ -f "${REMOTE_DIR}/include/config.local.php" ]; then
    cp -a "${REMOTE_DIR}/include/config.local.php" /tmp/config.local.php.prev
    echo "Backed up remote include/config.local.php"
else
    rm -f /tmp/config.local.php.prev
fi

# Keep the VM's own scraper cookies (live xfree session)
if [ -f "${REMOTE_DIR}/scripts/cookies.txt" ]; then
    cp -a "${REMOTE_DIR}/scripts/cookies.txt" /tmp/cookies.txt.prev
    echo "Backed up remote scripts/cookies.txt"
else
    rm -f /tmp/cookies.txt.prev
fi

# Extract shipped code over the existing deployment (media/tmp/cache untouched)
mkdir -p "${REMOTE_DIR}"
tar --warning=no-unknown-keyword -xzf "${TARBALL}" -C "${REMOTE_DIR}"

# Restore the VM's own files
if [ -f /tmp/config.local.php.prev ]; then
    cp -a /tmp/config.local.php.prev "${REMOTE_DIR}/include/config.local.php"
    echo "Restored remote include/config.local.php"
fi
if [ -f /tmp/cookies.txt.prev ]; then
    cp -a /tmp/cookies.txt.prev "${REMOTE_DIR}/scripts/cookies.txt"
    echo "Restored remote scripts/cookies.txt"
fi

# --- Environment-specific patches (idempotent) -----------------------------
CONFIG_LOCAL="${REMOTE_DIR}/include/config.local.php"
if [ -f "$CONFIG_LOCAL" ]; then
    # config.paths.php: BASE_URL + RELATIVE (local ships http://localhost/avscms)
    sed -i "s|http://localhost/avscms|${SITE_URL}|; s|RELATIVE'] = '/avscms'|RELATIVE'] = ''|" \
        "${REMOTE_DIR}/include/config.paths.php"
    # config.local.php: Mac-only tool paths -> Linux paths (no-op if already fixed)
    sed -i "s|/Applications/XAMPP/xamppfiles/bin/php|/usr/bin/php|g; s|/opt/homebrew/bin/ffmpeg|/usr/bin/ffmpeg|g; s|/opt/homebrew/bin/ffprobe|/usr/bin/ffprobe|g" \
        "$CONFIG_LOCAL"
else
    # Fresh install: minimal config.local.php (admin/admin; change from the panel)
    cat > "$CONFIG_LOCAL" <<'EOF'
<?php
defined('_VALID') or die('Restricted Access!');
$config['admin_name'] = 'admin';
$config['admin_pass'] = 'admin';
?>
EOF
    echo "Created minimal include/config.local.php (admin/admin)"
fi

# --- DB migrations (idempotent) -------------------------------------------
if mysql -u root avs -N -e "SHOW COLUMNS FROM video LIKE 'last_update'" 2>/dev/null | grep -q .; then
    echo "DB migration: video.last_update already present"
else
    mysql -u root avs < "${REMOTE_DIR}/sql/add_last_update.sql" \
        && echo "DB migration: video.last_update added"
fi

# --- Runtime directories ---------------------------------------------------
mkdir -p "${REMOTE_DIR}/cache/backend" "${REMOTE_DIR}/cache/frontend" "${REMOTE_DIR}/tmp/logs" "${REMOTE_DIR}/scripts"
mkdir -p "${REMOTE_DIR}/media/users/orig" "${REMOTE_DIR}/media/albums" "${REMOTE_DIR}/media/csv"
mkdir -p "${REMOTE_DIR}/media/photos/tmb" "${REMOTE_DIR}/media/player/logo"
mkdir -p "${REMOTE_DIR}/media/categories/album" "${REMOTE_DIR}/media/categories/video"
mkdir -p "${REMOTE_DIR}/media/videos/vid" "${REMOTE_DIR}/media/videos/flv" "${REMOTE_DIR}/media/videos/tmb"
mkdir -p "${REMOTE_DIR}/media/videos/hd" "${REMOTE_DIR}/media/videos/iphone" "${REMOTE_DIR}/media/videos/h264"
mkdir -p "${REMOTE_DIR}/tmp/albums" "${REMOTE_DIR}/tmp/avatars" "${REMOTE_DIR}/tmp/downloads"
mkdir -p "${REMOTE_DIR}/tmp/sessions" "${REMOTE_DIR}/tmp/thumbs" "${REMOTE_DIR}/tmp/uploader"

# --- Ownership / permissions -----------------------------------------------
chown -R www-data:www-data "${REMOTE_DIR}"
chmod -R 0775 "${REMOTE_DIR}/media" "${REMOTE_DIR}/tmp" "${REMOTE_DIR}/cache" "${REMOTE_DIR}/scripts"

rm -f "${TARBALL}"
echo "VM deploy OK"
REMOTE

# ---------------------------------------------------------------------------
# 3. Sync static media assets (media/player) — shipped in the repo
# ---------------------------------------------------------------------------
step "Syncing static media assets (media/player)..."
( cd "$SCRIPT_DIR" && COPYFILE_DISABLE=1 tar --no-xattrs -cf - \
        --exclude='*/._*' --exclude='*/.DS_Store' media/player ) \
    | gcloud compute ssh --quiet --strict-host-key-checking=no \
        --zone "$ZONE" "$TARGET" --project "$PROJECT" \
        --command "sudo tar -xf - -C '${REMOTE_DIR}' && sudo chown -R www-data:www-data '${REMOTE_DIR}/media/player' && echo PLAYER_SYNC_OK"

# ---------------------------------------------------------------------------
# 4. Sync runtime scripts/ (grabber helpers, yt-dlp, scrapers) — no cookies
# ---------------------------------------------------------------------------
step "Syncing runtime scripts/ (grabber helpers, yt-dlp)..."
( cd "$SCRIPT_DIR" && COPYFILE_DISABLE=1 tar --no-xattrs -cf - \
        --exclude='./scripts/bgutil-pot-provider' \
        --exclude='./scripts/cookies*.txt' \
        --exclude='*/._*' --exclude='*/.DS_Store' ./scripts ) \
    | gcloud compute ssh --quiet --strict-host-key-checking=no \
        --zone "$ZONE" "$TARGET" --project "$PROJECT" \
        --command "sudo tar -xf - -C '${REMOTE_DIR}' && sudo chown -R www-data:www-data '${REMOTE_DIR}/scripts' && sudo chmod +x '${REMOTE_DIR}/scripts/yt-dlp' '${REMOTE_DIR}/scripts'/*.php && echo SCRIPTS_SYNC_OK"

# ---------------------------------------------------------------------------
# 5. Smoke test (from the VM itself; admin password read from the VM config)
# ---------------------------------------------------------------------------
step "Smoke test (on the VM)..."
gcloud compute ssh --quiet --strict-host-key-checking=no \
    --zone "$ZONE" "$TARGET" --project "$PROJECT" \
    --command "REMOTE_DIR='${REMOTE_DIR}' sudo -E bash -s" <<'SMOKE'
set -euo pipefail
cd "${REMOTE_DIR}"
ADMIN_PASS="$(sed -n "s/.*\['admin_pass'\] = '\([^']*\)'.*/\1/p" include/config.local.php | head -1)"
curl -s -o /dev/null -w "home http://localhost/ -> %{http_code}\n" http://localhost/ || true
curl -s -o /dev/null -w "admin login -> %{http_code}\n" \
    -X POST http://localhost/siteadmin/login.php \
    --data-urlencode "username=admin" \
    --data-urlencode "password=${ADMIN_PASS}" \
    -d "submit_login=1" || true
SMOKE

echo
echo "Deploy finished."