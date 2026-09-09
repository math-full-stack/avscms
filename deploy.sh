#!/usr/bin/env bash
#
# Deploy AVSCMS to Cloud Run (pornozinho).
#
# Usage:
#   ./deploy.sh              — build from local source and deploy
#   ./deploy.sh --no-build   — redeploy the last built image (fast)
#
# The Cloud Build trigger on branch main handles auto-deploy on push.
# This script is for manual deploys from a local machine.
#
# Environment overrides:
#   GCLOUD_PROJECT    (novinhasbr)
#   GCLOUD_REGION     (southamerica-east1)
#   GCLOUD_SERVICE    (pornozinho)
#   GCLOUD_SQL_INST   (novinhasbr:southamerica-east1:pornozinho-sql)
#
set -euo pipefail

PROJECT="${GCLOUD_PROJECT:-novinhasbr}"
REGION="${GCLOUD_REGION:-southamerica-east1}"
SERVICE="${GCLOUD_SERVICE:-pornozinho}"
SQL_INST="${GCLOUD_SQL_INST:-novinhasbr:southamerica-east1:pornozinho-sql}"

# Credenciais vêm do .env local (gitignored) ou de env vars — nunca hardcode.
DB_HOST="${DB_HOST:-$(grep -E '^DB_HOST=' .env 2>/dev/null | head -1 | cut -d= -f2-)}"
DB_USER="${DB_USER:-$(grep -E '^DB_USER=' .env 2>/dev/null | head -1 | cut -d= -f2-)}"
DB_PASSWORD="${DB_PASSWORD:-$(grep -E '^DB_PASSWORD=' .env 2>/dev/null | head -1 | cut -d= -f2-)}"
DB_NAME="${DB_NAME:-$(grep -E '^DB_NAME=' .env 2>/dev/null | head -1 | cut -d= -f2-)}"
: "${DB_HOST:=127.0.0.1}"
: "${DB_USER:=avs_app}"
: "${DB_NAME:=avs}"
if [[ -z "$DB_PASSWORD" ]]; then
    echo "ERROR: DB_PASSWORD não definido. Exporte a variável ou coloque-a no .env local (gitignored)." >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
step() { printf '\n==> %s\n' "$*"; }

cd "$SCRIPT_DIR"

# ---------------------------------------------------------------------------
# 1. Build & deploy via Cloud Build from local source
# ---------------------------------------------------------------------------
if [[ "${1:-}" != "--no-build" ]]; then
    step "Building and deploying to Cloud Run..."
    gcloud run deploy "$SERVICE" \
        --source=. \
        --region="$REGION" \
        --project="$PROJECT" \
        --add-cloudsql-instances="$SQL_INST" \
        --set-env-vars="DB_HOST=${DB_HOST},DB_USER=${DB_USER},DB_PASSWORD=${DB_PASSWORD},DB_NAME=${DB_NAME}" \
        --quiet
else
    step "Redeploying last image (no build)..."
    # Get the current image from the service and redeploy
    IMAGE=$(gcloud run services describe "$SERVICE" \
        --region="$REGION" --project="$PROJECT" \
        --format="value(spec.template.spec.containers[0].image)")
    gcloud run services update "$SERVICE" \
        --region="$REGION" --project="$PROJECT" \
        --image="$IMAGE" --quiet
fi

# ---------------------------------------------------------------------------
# 2. Smoke test
# ---------------------------------------------------------------------------
step "Smoke test..."
URL="https://${SERVICE}.run.app"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$URL/" 2>/dev/null || echo "000")
echo "  $URL/ -> $HTTP_CODE"

DOMAIN_URL="https://${SERVICE}.com"
HTTP_CODE2=$(curl -s -o /dev/null -w "%{http_code}" "$DOMAIN_URL/" 2>/dev/null || echo "000")
echo "  $DOMAIN_URL/ -> $HTTP_CODE2"

echo
echo "Deploy finished."
echo "  Cloud Run: $URL"
echo "  Domain:    $DOMAIN_URL"
