#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
LOG=/var/log/masiha-schedule-deploy.log
STATUS=/var/log/masiha-schedule-deploy.status

: >"$LOG"
echo RUNNING >"$STATUS"
exec >>"$LOG" 2>&1
trap 'rc=$?; echo "FAILED rc=$rc line=$LINENO" >"$STATUS"; exit $rc' ERR

cd "$ROOT"

echo "=== HEAD ==="
git log -1 --oneline

echo "=== PREDEPLOY LINT ==="
php -l app/bootstrap.php
php -l app/admin-actions.php
php -l app/actions.php
php -l app/portal.php
php -l app/views.php
php -l app/admin-views.php
php -l app/admin-api.php
python3 -W error -m py_compile importer/parser.py

echo "=== ISOLATED TESTS ==="
python3 tests/importer.py

echo "=== BACKUP ==="
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p /var/backups/masiha-clinic
mysqldump --single-transaction --routines --triggers masiha_clinic   | gzip -c >"/var/backups/masiha-clinic/db-before-schedule-$STAMP.sql.gz"
echo "BACKUP=/var/backups/masiha-clinic/db-before-schedule-$STAMP.sql.gz"

echo "=== UPGRADE ==="
bash deploy/upgrade.sh

echo "=== PRODUCTION LINT ==="
php -l /var/www/masiha-clinic/app/bootstrap.php
php -l /var/www/masiha-clinic/app/admin-actions.php
php -l /var/www/masiha-clinic/app/actions.php
php -l /var/www/masiha-clinic/app/portal.php
php -l /var/www/masiha-clinic/app/views.php

cd /var/www/masiha-clinic

echo "=== SCHEDULE SMOKE ==="
cat >/tmp/masiha-schedule-smoke.php <<'PHP'
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
ob_start();
availabilityView();
$html=ob_get_clean();
foreach(['برنامه هفتگی حضور','روز یا بازه غیبت','کپی برنامه حضور','زمان‌های منتشرشده آینده'] as $needle){
  if(!str_contains($html,$needle))throw new RuntimeException('ui_missing_'.$needle);
}
echo "SCHEDULE_SMOKE_PASS\n";
PHP
php /tmp/masiha-schedule-smoke.php
rm -f /tmp/masiha-schedule-smoke.php

echo "=== DATABASE ==="
mariadb -N masiha_clinic -e "
SHOW TABLES LIKE 'therapist_schedules';
SHOW TABLES LIKE 'therapist_absences';
SELECT 'schedules',COUNT(*) FROM therapist_schedules;
SELECT 'absences',COUNT(*) FROM therapist_absences;
"

echo "=== SERVICES ==="
systemctl is-active nginx
systemctl is-active php8.3-fpm
systemctl is-active mariadb
systemctl is-active masiha-importer.timer

echo PASS >"$STATUS"
echo ALL_DEPLOY_AND_SMOKE_TESTS_PASS
