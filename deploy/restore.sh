#!/usr/bin/env bash
set -Eeuo pipefail
[[ $EUID = 0 && $# = 1 ]] || { echo 'sudo bash deploy/restore.sh /path/to/backup.tar.gz'; exit 1; }
BACKUP=$(realpath "$1")
[[ -f $BACKUP && -f $BACKUP.sha256 ]] || exit 1
EXPECTED=$(cut -d " " -f1 "$BACKUP.sha256")
ACTUAL=$(sha256sum "$BACKUP" | cut -d " " -f1)
[[ $EXPECTED = "$ACTUAL" ]] || { echo "Checksum mismatch"; exit 1; }
# Never overwrite a working deployment without preserving its current state.
/usr/local/sbin/masiha-backup
TMP=$(mktemp -d)
trap 'systemctl start php8.3-fpm; rm -rf "$TMP"' EXIT
systemctl stop php8.3-fpm
tar -xzf "$BACKUP" -C "$TMP"
tar -xzf "$TMP/private.tar.gz" -C /
gzip -dc "$TMP/database.sql.gz" | mariadb masiha_clinic
python3 - <<'PY'
import json,subprocess
c=json.load(open('/etc/masiha-clinic/install.json'))
p=c['db_password']
assert len(p)==64 and all(x in '0123456789abcdef' for x in p)
subprocess.run(['mariadb'],input="ALTER USER 'masiha_clinic'@'localhost' IDENTIFIED BY '"+p+"';",text=True,check=True)
PY
chown -R www-data:www-data /var/lib/masiha-clinic
chown root:www-data /etc/masiha-clinic /etc/masiha-clinic/config.php
chmod 750 /etc/masiha-clinic
chmod 640 /etc/masiha-clinic/config.php
systemctl start php8.3-fpm
echo 'Restored. Check /health and sign-in.'
