#!/usr/bin/env bash
# ============================================================
# cron_warmup.example.sh — пример запуска прогрева кэша
#
# Скопируйте в cron_warmup.sh и отредактируйте пути.
# Добавьте в crontab командой: crontab -e
#
# Расписание: ежедневно в 22:00 MSK (= 19:00 UTC)
#   0 19 * * *  /path/to/dashboard/scripts/cron_warmup.sh
# ============================================================

PHP_BIN=/usr/bin/php
SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOG_FILE="$SCRIPT_DIR/logs/cron_warmup.log"

mkdir -p "$SCRIPT_DIR/logs"

echo "--- $(date '+%Y-%m-%d %H:%M:%S') ---" >> "$LOG_FILE"
"$PHP_BIN" "$SCRIPT_DIR/cron_warmup.php" --force >> "$LOG_FILE" 2>&1
STATUS=$?

if [ $STATUS -ne 0 ]; then
  echo "[WARN] cron_warmup завершился с кодом $STATUS" >> "$LOG_FILE"
fi

exit $STATUS
