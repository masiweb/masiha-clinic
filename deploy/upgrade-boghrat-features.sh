#!/usr/bin/env bash
set -Eeuo pipefail

EXPECTED_HOST="b2b"
EXPECTED_IP="192.168.2.128"
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
STATUS=/var/log/masiha-boghrat-features.status
LOG=/var/log/masiha-boghrat-features.log

if [[ "$(hostname)" != "$EXPECTED_HOST" ]] || ! hostname -I | tr ' ' '\n' | grep -Fxq "$EXPECTED_IP"; then
  echo "WRONG_HOST hostname=$(hostname) ips=$(hostname -I)" >&2
  exit 42
fi

: >"$LOG"
echo RUNNING >"$STATUS"
exec >>"$LOG" 2>&1
trap 'rc=$?; echo "FAILED rc=$rc line=$LINENO" >"$STATUS"; exit $rc' ERR

cd "$ROOT"

echo "=== HOST ==="
hostname
hostname -I
echo "=== HEAD ==="
git log -1 --oneline

echo "=== PREDEPLOY LINT ==="
php -l app/bootstrap.php
php -l app/Sms.php
php -l app/admin-actions.php
php -l app/actions.php
php -l app/portal.php
php -l app/views.php
php -l app/admin-views.php
php -l app/admin-api.php
php -l deploy/sms-worker.php
python3 -W error -m py_compile importer/parser.py

echo "=== ISOLATED TESTS ==="
python3 tests/importer.py

echo "=== BACKUP ==="
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p /var/backups/masiha-clinic
mysqldump --single-transaction --routines --triggers masiha_clinic  | gzip -c >"/var/backups/masiha-clinic/db-before-boghrat-features-$STAMP.sql.gz"
echo "BACKUP=/var/backups/masiha-clinic/db-before-boghrat-features-$STAMP.sql.gz"

echo "=== UPGRADE ==="
bash deploy/upgrade.sh

echo "=== PRODUCTION LINT ==="
for f in app/bootstrap.php app/Sms.php app/admin-actions.php app/actions.php app/portal.php app/views.php app/admin-views.php app/admin-api.php deploy/sms-worker.php; do
 php -l "/var/www/masiha-clinic/$f"
done

cd /var/www/masiha-clinic

echo "=== SMS SAFETY ==="
SMS_ON=$(mariadb -N masiha_clinic -e "SELECT COALESCE((SELECT value FROM settings WHERE \`key\`='sms_notifications'),'0')")
echo "sms_notifications=$SMS_ON"
if [[ "$SMS_ON" != "0" && "$SMS_ON" != "1" ]]; then
  echo "invalid sms_notifications value" >&2
  exit 43
fi
if [[ "$SMS_ON" == "0" ]]; then
  SMS_WORKER_OUTPUT=$(php deploy/sms-worker.php)
  [[ "$SMS_WORKER_OUTPUT" == "SMS_DISABLED" ]] || { echo "unexpected worker output: $SMS_WORKER_OUTPUT" >&2; exit 44; }
fi

echo "=== TRANSACTIONAL SCHEDULE SMOKE ==="
cat >/tmp/masiha-boghrat-features-smoke.php <<'PHP'
<?php
require "app/bootstrap.php";
require_once "app/ui.php";
require_once "app/admin-views.php";
require_once "app/views.php";

$admin=(int)q("SELECT id FROM staff WHERE role='admin' AND active=1 AND deleted=0 ORDER BY id LIMIT 1")->fetchColumn();
if(!$admin)throw new RuntimeException('no_admin');

$GLOBALS['db']->beginTransaction();
try{
  $date='2099-01-03';
  $weekday=clinicWeekday($date);
  q("INSERT INTO therapist_schedules(therapist_id,weekday,start_time,end_time,effective_from,effective_to,active,created_by) VALUES(?,?,'09:00:00','12:00:00','2099-01-01',NULL,1,?)",[$admin,$weekday,$admin]);
  if(therapistAvailabilityIssue("$date 09:30:00","$date 10:00:00",$admin)!==null)throw new RuntimeException('inside_schedule_rejected');
  if(therapistAvailabilityIssue("$date 13:00:00","$date 13:30:00",$admin)===null)throw new RuntimeException('outside_schedule_allowed');
  q("INSERT INTO therapist_absences(therapist_id,starts_at,ends_at,reason,active,created_by) VALUES(?,'2099-01-03 09:40:00','2099-01-03 10:10:00','smoke',1,?)",[$admin,$admin]);
  if(therapistAvailabilityIssue("$date 09:45:00","$date 10:00:00",$admin)===null)throw new RuntimeException('absence_allowed');
  $GLOBALS['db']->rollBack();
}catch(Throwable $e){
  if($GLOBALS['db']->inTransaction())$GLOBALS['db']->rollBack();
  throw $e;
}

$_SESSION=['uid'=>$admin,'csrf'=>str_repeat('a',64)];

ob_start();availabilityView();$availability=ob_get_clean();
foreach(['برنامه هفتگی حضور','روز یا بازه غیبت','کپی برنامه حضور','زمان‌های منتشرشده آینده'] as $needle)
 if(!str_contains($availability,$needle))throw new RuntimeException('availability_ui_missing');

$_GET=[];
ob_start();turnBoardView();$turn=ob_get_clean();
foreach(['مدیریت تابلو نوبت','حالت نمایش مانیتور','نوبت امروز'] as $needle)
 if(!str_contains($turn,$needle))throw new RuntimeException('turn_board_ui_missing');

ob_start();smsView();$sms=ob_get_clean();
foreach(['پنل پیامک','قالب‌های پیامک','تاریخچه و صف پیامک'] as $needle)
 if(!str_contains($sms,$needle))throw new RuntimeException('sms_ui_missing');

echo "UI_AND_SCHEDULE_SMOKE_PASS\n";
PHP
php /tmp/masiha-boghrat-features-smoke.php
rm -f /tmp/masiha-boghrat-features-smoke.php

echo "=== DATABASE CHECK ==="
mariadb -N masiha_clinic -e "
SHOW TABLES LIKE 'therapist_schedules';
SHOW TABLES LIKE 'therapist_absences';
SHOW TABLES LIKE 'sms_templates';
SHOW TABLES LIKE 'sms_outbox';
SELECT 'turn_state_column',COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='masiha_clinic' AND TABLE_NAME='physio_sessions' AND COLUMN_NAME='turn_state';
SELECT 'sms_enabled_templates',COUNT(*) FROM sms_templates WHERE enabled=1;
SELECT 'sms_queue',COUNT(*) FROM sms_outbox;
"

echo "=== SERVICES ==="
systemctl is-active nginx
systemctl is-active php8.3-fpm
systemctl is-active mariadb
systemctl is-active masiha-importer.timer
systemctl is-enabled masiha-sms-worker.timer
systemctl is-active masiha-sms-worker.timer

echo PASS >"$STATUS"
echo ALL_BOGHRAT_FEATURE_DEPLOY_TESTS_PASS
