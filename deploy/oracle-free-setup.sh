#!/usr/bin/env bash
set -euo pipefail

# One-shot bootstrap for Ubuntu on Oracle Always Free.
# Usage:
#   bash oracle-free-setup.sh
# Then:
#   cd ~/airtable && AIRTABLE_PAT='pat_...' docker compose up -d --build

sudo apt-get update -y
sudo apt-get install -y ca-certificates curl git

if ! command -v docker >/dev/null 2>&1; then
  curl -fsSL https://get.docker.com | sh
  sudo usermod -aG docker "$USER"
fi

if docker compose version >/dev/null 2>&1; then
  echo "docker compose already installed"
else
  sudo apt-get install -y docker-compose-plugin
fi

sudo systemctl enable docker
sudo systemctl start docker

echo "Bootstrap done."
echo "IMPORTANT: re-login (or run: newgrp docker) to use docker without sudo."
