#!/usr/bin/env bash
# Entrypoint do container AVSCMS (Cloud Run).
#
# O rootfs do Cloud Run é em memória e /tmp nasce vazio em cada instância, mas
# o repo tem cache/ e tmp/ apontando para lá (ver Dockerfile). Recriar os
# diretórios aqui evita o Smarty/thumb-writer falhar no primeiro request.
set -euo pipefail

mkdir -p /tmp/avscms-cache/frontend /tmp/avscms-cache/backend
mkdir -p /tmp/avscms-tmp/logs /tmp/avscms-tmp/thumbs
chown -R www-data:www-data /tmp/avscms-cache /tmp/avscms-tmp 2>/dev/null || true

exec "$@"
