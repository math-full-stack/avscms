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

# bsdtar (macOS) silently ignores --no-xattrs, so we strip xattrs from a
# temporary copy before tarring.  On Linux xattr is a no-op so this is safe
# everywhere.
STAGING="/tmp/avscms-staging.$$"
rm -rf "$STAGING"
mkdir -p "$STAGING"
# rsync everything except excluded dirs/files
rsync -a --delete \
    --exclude='./media' \
    --exclude='./scripts' \
    --exclude='./tmp' \
    --exclude='./cache' \
    --exclude='./.git' \
    --exclude='./.github' \
    --exclude='./deploy.sh' \
    --exclude='./.gitignore' \
    --exclude='./include/config.local.php' \
    --exclude='./include/config.db.php' \
    --exclude='./cookies*.txt' \
    "$SCRIPT_DIR/" "$STAGING/"
# strip all macOS extended attributes + resource-fork files
find "$STAGING" -name '._*' -delete 2>/dev/null || true
find "$STAGING" -name '.DS_Store' -delete 2>/dev/null || true
if command -v xattr &>/dev/null; then
    find "$STAGING" -type f -exec xattr -c {} + 2>/dev/null || true
fi
COPYFILE_DISABLE=1 tar -czf "$TARBALL" -C "$STAGING" . 2>/dev/null
rm -rf "$STAGING"
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

# Keep the VM's own DB config (host/credentials differ: local uses the
# prod tunnel 127.0.0.1:3307, the VM talks to its local MariaDB)
if [ -f "${REMOTE_DIR}/include/config.db.php" ]; then
    cp -a "${REMOTE_DIR}/include/config.db.php" /tmp/config.db.php.prev
    echo "Backed up remote include/config.db.php"
else
    rm -f /tmp/config.db.php.prev
fi

# Keep the VM's own GCS config (if any)
for _gcs in include/config.gcs.php include/gcs-service-account.json; do
    if [ -f "${REMOTE_DIR}/${_gcs}" ]; then
        cp -a "${REMOTE_DIR}/${_gcs}" "/tmp/${_gcs##*/}.prev"
        echo "Backed up remote ${_gcs}"
    fi
done

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
# Restore the VM's own GCS config
for _gcs in config.gcs.php gcs-service-account.json; do
    if [ -f "/tmp/${_gcs}.prev" ]; then
        cp -a "/tmp/${_gcs}.prev" "${REMOTE_DIR}/include/${_gcs}"
        echo "Restored remote include/${_gcs}"
    fi
done
# Restore the VM's own DB config (falls back to the local MariaDB if missing)
if [ -f /tmp/config.db.php.prev ]; then
    cp -a /tmp/config.db.php.prev "${REMOTE_DIR}/include/config.db.php"
    echo "Restored remote include/config.db.php"
elif [ ! -f "${REMOTE_DIR}/include/config.db.php" ]; then
    cat > "${REMOTE_DIR}/include/config.db.php" <<'EOF'
<?php
defined('_VALID') or die('Restricted Access!');
$config['db_type'] = 'mysqli';
$config['db_host'] = '127.0.0.1:3306';
$config['db_user'] = 'avs';
$config['db_pass'] = '1689909e285ffd93cae8cb2f';
$config['db_name'] = 'avs';
?>
EOF
    echo "Created include/config.db.php (default VM DB config)"
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
# Run the migration system which tracks applied migrations in db_migrations table
echo "Running DB migrations..."
# Find PHP binary on the VM
PHP_BIN=$(command -v php || echo "/usr/bin/php")
if [ -x "$PHP_BIN" ]; then
    "$PHP_BIN" "${REMOTE_DIR}/include/function_migrations.php" 2>&1 || {
        echo "WARNING: php migrations runner failed, trying direct mysql fallback..."
        for sqlfile in "${REMOTE_DIR}"/sql/migrations/*.sql; do
            [ -f "$sqlfile" ] || continue
            mysql -u root avs < "$sqlfile" 2>/dev/null && echo "  Applied: $(basename "$sqlfile")" || true
        done
    }
else
    echo "PHP not found, running migrations via mysql directly..."
    for sqlfile in "${REMOTE_DIR}"/sql/migrations/*.sql; do
        [ -f "$sqlfile" ] || continue
        mysql -u root avs < "$sqlfile" 2>/dev/null && echo "  Applied: $(basename "$sqlfile")" || true
    done
fi
echo "DB migrations complete."

# --- Runtime directories ---------------------------------------------------
mkdir -p "${REMOTE_DIR}/cache/backend" "${REMOTE_DIR}/cache/frontend" "${REMOTE_DIR}/tmp/logs" "${REMOTE_DIR}/scripts"
mkdir -p "${REMOTE_DIR}/media/users/orig" "${REMOTE_DIR}/media/albums" "${REMOTE_DIR}/media/csv"
mkdir -p "${REMOTE_DIR}/media/photos/tmb" "${REMOTE_DIR}/media/player/logo"
mkdir -p "${REMOTE_DIR}/media/categories/album" "${REMOTE_DIR}/media/categories/video"
mkdir -p "${REMOTE_DIR}/media/videos/vid" "${REMOTE_DIR}/media/videos/flv" "${REMOTE_DIR}/media/videos/tmb"
mkdir -p "${REMOTE_DIR}/media/videos/hd" "${REMOTE_DIR}/media/videos/iphone" "${REMOTE_DIR}/media/videos/h264"
mkdir -p "${REMOTE_DIR}/tmp/albums" "${REMOTE_DIR}/tmp/avatars" "${REMOTE_DIR}/tmp/downloads"
mkdir -p "${REMOTE_DIR}/tmp/sessions" "${REMOTE_DIR}/tmp/thumbs" "${REMOTE_DIR}/tmp/uploader"

# --- AppleDouble / macOS junk cleanup --------------------------------------
# Never leave Finder metadata (._* sidecars, .DS_Store) behind: besides
# doubling every file, those binary sidecars get executed/included by PHP
# tooling and show up as "Mac OS X ATTR com.apple.provenance..." garbage.
find "${REMOTE_DIR}" -name '._*' -delete 2>/dev/null || true
find "${REMOTE_DIR}" -name '.DS_Store' -delete 2>/dev/null || true

# --- Ownership / permissions -----------------------------------------------
# Own the whole tree by the web server user (readable code, writable runtime
# dirs - the owner has rwx on dirs). File/dir MODES are deliberately left
# untouched: the admin check page now flags paths only when they are not
# actually writable (siteadmin/modules/index/check.php), so permissions
# configured on the VM survive deploys instead of being reset to 0777/0775
# on every run.
chown -R www-data:www-data "${REMOTE_DIR}"
chmod +x "${REMOTE_DIR}/scripts/yt-dlp" "${REMOTE_DIR}"/scripts/*.sh 2>/dev/null || true

rm -f "${TARBALL}"
echo "VM deploy OK"
REMOTE

# ---------------------------------------------------------------------------
# 2b. Deploy GCS config files (if they exist locally)
# ---------------------------------------------------------------------------
step "Deploying GCS config files..."
# Upload to /tmp and sudo-install into place: the code step above already
# chown'd REMOTE_DIR to www-data, so a plain scp as the SSH user cannot
# overwrite these files (Permission denied).
for _gcs in include/config.gcs.php include/gcs-service-account.json; do
    if [ -f "${SCRIPT_DIR}/${_gcs}" ]; then
        _name="${_gcs##*/}"
        gcloud compute scp --quiet --strict-host-key-checking=no \
            "${SCRIPT_DIR}/${_gcs}" "${TARGET}:/tmp/${_name}" \
            --zone "$ZONE" --project "$PROJECT"
        gcloud compute ssh --quiet --strict-host-key-checking=no "$TARGET" \
            --zone "$ZONE" --project "$PROJECT" --command \
            "sudo install -o www-data -g www-data -m 644 '/tmp/${_name}' '${REMOTE_DIR}/${_gcs}' && rm -f '/tmp/${_name}' && echo '  Uploaded: ${_gcs}'"
    else
        echo "  Skipped (not found locally): ${_gcs}"
    fi
