#!/usr/bin/env bash
# Пример вызова обновления кэша дашборда по cron или webhook.
# Подставьте полный URL к api.php на вашем хосте (тот же каталог, что index.php).
# crontab: 0 8-20 * * 1-5 /path/to/refresh-dashboard-cron.example.sh
set -euo pipefail
URL="${DZ_REFRESH_URL:-https://example.com/dashboard/api.php?action=refresh}"
curl -fsS -m 120 --retry 2 "$URL" >/dev/null
echo "$(date -Iseconds) OK refresh"
