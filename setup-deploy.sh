#!/usr/bin/env bash
# Setup script for production deploy to pornozinho-vm (with IAP tunneling)
# Run this ONCE locally (where gcloud is authenticated)

set -euo pipefail

PROJECT="novinhasbr"
VM_NAME="pornozinho-vm"
ZONE="southamerica-east1-c"
VM_USER="matheussturiao_gmail_com"  # OS Login username
DEPLOY_PATH="/var/www/avscms"
KEY_NAME="deploy_key"
KEY_PATH="$HOME/.ssh/$KEY_NAME"

step() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }

GCLOUD_SSH="gcloud compute ssh $VM_NAME --zone=$ZONE --project=$PROJECT --tunnel-through-iap --quiet"

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

step "3. Verificando se OS Login está habilitado no projeto"
OS_LOGIN=$(gcloud compute project-info describe --project="$PROJECT" \
  --format="value(commonInstanceMetadata.items[enable-oslogin].value)")
if [[ "$OS_LOGIN" != "TRUE" ]]; then
  echo "⚠ OS Login não está habilitado no projeto. Habilitando..."
  gcloud compute project-info add-metadata \
    --metadata=enable-oslogin=TRUE \
    --project="$PROJECT"
fi

step "4. Testando conexão SSH via IAP"
$GCLOUD_SSH --command="echo 'SSH OK via IAP'"

step "5. Preparando diretório de deploy na VM"
$GCLOUD_SSH --command="sudo mkdir -p $DEPLOY_PATH && sudo chown $VM_USER:$VM_USER $DEPLOY_PATH"

step "6. Testando execução remota (dry-run migrations)"
$GCLOUD_SSH --command="cd $DEPLOY_PATH && ls sql/*.sql 2>/dev/null | head -5"

step "7. Testando sudo systemctl reload apache2"
$GCLOUD_SSH --command="sudo systemctl reload apache2"

step "✅ Setup completo!"
echo
echo "----------------------------------------"
echo "AGORA configure estes SECRETS no GitHub:"
echo "  Settings → Secrets and variables → Actions → New repository secret"
echo "----------------------------------------"
echo "VM_NAME          = $VM_NAME"
echo "VM_ZONE          = $ZONE"
echo "PROJECT_ID       = $PROJECT"
echo "VM_USER          = $VM_USER"
echo "DEPLOY_PATH      = $DEPLOY_PATH"
echo "SSH_PRIVATE_KEY  = (cole o conteúdo ABAIXO)"
echo "DB_USER          = (seu usuário MySQL)"
echo "DB_PASSWORD      = (sua senha MySQL)"
echo "DB_NAME          = (seu database MySQL)"
echo "----------------------------------------"
cat "$KEY_PATH"
echo "----------------------------------------"
echo
echo "O workflow usará 'gcloud compute ssh --tunnel-through-iap' para deploy."