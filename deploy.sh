#!/usr/bin/env bash
#
# Deploy AVSCMS from this Mac to the GCP VM (xbrasil).
#
# What it does:
#   1. Packages the code (excluding heavy/generated dirs: media, scripts,
#      tmp, cache — they stay on the VM).
#   2. Uploads the tarball and extracts it over /var/www/html/avscms.
#   3. Restores the VM's own include/config.local.php (so panel settings such
#      as the admin password are kept) and re-applies the environment-specific
#      values (BASE_URL, ffmpeg/php paths) to the shipped config files.
#   4. Fixes ownership/permissions and creates any missing runtime dirs.
#
# Usage:  ./deploy.sh
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration (edit as needed)
# ---------------------------------------------------------------------------
ZONE="southamerica-east1-c"
INSTANCE="xbrasil"
PROJECT="flashentrega"
REMOTE_DIR="/var/www/html/avscms"
TARBALL="/tmp/avscms-code.tar.gz"
SITE_URL="https://novinhasbr.net"
# Canonical admin password stored on the VM (used only when deploying to a VM
# that has no config.local.php yet, e.g. a fresh install).
ADMIN_PASS=")()@(&Brmy001"
# ---------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

step() { printf '\n==> %s\n' "$*"; }

cd "$SCRIPT_DIR"

step "Packaging code (excluding media/scripts/tmp/cache)..."
COPYFILE_DISABLE=1 tar --no-xattrs -czf "$TARBALL" \
    --exclude='./media' \
    --exclude='./scripts' \
    --exclude='./tmp' \
    --exclude='./cache' \
    --exclude='./deploy.sh' \
    --exclude='*/.DS_Store' \
    --exclude='*/._*' \
    -C "$SCRIPT_DIR" .
ls -lh "$TARBALL"

step "Uploading to ${INSTANCE}..."
gcloud compute scp "$TARBALL" "${INSTANCE}:/tmp/avscms-code.tar.gz" \
    --zone "$ZONE" --project "$PROJECT"

step "Extracting on the VM and re-applying VM-specific config..."
gcloud compute ssh --zone "$ZONE" "$INSTANCE" --project "$PROJECT" \
    --command "sudo bash -s" <<'REMOTE'
set -euo pipefail
cd /var/www/html

# Keep a backup of the VM's current config.local.php (panel settings live there)
if [ -f avscms/include/config.local.php ]; then
    cp -a avscms/include/config.local.php /tmp/config.local.php.prev
    echo "Backed up remote include/config.local.php"
else
    rm -f /tmp/config.local.php.prev
fi

# Extract shipped code over the existing deployment (media/tmp/cache untouched)
mkdir -p avscms
tar --warning=no-unknown-keyword -xzf /tmp/avscms-code.tar.gz -C avscms

# Restore VM config.local.php (dev/Mac values shipped in the package are NOT wanted)
if [ -f /tmp/config.local.php.prev ]; then
    cp -a /tmp/config.local.php.prev avscms/include/config.local.php
    echo "Restored remote include/config.local.php"
fi

# --- Environment-specific patches (idempotent) -----------------------------
# config.paths.php: BASE_URL + RELATIVE (local ships http://localhost/avscms)
sed -i "s|http://localhost/avscms|https://novinhasbr.net|; s|RELATIVE'] = '/avscms'|RELATIVE'] = ''|" \
    avscms/include/config.paths.php

# config.local.php: Mac-only tool paths -> Linux paths (no-op if already fixed)
sed -i "s|/Applications/XAMPP/xamppfiles/bin/php|/usr/bin/php|g; s|/opt/homebrew/bin/ffmpeg|/usr/bin/ffmpeg|g; s|/opt/homebrew/bin/ffprobe|/usr/bin/ffprobe|g" \
    avscms/include/config.local.php

# config.local.php: fresh installs get the canonical admin password
if ! grep -q ")()@(&Brmy001" avscms/include/config.local.php 2>/dev/null; then
    sed -i "s|\$config['admin_pass'] = 'admin'|\$config['admin_pass'] = ')()@(\&Brmy001'|" \
        avscms/include/config.local.php 2>/dev/null || true
fi

# --- DB migrations (idempotent) -------------------------------------------
# video.last_update is used by queue/conversion/grabber code; on a fresh DB
# import it is missing and every grab worker dies -> videos stuck 'Baixando'.
if mysql -u root avs -N -e "SHOW COLUMNS FROM video LIKE 'last_update'" 2>/dev/null | grep -q .; then
    echo "DB migration: video.last_update already present"
else
    mysql -u root avs < avscms/sql/add_last_update.sql && echo "DB migration: video.last_update added"
fi

# --- Runtime directories ---------------------------------------------------
mkdir -p avscms/cache/backend avscms/cache/frontend avscms/tmp/logs avscms/scripts
mkdir -p avscms/media/users/orig avscms/media/albums avscms/media/csv
mkdir -p avscms/media/photos/tmb avscms/media/player/logo
mkdir -p avscms/media/categories/album avscms/media/categories/video
mkdir -p avscms/media/videos/vid avscms/media/videos/flv avscms/media/videos/tmb
mkdir -p avscms/media/videos/hd avscms/media/videos/iphone avscms/media/videos/h264
mkdir -p avscms/tmp/albums avscms/tmp/avatars avscms/tmp/downloads
mkdir -p avscms/tmp/sessions avscms/tmp/thumbs avscms/tmp/uploader

# --- Ownership / permissions -----------------------------------------------
chown -R www-data:www-data avscms
chmod -R 0775 avscms/media avscms/tmp avscms/cache avscms/scripts

rm -f /tmp/avscms-code.tar.gz
echo "VM deploy OK"
REMOTE

step "Syncing static media assets (media/player)..."
( cd "$SCRIPT_DIR" && COPYFILE_DISABLE=1 tar --no-xattrs -cf - --exclude='*/._*' --exclude='*/.DS_Store' media/player ) \
    | gcloud compute ssh --zone "$ZONE" "$INSTANCE" --project "$PROJECT" \
        --command "sudo tar -xf - -C /var/www/html/avscms && sudo chown -R www-data:www-data /var/www/html/avscms/media/player && echo PLAYER_SYNC_OK"

step "Syncing runtime scripts/ (grabber helpers, yt-dlp)..."
( cd "$SCRIPT_DIR/.." && COPYFILE_DISABLE=1 tar --no-xattrs -cf - --exclude='*/bgutil-pot-provider' --exclude='*/._*' --exclude='*/.DS_Store' avscms/scripts ) \
    | gcloud compute ssh --zone "$ZONE" "$INSTANCE" --project "$PROJECT" \
        --command "sudo tar -xf - -C /var/www/html && sudo chown -R www-data:www-data /var/www/html/avscms/scripts && sudo chmod +x /var/www/html/avscms/scripts/yt-dlp /var/www/html/avscms/scripts/*.php && echo SCRIPTS_SYNC_OK"

step "Smoke test..."
curl -s -o /dev/null -w "home https://novinhasbr.net/ -> %{http_code}\n" "$SITE_URL/" || true
curl -s -o /dev/null -w "admin login -> %{http_code} (%{redirect_url})\n" \
    -X POST "$SITE_URL/siteadmin/login.php" \
    --data-urlencode "username=admin" \
    --data-urlencode "password=$ADMIN_PASS" \
    -d "submit_login=1" || true

echo
echo "Deploy finished."
