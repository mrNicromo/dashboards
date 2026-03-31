#!/usr/bin/env bash
# ============================================================
# Еженедельный прогрев кэша для weekly.php
# Запускать каждую среду в 10:00 (Москва = UTC+3 → UTC 07:00)
#
# Добавить в crontab (crontab -e):
#   0 7 * * 3 /path/to/dashboard/scripts/refresh-weekly-cron.sh >> /var/log/weekly-dz.log 2>&1
#
# Что делает скрипт:
#   1. Делает HTTP-запрос к weekly.php — PHP выполняет ManagerReport::fetchReport()
#      и обновляет все кэш-файлы (DzWeeklyHistory, DzWeekPayments, DzMrrCache)
#   2. Логирует результат с временной меткой
# ============================================================

set -euo pipefail

BASE_URL="${DASHBOARD_URL:-http://localhost:8080}"
ENDPOINT="${BASE_URL}/weekly.php"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Запуск еженедельного обновления ДЗ..."

HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
  --max-time 120 \
  --retry 3 \
  --retry-delay 10 \
  "${ENDPOINT}")

if [ "${HTTP_CODE}" = "200" ]; then
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] ✓ Данные обновлены (HTTP ${HTTP_CODE})"
else
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] ✗ Ошибка при обновлении (HTTP ${HTTP_CODE})" >&2
  exit 1
fi
