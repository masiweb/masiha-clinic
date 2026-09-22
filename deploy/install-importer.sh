#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

[[ $EUID = 0 ]] || { echo 'Run with sudo/root'; exit 1; }

ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
[[ -f /etc/masiha-clinic/config.php ]] || {
  echo 'Install Masiha Clinic first with setup.sh'
  exit 1
}

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y   python3 python3-venv python3-pip   xvfb xauth ca-certificates curl

install -d -m 755 /opt/masiha-importer
python3 -m venv /opt/masiha-importer/venv
/opt/masiha-importer/venv/bin/pip install --upgrade pip
/opt/masiha-importer/venv/bin/pip install -r "$ROOT/importer/requirements.txt"

PLAYWRIGHT_BROWSERS_PATH=/opt/masiha-importer/browsers PLAYWRIGHT_DOWNLOAD_CONNECTION_TIMEOUT=120000 /opt/masiha-importer/venv/bin/playwright install --with-deps chromium

mariadb masiha_clinic < "$ROOT/deploy/import.sql"

install -d -m 700 -o www-data -g www-data   /var/lib/masiha-clinic/importer   /var/lib/masiha-clinic/importer/chrome-home   /var/lib/masiha-clinic/importer/chrome-config   /var/lib/masiha-clinic/importer/chrome-cache

cat > /etc/systemd/system/masiha-importer.service <<'UNIT'
[Unit]
Description=Masiha Clinic read-only Boghrat importer
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=oneshot
User=www-data
Group=www-data
Environment=PLAYWRIGHT_BROWSERS_PATH=/opt/masiha-importer/browsers
Environment=HOME=/var/lib/masiha-clinic/importer/chrome-home
Environment=XDG_CONFIG_HOME=/var/lib/masiha-clinic/importer/chrome-config
Environment=XDG_CACHE_HOME=/var/lib/masiha-clinic/importer/chrome-cache
WorkingDirectory=/var/www/masiha-clinic/importer
ExecStart=/usr/bin/xvfb-run -a -s "-screen 0 1280x900x24 -nolisten tcp" /opt/masiha-importer/venv/bin/python /var/www/masiha-clinic/importer/worker.py
UMask=0077
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/lib/masiha-clinic/importer
MemoryMax=500M
MemorySwapMax=512M
CPUQuota=50%
TimeoutStartSec=infinity
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
UNIT

cat > /etc/systemd/system/masiha-importer.timer <<'UNIT'
[Unit]
Description=Check for requested Masiha imports

[Timer]
OnBootSec=60
OnUnitInactiveSec=60
Unit=masiha-importer.service
AccuracySec=5

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload
systemctl enable --now masiha-importer.timer
install -m 644 /dev/null /opt/masiha-importer/ready

echo 'Importer installed. Configure Boghrat credentials in the admin UI before starting an import.'
