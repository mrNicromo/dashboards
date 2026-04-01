#!/usr/bin/env bash
set -euo pipefail

PORT="${PORT:-80}"

# Railway sets PORT; make Apache listen on it.
if [[ -f /etc/apache2/ports.conf ]]; then
  sed -i -E "s/^[[:space:]]*Listen[[:space:]]+[0-9]+/Listen ${PORT}/" /etc/apache2/ports.conf
fi

if [[ -f /etc/apache2/sites-available/000-default.conf ]]; then
  sed -i -E "s/<VirtualHost\\s+\\*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

exec apache2-foreground

