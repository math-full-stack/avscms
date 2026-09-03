#!/bin/bash
# Garante que o servidor do PO Token (bgutil) esteja rodando.
# Uso: bash scripts/start_pot_provider.sh
# Nota: em servidor real, usar Supervisor/systemd para manter 24/7.

BASE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SERVER_DIR="$BASE_DIR/scripts/bgutil-pot-provider/server"
PORT="${POT_PORT:-4416}"

# Verifica se já está rodando
if lsof -i :$PORT >/dev/null 2>&1; then
    echo "PO Provider já está rodando na porta $PORT"
    exit 0
fi

if [ ! -d "$SERVER_DIR" ] || [ ! -f "$SERVER_DIR/build/main.js" ]; then
    echo "ERRO: Provider não instalado em $SERVER_DIR"
    exit 1
fi

NODE_BIN="$(command -v node)"

cd "$SERVER_DIR"
nohup "$NODE_BIN" build/main.js --port "$PORT" > /tmp/bgutil-pot.log 2>&1 &
sleep 2

if lsof -i :$PORT >/dev/null 2>&1; then
    echo "PO Provider iniciado na porta $PORT (PID $!)"
else
    echo "ERRO: Falha ao iniciar PO Provider. Veja /tmp/bgutil-pot.log"
    exit 1
fi
