#!/usr/bin/env bash
#
# Acesso ao MySQL do Pornozinho.
#   ./tunnel.sh            -> túnel via VM GCP (pornozinho-vm)
#   ./tunnel.sh direct     -> Cloud SQL Auth Proxy local (pornozinho-sql)
#   ./tunnel.sh status     -> mostra se a porta 3307 está de pé (e por qual modo)
#   ./tunnel.sh stop       -> encerra túnel/proxy ativo
#
# App local espera: 127.0.0.1:3307 (include/config.db.php)
#
set -euo pipefail

ZONE="${GCLOUD_ZONE:-southamerica-east1-c}"
INSTANCE="${GCLOUD_INSTANCE:-pornozinho-vm}"
PROJECT="${GCLOUD_PROJECT:-novinhasbr}"
SQL_INSTANCE="${GCLOUD_SQL_INSTANCE:-novinhasbr:southamerica-east1:pornozinho-sql}"
LOCAL_PORT="${TUNNEL_LOCAL_PORT:-3307}"
REMOTE_PORT="${TUNNEL_REMOTE_PORT:-3306}"
PID_FILE="/tmp/avscms-tunnel.pid"
LOG_FILE="/tmp/avscms-tunnel.log"
SQL_PROXY_PID="/tmp/avscms-sqlproxy.pid"
SQL_PROXY_LOG="/tmp/avscms-sqlproxy.log"

is_open() {
    nc -z -w 3 127.0.0.1 "${LOCAL_PORT}" 2>/dev/null
}

proxy_pids() {
    pgrep -f "cloud-sql-proxy .*--port ${LOCAL_PORT}" || true
}

status() {
    if is_open; then
        echo "ATIVO em 127.0.0.1:${LOCAL_PORT}"
        local pids
        pids=$(pgrep -f "3307:127.0.0.1:3306" || true)
        [ -n "$pids" ] && echo "  modo: túnel via VM $INSTANCE (ssh $(echo "$pids" | tr '\n' ' '))"
        pids=$(proxy_pids)
        [ -n "$pids" ] && echo "  modo: Cloud SQL Auth Proxy local ($(echo "$pids" | tr '\n' ' '))"
        return 0
    fi
    echo "INATIVO (porta ${LOCAL_PORT} fechada)"
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

direct() {
    if ! command -v cloud-sql-proxy >/dev/null 2>&1; then
        echo "cloud-sql-proxy não instalado. Instale com: brew install cloud-sql-proxy"
        return 1
    fi
    if is_open; then
        echo "Já há algo ativo em 127.0.0.1:${LOCAL_PORT}. Use ./tunnel.sh status"
        return 0
    fi
    echo "Iniciando Cloud SQL Auth Proxy local ${SQL_INSTANCE} -> 127.0.0.1:${LOCAL_PORT}..."
    nohup cloud-sql-proxy "$SQL_INSTANCE" \
        --address 127.0.0.1 --port "$LOCAL_PORT" \
        >"$SQL_PROXY_LOG" 2>&1 &
    local pid i
    for i in $(seq 1 20); do
        sleep 1
        if is_open; then
            pid=$(proxy_pids | head -1) || pid=""
            [ -n "$pid" ] && echo "$pid" > "$SQL_PROXY_PID"
            echo "OK: proxy ativo em 127.0.0.1:${LOCAL_PORT}"
            return 0
        fi
    done
    echo "Falha ao iniciar o proxy. Veja ${SQL_PROXY_LOG}:"; cat "$SQL_PROXY_LOG"
    return 1
}

stop() {
    local any=0 pid
    pid=$(pgrep -f "3307:127.0.0.1:3306" | head -1) || pid=""
    if [ -n "$pid" ]; then
        kill "$pid" 2>/dev/null || true
        echo "Túnel via VM encerrado (pid ${pid})."; any=1
    fi
    pid=$(proxy_pids | head -1) || pid=""
    if [ -n "$pid" ]; then
        kill "$pid" 2>/dev/null || true
        echo "Cloud SQL Auth Proxy encerrado (pid ${pid})."; any=1
    fi
    if [ "$any" -eq 0 ]; then
        echo "Nenhum túnel/proxy para encerrar."
    fi
    rm -f "$PID_FILE" "$SQL_PROXY_PID"
}

case "${1:-start}" in
    start)  start  ;;
    direct) direct ;;
    status) status; exit $? ;;
    stop)   stop   ;;
    *) echo "Uso: $0 [start|direct|status|stop]"; exit 2 ;;
esac
