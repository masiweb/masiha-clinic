#!/usr/bin/env bash
set -Eeuo pipefail

ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
MODE=${1:-status}

need_root() {
  [[ ${EUID:-$(id -u)} -eq 0 ]] || { echo "Run as root"; exit 1; }
}

status() {
  mariadb masiha_clinic -e "
  SELECT id,status,processed,saved,duplicates,page_no,row_no,hourly_limit,delay_seconds,total_limit,message
  FROM import_runs ORDER BY id DESC LIMIT 3;

  SELECT COUNT(*) AS imported_records,
         SUM(pid IS NOT NULL) AS registered_patients,
         SUM(pid IS NULL) AS without_patient
  FROM import_records;

  SELECT COUNT(*) AS structured_events,
         COUNT(DISTINCT record_id) AS patients_with_events
  FROM import_patient_events;

  SELECT COUNT(*) AS financial_lines,
         COUNT(DISTINCT record_id) AS patients_with_financial_data
  FROM import_financial_lines;
  "
}

prepare_100() {
  need_root
  systemctl disable --now masiha-importer.timer || true
  systemctl stop masiha-importer.service 2>/dev/null || true

  cd "$ROOT"
  git pull --ff-only origin main
  bash deploy/upgrade.sh

  php -l app/import.php
  php -l app/actions.php
  php -l app/views.php
  php -l importer/bridge.php
  python3 -m py_compile importer/parser.py importer/worker.py importer/audit.py importer/audit_summary.py

  # Preserve credentials/clinic and current case-number settings. Start a fresh
  # controlled 100-patient run with unlimited hourly ceiling and 8-second pacing.
  php deploy/start-boghrat-100.php

  systemctl enable --now masiha-importer.timer
  systemctl start --no-block masiha-importer.service

  echo "Controlled 100-patient run started."
  status
}

audit() {
  need_root
  systemctl disable --now masiha-importer.timer || true
  systemctl stop masiha-importer.service 2>/dev/null || true

  runuser -u www-data -- env     PLAYWRIGHT_BROWSERS_PATH=/opt/masiha-importer/browsers     HOME=/var/lib/masiha-clinic/importer/chrome-home     XDG_CONFIG_HOME=/var/lib/masiha-clinic/importer/chrome-config     XDG_CACHE_HOME=/var/lib/masiha-clinic/importer/chrome-cache     xvfb-run -a -s "-screen 0 1280x900x24 -nolisten tcp"     /opt/masiha-importer/venv/bin/python     /var/www/masiha-clinic/importer/audit.py     --max-pages 250 --delay 2

  runuser -u www-data --     /opt/masiha-importer/venv/bin/python     /var/www/masiha-clinic/importer/audit_summary.py     /var/lib/masiha-clinic/importer/boghrat-capability-audit.json     > /var/lib/masiha-clinic/importer/boghrat-capability-summary.md

  chmod 600     /var/lib/masiha-clinic/importer/boghrat-capability-audit.json     /var/lib/masiha-clinic/importer/boghrat-capability-summary.md

  cat /var/lib/masiha-clinic/importer/boghrat-capability-summary.md
}

case "$MODE" in
  status) status ;;
  prepare-100) prepare_100 ;;
  audit) audit ;;
  *)
    echo "Usage: $0 {status|prepare-100|audit}"
    exit 2
    ;;
esac
