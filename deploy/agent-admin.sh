#!/usr/bin/env bash
# Installed as root-owned /usr/local/sbin/masiha-agent-admin.
set -euo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
[[ $EUID = 0 && $# = 1 ]] || exit 1
REPO=/root/masiha-transfer-tools
case "$1" in
 status)
  systemctl show nginx php8.3-fpm mariadb masiha-importer.timer masiha-importer.service masiha-sms-worker.timer --property=Id --property=ActiveState --property=SubState --property=Result
  curl -fsS --max-time 10 -H 'Host: 65.109.208.122' http://127.0.0.1/health
  ;;
 logs) journalctl -u masiha-importer.service -n 80 --no-pager -o cat ;;
 audit)
  MASIHA_CONFIG=/etc/masiha-clinic/config.php /usr/bin/php "$REPO/deploy/audit-clinic.php"
  ;;
 backup) /usr/local/sbin/masiha-backup ;;
 sync)
  [[ -z $(git -C "$REPO" status --porcelain) ]] || { echo 'Source checkout is not clean'; exit 1; }
  git -C "$REPO" fetch origin feature/visit-workflow-core
  git -C "$REPO" checkout --detach FETCH_HEAD
  ;;
 upgrade) bash "$REPO/deploy/upgrade.sh" ;;
 *) echo 'Allowed: status logs audit backup sync upgrade'; exit 1 ;;
esac
