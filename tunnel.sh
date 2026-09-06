#!/usr/bin/env bash
#
# Tunnel para o MySQL/MariaDB da VM GCP (xbrasil -> NovinhasBR).
#   ./tunnel.sh          -> inicia o túnel (se já estiver ativo, não duplica)
#   ./tunnel.sh status   -> mostra se a porta 3307 está de pé
#   ./tunnel.sh stop     -> encerra o túnel
#
# App local espera: 127.0.0.1:3307 (include/config.db.php)
#
set -euo pipefail

ZONE="${GCLOUD_ZONE:-southamerica-east1-c}"
INSTANCE="${GCLOUD_INSTANCE:-xbrasil}"
PROJECT="${GCLOUD_PROJECT:-flashentrega}"
LOCAL_PORT="${TUNNEL_LOCAL_PORT:-3307}"
REMOTE_PORT="${TUNNEL_REMOTE_PORT:-3306}"
PID_FILE="/tmp/avscms-tunnel.pid"
LOG_FILE="/tmp/avscms-tunnel.log"

is_open() {
    nc -z -w 3 127.0.0.1 "${LOCAL_PORT}" 2>/dev/null
}

status() {
    if is_open; then
        echo "Túnel ATIVO em 127.0.0.1:${LOCAL_PORT}"
        [ -f "$PID_FILE" ] && echo "PID: $(cat "$PID_FILE") (ssh $(pgrep -f "3307:127.0.0.1:3306" | tr '\n' ' '))"
        return 0
    fi
    echo "Túnel INATIVO (porta ${LOCAL_PORT} fechada)"
    return 1
}

start() {
    if is_open; then
        echo "Túnel já está ativo em 127.0.0.1:${LOCAL_PORT}. Nada a fazer."
        return 0
    fi
    echo "Abrindo túnel ${LOCAL_PORT} -> ${INSTANCE}:127.0.0.1:${REMOTE_PORT} (projeto ${PROJECT})..."
    # O -f faz o ssh virar daemon; guardamos o PID real do ssh.
    nohup gcloud compute ssh --zone "$ZONE" --project "$PROJECT" "$INSTANCE" \
        -- -f -N -L "${LOCAL_PORT}:127.0.0.1:${REMOTE_PORT}" \
        >"$LOG_FILE" 2>&1 &
    local pid i
    for i in $(seq 1 15); do
        sleep 1
        if is_open; then
            pid=$(pgrep -f "3307:127.0.0.1:3306" | head -1) || pid=""
            [ -n "$pid" ] && echo "$pid" > "$PID_FILE"
            echo "OK: túnel ativo em 127.0.0.1:${LOCAL_PORT}"
            return 0
        fi
    done
    echo "Falha ao abrir o túnel. Veja ${LOG_FILE}:"; cat "$LOG_FILE"
    return 1
}

stop() {
    local pid
    pid=$(pgrep -f "3307:127.0.0.1:3306" | head -1) || pid=""
    if [ -n "$pid" ]; then
        kill "$pid" 2>/dev/null || true
        echo "Túnel encerrado (pid ${pid})."
    else
        echo "Nenhum túnel para encerrar."
    fi
    rm -f "$PID_FILE"
}

case "${1:-start}" in
    start)  start  ;;
    status) status; exit $? ;;
    stop)   stop   ;;
    *) echo "Uso: $0 [start|status|stop]"; exit 2 ;;
esac
