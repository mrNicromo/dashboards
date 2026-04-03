#!/usr/bin/env bash
# Загрузка CSV в Merchrules Dashboard (backend-v2/import/tasks/csv).
#
# Авторизация (один из вариантов):
#   MERCHRULES_API_TOKEN — Bearer-токен, если backend выдаёт долгоживущий ключ для API;
#   MERCHRULES_COOKIE — браузерная сессия (короткоживущая).
# Переменные: export, .env в корне или dashboard/, файл dashboard/.merchrules_cookie (только cookie).
#
# Usage:
#   ./roadmap_import_csv.sh 8591 /path/to/tasks.csv
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
DASHBOARD_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"

# Загрузка только MERCHRULES_* из .env (первая «=» отделяет значение).
load_merchrules_env() {
  local f="$1"
  [[ -f "$f" ]] || return 0
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line//$'\r'/}"
    [[ "$line" =~ ^[[:space:]]*# ]] && continue
    [[ -z "${line// }" ]] && continue
    [[ "$line" =~ ^MERCHRULES_[A-Za-z0-9_]+= ]] || continue
    local key="${line%%=*}"
    local val="${line#*=}"
    val="${val#\"}"
    val="${val%\"}"
    val="${val#\'}"
    val="${val%\'}"
    export "${key}=${val}"
  done < "$f"
}

load_merchrules_env "$ROOT_DIR/.env"
load_merchrules_env "$DASHBOARD_DIR/.env"

SITE_ID="${1:?site_id}"
CSV="${2:?path to csv}"
BASE="${MERCHRULES_BASE_URL:-https://merchrules.any-platform.ru}"
ENDPOINT="${BASE}/backend-v2/import/tasks/csv"

COOKIE_FILE="${SCRIPT_DIR}/.merchrules_cookie"
if [[ ! -f "$COOKIE_FILE" ]]; then
  COOKIE_FILE="${DASHBOARD_DIR}/.merchrules_cookie"
fi
if [[ -z "${MERCHRULES_COOKIE:-}" ]] && [[ -f "$COOKIE_FILE" ]]; then
  MERCHRULES_COOKIE="$(tr -d '\n\r' < "$COOKIE_FILE")"
fi

CURL_AUTH=()
if [[ -n "${MERCHRULES_API_TOKEN:-}" ]]; then
  CURL_AUTH+=( -H "Authorization: Bearer ${MERCHRULES_API_TOKEN}" )
fi
if [[ -n "${MERCHRULES_COOKIE:-}" ]]; then
  CURL_AUTH+=( -H "Cookie: ${MERCHRULES_COOKIE}" )
fi
if [[ ${#CURL_AUTH[@]} -eq 0 ]]; then
  echo "Укажите MERCHRULES_API_TOKEN или MERCHRULES_COOKIE в .env (см. .env.example) или cookie-файл dashboard/.merchrules_cookie" >&2
  exit 1
fi

exec curl -sS -w "\nHTTP:%{http_code}\n" -X POST "$ENDPOINT" \
  "${CURL_AUTH[@]}" \
  -F "site_id=${SITE_ID}" \
  -F "file=@${CSV};type=text/csv;charset=utf-8"
