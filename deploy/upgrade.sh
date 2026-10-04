#!/usr/bin/env bash
set -Eeuo pipefail
[[ $EUID = 0 ]] || { echo 'Run with sudo'; exit 1; }
ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
TARGET=${MASIHA_DEPLOY_ROOT:-/var/www/masiha-clinic}
BACKUPS=${MASIHA_BACKUP_DIR:-/var/backups/masiha-clinic}
BACKUP_COMMAND=${MASIHA_BACKUP_COMMAND:-/usr/local/sbin/masiha-backup}
[[ -f ${MASIHA_CONFIG:-/etc/masiha-clinic/config.php} ]] || { echo 'Use setup.sh for a new installation'; exit 1; }
[[ $(realpath "$ROOT") != $(realpath "$TARGET") ]] || { echo 'Deploy from a separate coherent checkout'; exit 1; }
git -C "$ROOT" rev-parse --verify HEAD >/dev/null
[[ -z $(git -C "$ROOT" status --porcelain) ]] || { echo 'Commit or remove local checkout changes before deploying'; exit 1; }
for dir in app public deploy importer; do [[ -d $TARGET/$dir ]] || { echo "Missing deployed directory: $dir"; exit 1; }; done
find "$ROOT/app" "$ROOT/public" "$ROOT/deploy" "$ROOT/importer" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
for service in masiha-importer.service masiha-sms-worker.service; do
 if systemctl is-active --quiet "$service"; then
  echo "Wait for active worker to finish before deployment: $service"; exit 1
 fi
done
PHP_ACTIVE=0
systemctl is-active --quiet php8.3-fpm && PHP_ACTIVE=1
TIMERS=()
for timer in masiha-importer.timer masiha-sms-worker.timer; do
 if systemctl is-active --quiet "$timer"; then TIMERS+=("$timer"); fi
done
CODE_BACKUP=''
COPY_STARTED=0
restore_state(){
 local rc=$?
 trap - EXIT
 if [[ $rc != 0 && $COPY_STARTED = 1 ]]; then
  if ! tar -xzf "$CODE_BACKUP" -C "$TARGET"; then
   echo 'Code rollback failed; PHP and timers remain stopped. Restore the code snapshot manually.' >&2
   exit "$rc"
  fi
 fi
 local resumed=1
 if [[ $PHP_ACTIVE = 1 ]]; then systemctl start php8.3-fpm || resumed=0; fi
 if [[ $resumed = 1 ]]; then
  for timer in "${TIMERS[@]}"; do systemctl start "$timer" || { [[ $rc != 0 ]] || rc=1; }; done
 else
  [[ $rc != 0 ]] || rc=1
  echo 'PHP failed to start; background timers remain stopped.' >&2
 fi
 exit "$rc"
}
trap restore_state EXIT
for timer in "${TIMERS[@]}"; do systemctl stop "$timer"; done
# Recheck after pausing timers: a tick could have started a worker during preflight.
for service in masiha-importer.service masiha-sms-worker.service; do
 if systemctl is-active --quiet "$service"; then echo "Worker started during preflight: $service"; exit 1; fi
done
"$BACKUP_COMMAND"
systemctl stop php8.3-fpm
umask 077
mkdir -p "$BACKUPS"
CODE_BACKUP=$(mktemp "$BACKUPS/code-before-upgrade-XXXXXXXX.tar.gz")
tar -czf "$CODE_BACKUP" -C "$TARGET" app public deploy importer
sha256sum "$CODE_BACKUP" > "$CODE_BACKUP.sha256"
echo "Code snapshot: $CODE_BACKUP"
mariadb masiha_clinic < "$ROOT/deploy/admin-v2.sql"
mariadb masiha_clinic < "$ROOT/deploy/import.sql"
mariadb masiha_clinic < "$ROOT/deploy/workflow.sql"
COPY_STARTED=1
for dir in app public deploy importer; do cp -a "$ROOT/$dir/." "$TARGET/$dir/"; done
find "$TARGET/app" "$TARGET/public" -type d -exec chmod 755 {} +
find "$TARGET/app" "$TARGET/public" -type f -exec chmod 644 {} +
# Installation of worker units is explicit; an upgrade never enables disabled timers.
printf 'Masiha Clinic upgraded from commit %s; original timer states retained.\n' "$(git -C "$ROOT" rev-parse HEAD)"
