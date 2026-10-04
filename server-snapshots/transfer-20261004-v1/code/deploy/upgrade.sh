#!/usr/bin/env bash
set -Eeuo pipefail
[[ $EUID = 0 ]] || { echo 'Run with sudo'; exit 1; }
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
[[ -f /etc/masiha-clinic/config.php ]] || { echo 'Use setup.sh for a new installation'; exit 1; }
/usr/local/sbin/masiha-backup
mariadb masiha_clinic < "$ROOT/deploy/admin-v2.sql"
mariadb masiha_clinic < "$ROOT/deploy/import.sql"
install -d -m 755 /var/www/masiha-clinic/importer
for dir in app public deploy importer; do cp -a "$ROOT/$dir/." "/var/www/masiha-clinic/$dir/"; done
find /var/www/masiha-clinic/app /var/www/masiha-clinic/public -type d -exec chmod 755 {} +
find /var/www/masiha-clinic/app /var/www/masiha-clinic/public -type f -exec chmod 644 {} +
systemctl reload php8.3-fpm
printf 'Masiha Clinic upgraded to 1.2.0. Existing accounts and passwords preserved.\n'
