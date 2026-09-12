#!/usr/bin/env bash
#
# Reaplica a rede de produção que a VM perde quando é recriada (preemptible):
#   1. Tag de firewall 'http-server' -> permite sondas/tráfego do LB na :80
#   2. Endpoint do NEG do LB         -> aponta para o IP interno atual da VM
#
# Idempotente: roda quantas vezes quiser. Usar após recriar pornozinho-vm
# e também no setup-deploy.sh / pós-migração.
#
# Uso: ./deploy/fix-tags.sh
#
set -euo pipefail

PROJECT="${GCLOUD_PROJECT:-novinhasbr}"
VM="${GCLOUD_VM:-pornozinho-vm}"
ZONE="${GCLOUD_ZONE:-southamerica-east1-c}"
NEG="${GCLOUD_NEG:-pornozinho-vm-neg}"
BACKEND="${GCLOUD_BACKEND:-pornozinho-backend}"
PORT=80
TAG_REQUIRED="http-server"

step() { printf '\n==> %s\n' "$*"; }

step "Tag de firewall '$TAG_REQUIRED' em $VM"
TAGS=$(gcloud compute instances describe "$VM" --zone="$ZONE" --project="$PROJECT" \
  --format="value(tags.items[])")
if [[ ",$TAGS," == *",$TAG_REQUIRED,"* ]]; then
  echo "ok: tag '$TAG_REQUIRED' já presente (tags: $TAGS)"
else
  gcloud compute instances add-tags "$VM" --zone="$ZONE" --project="$PROJECT" \
    --tags "$TAG_REQUIRED"
  TAGS=$(gcloud compute instances describe "$VM" --zone="$ZONE" --project="$PROJECT" \
    --format="value(tags.items[])")
  echo "tags finais: $TAGS"
fi

step "Endpoint do NEG $NEG apontando para o IP interno atual"
IP=$(gcloud compute instances describe "$VM" --zone="$ZONE" --project="$PROJECT" \
  --format="value(networkInterfaces[0].networkIP)")
END_IP=$(gcloud compute network-endpoint-groups list-network-endpoints "$NEG" \
  --zone="$ZONE" --format="value(networkEndpoint.ipAddress)")
END_PORT=$(gcloud compute network-endpoint-groups list-network-endpoints "$NEG" \
  --zone="$ZONE" --format="value(networkEndpoint.port)")

if [[ "$END_IP" == "$IP" && "$END_PORT" == "$PORT" ]]; then
  echo "ok: endpoint já é $IP:$PORT"
else
  if [[ -n "$END_IP" ]]; then
    gcloud compute network-endpoint-groups update "$NEG" --zone="$ZONE" \
      --remove-endpoint instance="$VM",ip="$END_IP",port="$END_PORT"
  fi
  gcloud compute network-endpoint-groups update "$NEG" --zone="$ZONE" \
    --add-endpoint instance="$VM",ip="$IP",port="$PORT"
  echo "endpoint re-atarado para $IP:$PORT"
fi

step "Saúde do backend ($BACKEND)"
sleep 5
gcloud compute backend-services get-health "$BACKEND" --global \
  --format="value(status.healthStatus[0].healthState)"