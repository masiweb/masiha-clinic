#!/usr/bin/env bash
set -Eeuo pipefail
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo "Run as root"; exit 1; }

cat >/etc/systemd/system/masiha-sms-worker.service <<'UNIT'
[Unit]
Description=Masiha Clinic SMS queue worker
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=oneshot
User=www-data
Group=www-data
ExecStart=/usr/bin/php /var/www/masiha-clinic/deploy/sms-worker.php
Nice=5
UNIT

cat >/etc/systemd/system/masiha-sms-worker.timer <<'UNIT'
[Unit]
Description=Run Masiha Clinic SMS worker every minute

[Timer]
OnBootSec=2min
OnUnitActiveSec=1min
AccuracySec=10s
Persistent=true
Unit=masiha-sms-worker.service

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload
systemctl enable --now masiha-sms-worker.timer
echo "Masiha SMS worker timer installed. Notifications remain controlled by the in-app sms_notifications switch."
