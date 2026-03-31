#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8080}"
export HOST PORT

echo "Запуск локального дашборда на http://${HOST}:${PORT}/index.php"

if command -v php >/dev/null 2>&1; then
  exec php -S "${HOST}:${PORT}" -t "${ROOT_DIR}"
fi

if command -v node >/dev/null 2>&1; then
  exec node "${ROOT_DIR}/serve.mjs"
fi

echo "Не найдены php и node. Установите Node.js или PHP." >&2
exit 1
