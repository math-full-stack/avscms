#!/usr/bin/env bash
# Setup script for production deploy to pornozinho-vm
# Run this ONCE locally (where gcloud is authenticated)

set -euo pipefail

PROJECT="novinhasbr"
VM_NAME="pornozinho-vm"
ZONE="southamerica-east1-a"
VM_USER="ubuntu"        # ajuste se for outro usuário
DEPLOY_PATH="/var/www/avscms"
KEY_NAME="deploy_key"
KEY_PATH="$HOME/.ssh/$KEY_NAME"

step() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }

step "1. Gerando chave SSH dedicada para deploy (ed25519)"
if [[ -f "$KEY_PATH" ]]; then
  echo "Chave $KEY_PATH já existe — pulando geração"
else
  ssh-keygen -t ed25519 -C "github-actions-deploy-$VM_NAME" -f "$KEY_PATH" -N ""
  echo "Chave gerada: $KEY_PATH (privada) e $KEY_PATH.pub (pública)"
fi

step "2. Adicionando chave pública no OS Login do projeto"
gcloud compute os-login ssh-keys add \
  --key-file="$KEY_PATH.pub" \
  --project="$PROJECT" \
  --quiet

step "3. Verificando se OS Login está habilitado na VM"
OS_LOGIN=$(gcloud compute project-info describe --project="$PROJECT" \
  --format="value(commonInstanceMetadata.items[enable-oslogin].value)")
if [[ "$OS_LOGIN" != "TRUE" ]]; then
  echo "⚠ OS Login não está habilitado no projeto. Habilitando..."
  gcloud compute project-info add-metadata \
    --metadata=enable-oslogin=TRUE \
    --project="$PROJECT"
fi

step "4. Testando conexão SSH via gcloud (valida OS Login + chave)"
gcloud compute ssh "$VM_NAME" \
  --zone="$ZONE" \
  --project="$PROJECT" \
  --command="echo 'SSH OK via gcloud'" \
  --quiet

step "5. Preparando diretório de deploy na VM"
gcloud compute ssh "$VM_NAME" \
  --zone="$ZONE" \
  --project="$PROJECT" \
  --command="sudo mkdir -p $DEPLOY_PATH && sudo chown $VM_USER:$VM_USER $DEPLOY_PATH" \
  --quiet

step "6. Testando rsync direto (simula o que o GitHub Actions fará)"
rsync -avz --delete \
  -e "ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null" \
  --exclude '.git' --exclude '.github' --exclude '*.log' \
  . "$VM_USER@$VM_NAME:$DEPLOY_PATH/"

step "7. Testando execução remota de migrations (dry-run)"
gcloud compute ssh "$VM_NAME" \
  --zone="$ZONE" \
  --project="$PROJECT" \
  --command="cd $DEPLOY_PATH && ls sql/*.sql 2>/dev/null | head -5" \
  --quiet

step "✅ Setup completo!"
echo
echo "----------------------------------------"
echo "AGORA configure estes SECRETS no GitHub:"
echo "  Settings → Secrets and variables → Actions → New repository secret"
echo "----------------------------------------"
echo "VM_HOST          = $(gcloud compute instances describe $VM_NAME --zone=$ZONE --project=$PROJECT --format='value(networkInterfaces[0].accessConfigs[0].natIP)')"
echo "VM_USER          = $VM_USER"
echo "SSH_PRIVATE_KEY  = (cole o conteúdo ABAIXO)"
echo "DB_USER          = (seu usuário MySQL)"
echo "DB_PASSWORD      = (sua senha MySQL)"
echo "DB_NAME          = (seu database MySQL)"
echo "----------------------------------------"
cat "$KEY_PATH"
echo "----------------------------------------"