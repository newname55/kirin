#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

ENV_FILE="$ROOT_DIR/.env.ftp"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing .env.ftp. Copy .env.ftp.example and fill in FTP credentials first." >&2
  exit 1
fi

# shellcheck disable=SC1090
source "$ENV_FILE"

: "${FTP_HOST:?FTP_HOST is required}"
: "${FTP_PORT:=21}"
: "${FTP_USER:?FTP_USER is required}"
: "${FTP_PASS:?FTP_PASS is required}"
: "${FTP_REMOTE:=/public_html}"

if [[ "$FTP_REMOTE" != "/public_html" && "$FTP_REMOTE" != "/public_html/" ]]; then
  echo "Refusing to deploy outside /public_html: $FTP_REMOTE" >&2
  exit 1
fi

if ! command -v lftp >/dev/null 2>&1; then
  echo "lftp is required but not installed." >&2
  exit 1
fi

lftp -u "$FTP_USER","$FTP_PASS" "ftp://$FTP_HOST:$FTP_PORT" <<EOF
set ftp:ssl-allow no
set net:timeout 20
set net:max-retries 2
set xfer:clobber on
set cmd:fail-exit yes
cd /public_html
put index.html
put cast-asuka.html
put cast-kanon.html
put cast-list.html
put cast-rio.html
put cast-runa.html
put cast-yuki.html
put first-guide.html
put privacy.html
put recruit.html
put robots.txt
put sitemap.xml
put system.html
mirror -R --verbose \
  --exclude-glob .DS_Store \
  --exclude-glob .env \
  --exclude-glob .env.* \
  --exclude-glob .vscode \
  --exclude-glob .git \
  --exclude-glob .github \
  --exclude-glob _wp_backup_partial \
  --exclude-glob docs \
  --exclude-glob mail \
  --exclude-glob xserver_php \
  --exclude-glob autoreply \
  --exclude-glob htpasswd \
  --exclude-glob log \
  --exclude-glob script \
  --exclude-glob README.md \
  assets assets
bye
EOF
