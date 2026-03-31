#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8080}"
LOG_FILE="${ROOT_DIR}/.local-server.log"
ERR_FILE="${ROOT_DIR}/.local-server.err.log"

cd "${ROOT_DIR}"

mkdir -p "${ROOT_DIR}/snapshots"
if [ ! -f "${ROOT_DIR}/snapshots/manifest.json" ]; then
  echo "[]" > "${ROOT_DIR}/snapshots/manifest.json"
fi
if [ ! -f "${ROOT_DIR}/config.php" ] && [ -f "${ROOT_DIR}/config.sample.php" ]; then
  cp "${ROOT_DIR}/config.sample.php" "${ROOT_DIR}/config.php"
fi

PHP_BIN=""
if command -v php >/dev/null 2>&1; then
  PHP_BIN="$(command -v php)"
elif [ -x "/opt/homebrew/bin/php" ]; then
  PHP_BIN="/opt/homebrew/bin/php"
elif [ -x "/usr/local/bin/php" ]; then
  PHP_BIN="/usr/local/bin/php"
fi

NODE_BIN=""
if command -v node >/dev/null 2>&1; then
  NODE_BIN="$(command -v node)"
elif [ -x "/opt/homebrew/bin/node" ]; then
  NODE_BIN="/opt/homebrew/bin/node"
elif [ -x "/usr/local/bin/node" ]; then
  NODE_BIN="/usr/local/bin/node"
fi

is_dashboard_api_ok() {
  local p="$1"
  curl -fsS "http://${HOST}:${p}/api.php?action=snapshots" >/dev/null 2>&1
}

find_free_port() {
  local p="$1"
  while lsof -nP -iTCP:"${p}" -sTCP:LISTEN >/dev/null 2>&1; do
    p=$((p + 1))
    if [ "${p}" -gt 8099 ]; then
      break
    fi
  done
  echo "${p}"
}

if lsof -nP -iTCP:"${PORT}" -sTCP:LISTEN >/dev/null 2>&1; then
  if ! is_dashboard_api_ok "${PORT}"; then
    PORT="$(find_free_port "${PORT}")"
  fi
fi

if ! lsof -nP -iTCP:"${PORT}" -sTCP:LISTEN >/dev/null 2>&1 || ! is_dashboard_api_ok "${PORT}"; then
  if [ -n "${PHP_BIN}" ]; then
    nohup "${PHP_BIN}" -S "${HOST}:${PORT}" -t "${ROOT_DIR}" >"${LOG_FILE}" 2>"${ERR_FILE}" &
  elif [ -n "${NODE_BIN}" ]; then
    export HOST PORT
    nohup "${NODE_BIN}" "${ROOT_DIR}/serve.mjs" >"${LOG_FILE}" 2>"${ERR_FILE}" &
  else
    osascript -e 'display alert "Нет PHP и Node.js" message "Установите Node.js (nodejs.org) или PHP (brew install php), затем снова запустите дашборд."' || true
    exit 1
  fi
  for _ in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
    if lsof -nP -iTCP:"${PORT}" -sTCP:LISTEN >/dev/null 2>&1; then
      break
    fi
    sleep 0.3
  done
fi

URL="http://${HOST}:${PORT}/index.php"
open "${URL}"
