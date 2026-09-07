#!/bin/bash
# Start the local_worker in persistent loop mode.
# Auto-restarts on crash with 5s delay to prevent tight restart loops.
# Usage: nohup bash scripts/start_local_worker.sh &

BASEDIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP="/Applications/XAMPP/xamppfiles/bin/php"
WORKER="$BASEDIR/scripts/local_worker.php"
LOG="$BASEDIR/tmp/logs/local_worker_daemon.log"
MAX_JOBS=4

mkdir -p "$(dirname "$LOG")"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] local_worker daemon started (PID $$)" >> "$LOG"

while true; do
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting local_worker loop (max_jobs=$MAX_JOBS)..." >> "$LOG"
    "$PHP" "$WORKER" loop "$MAX_JOBS" >> "$LOG" 2>&1
    EXIT_CODE=$?
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] local_worker exited with code $EXIT_CODE — restarting in 5s..." >> "$LOG"
    sleep 5
done