done

# ---------------------------------------------------------------------------
# 3. Sync static media assets (media/player) — shipped in the repo
# ---------------------------------------------------------------------------
step "Syncing static media assets (media/player)..."
_tmpmedia="/tmp/avscms-player.$$.tar"
( cd "$SCRIPT_DIR" && COPYFILE_DISABLE=1 tar -cf "$_tmpmedia" \
        --exclude='*/._*' --exclude='*/.DS_Store' media/player ) 2>/dev/null
gcloud compute scp --quiet --strict-host-key-checking=no \
    "$_tmpmedia" "${TARGET}:${_tmpmedia}" \
    --zone "$ZONE" --project "$PROJECT"
gcloud compute ssh --quiet --strict-host-key-checking=no \
    --zone "$ZONE" "$TARGET" --project "$PROJECT" \
    --command "sudo tar -xf '${_tmpmedia}' -C '${REMOTE_DIR}' && sudo chown -R www-data:www-data '${REMOTE_DIR}/media/player' && sudo find '${REMOTE_DIR}/media/player' -name '._*' -delete 2>/dev/null; sudo find '${REMOTE_DIR}/media/player' -name '.DS_Store' -delete 2>/dev/null; rm -f '${_tmpmedia}' && echo PLAYER_SYNC_OK"
rm -f "$_tmpmedia"

# ---------------------------------------------------------------------------
# 4. Sync runtime scripts/ (grabber helpers, yt-dlp, scrapers) — no cookies
# ---------------------------------------------------------------------------
step "Syncing runtime scripts/ (grabber helpers, yt-dlp)..."
_tmrscript="/tmp/avscms-scripts.$$.tar"
( cd "$SCRIPT_DIR" && COPYFILE_DISABLE=1 tar -cf "$_tmrscript" \
        --exclude='./scripts/bgutil-pot-provider' \
        --exclude='./scripts/cookies*.txt' \
        --exclude='*/._*' --exclude='*/.DS_Store' ./scripts ) 2>/dev/null
gcloud compute scp --quiet --strict-host-key-checking=no \
    "$_tmrscript" "${TARGET}:${_tmrscript}" \
    --zone "$ZONE" --project "$PROJECT"
gcloud compute ssh --quiet --strict-host-key-checking=no \
    --zone "$ZONE" "$TARGET" --project "$PROJECT" \
    --command "sudo tar -xf '${_tmrscript}' -C '${REMOTE_DIR}' && sudo find '${REMOTE_DIR}/scripts' -name '._*' -delete 2>/dev/null; sudo find '${REMOTE_DIR}/scripts' -name '.DS_Store' -delete 2>/dev/null; sudo chown -R www-data:www-data '${REMOTE_DIR}/scripts' && sudo chmod +x '${REMOTE_DIR}/scripts/yt-dlp' '${REMOTE_DIR}'/scripts/*.sh 2>/dev/null; rm -f '${_tmrscript}' && echo SCRIPTS_SYNC_OK"
rm -f "$_tmrscript"

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