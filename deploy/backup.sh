#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
[[ $EUID = 0 ]] || exit 1
DEST=/var/backups/masiha-clinic
mkdir -p "$DEST"
WORK=$(mktemp -d "$DEST/.work.XXXXXX")
WAS_ACTIVE=0
if systemctl is-active --quiet php8.3-fpm; then WAS_ACTIVE=1; systemctl stop php8.3-fpm; fi
trap 'if [[ $WAS_ACTIVE = 1 ]]; then systemctl start php8.3-fpm; fi; rm -rf "$WORK"' EXIT
mariadb-dump --single-transaction masiha_clinic | gzip > "$WORK/database.sql.gz"
tar -czf "$WORK/private.tar.gz" -C / etc/masiha-clinic var/lib/masiha-clinic
NAME="$DEST/masiha-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
tar -czf "$NAME" -C "$WORK" .
sha256sum "$NAME" > "$NAME.sha256"
echo "Backup saved: $NAME"
