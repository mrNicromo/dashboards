#!/usr/bin/env bash
set -euo pipefail

# Ensure exactly one Apache MPM is enabled.
a2dismod mpm_event mpm_worker mpm_prefork >/dev/null 2>&1 || true
a2enmod mpm_prefork >/dev/null

exec apache2-foreground

