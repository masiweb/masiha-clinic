#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

[[ $EUID = 0 ]] || { echo 'Run with sudo/root'; exit 1; }

source /etc/os-release
[[ ${ID:-} = ubuntu && ${VERSION_ID:-} = 24.04 ]] || {
  echo 'Ubuntu 24.04 is required'
  exit 1
}

DOMAIN=${1:-}
MODE=${2:-}
[[ $DOMAIN =~ ^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?\.[a-zA-Z]{2,}$ ]] || {
  echo 'Usage: sudo bash setup.sh clinic.example.com [--skip-ssl]'
  exit 1
}
[[ -z $MODE || $MODE = --skip-ssl ]] || {
  echo 'Second argument may only be --skip-ssl'
  exit 1
}

ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
APP=/var/www/masiha-clinic
export DEBIAN_FRONTEND=noninteractive

echo '[1/9] Installing OS packages...'
apt-get update
apt-get install -y   ca-certificates curl git jq rsync unzip zip openssl cron   nginx mariadb-server   php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-intl   php8.3-curl php8.3-gd php8.3-xml php8.3-zip   python3 python3-venv python3-pip python3-requests   xvfb xauth   certbot python3-certbot-nginx

systemctl enable --now mariadb php8.3-fpm nginx cron

echo '[2/9] Initializing database and private configuration...'
python3 "$ROOT/deploy/init.py"

echo '[3/9] Deploying application...'
install -d -m 755 "$APP"
for dir in app public deploy importer; do
  install -d -m 755 "$APP/$dir"
  rsync -a --delete     --exclude '__pycache__/' --exclude '*.pyc' --exclude '*.bak*'     "$ROOT/$dir/" "$APP/$dir/"
done
install -m 755 "$ROOT/setup.sh" "$APP/setup.sh"

find "$APP/app" "$APP/public" -type d -exec chmod 755 {} +
find "$APP/app" "$APP/public" -type f -exec chmod 644 {} +
find "$APP/importer" -type d -exec chmod 755 {} +
find "$APP/importer" -type f -exec chmod 644 {} +

echo '[4/9] Configuring PHP...'
cat > /etc/php/8.3/fpm/conf.d/99-masiha.ini <<'INI'
date.timezone=Asia/Tehran
memory_limit=256M
upload_max_filesize=10M
post_max_size=12M
max_execution_time=60
session.cookie_httponly=1
session.cookie_secure=1
session.cookie_samesite=Lax
expose_php=Off
display_errors=Off
log_errors=On
INI

echo '[5/9] Configuring Nginx...'
sed "s/__DOMAIN__/$DOMAIN/g" "$ROOT/deploy/nginx.conf" > /etc/nginx/sites-available/masiha-clinic
ln -sfn /etc/nginx/sites-available/masiha-clinic /etc/nginx/sites-enabled/masiha-clinic
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload php8.3-fpm nginx

echo '[6/9] Installing Boghrat importer and Chromium...'
bash "$ROOT/deploy/install-importer.sh"

echo '[7/9] Installing backup job...'
install -m 700 "$ROOT/deploy/backup.sh" /usr/local/sbin/masiha-backup
printf '23 2 * * * root /usr/local/sbin/masiha-backup\n' > /etc/cron.d/masiha-clinic
chmod 644 /etc/cron.d/masiha-clinic

if [[ $MODE != --skip-ssl ]]; then
  echo '[8/9] Requesting TLS certificate...'
  certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos     --register-unsafely-without-email --redirect
  CHECK_URL="https://$DOMAIN/health"
  CHECK_ARGS=()
else
  echo '[8/9] TLS skipped; run certbot after DNS points to this server.'
  CHECK_URL='http://127.0.0.1/health'
  CHECK_ARGS=(-H "Host: $DOMAIN")
fi

echo '[9/9] Running health and service checks...'
curl -fsS --max-time 15 "${CHECK_ARGS[@]}" "$CHECK_URL"
echo
systemctl is-active mariadb
systemctl is-active php8.3-fpm
systemctl is-active nginx
systemctl is-active masiha-importer.timer

echo
echo 'Boghrat outbound-network preflight:'
bash "$ROOT/deploy/check-boghrat-network.sh" 1 || true

cat <<OUT

Masiha Clinic installation complete.

Application: /var/www/masiha-clinic
Private config: /etc/masiha-clinic/config.php
Initial admin credentials: /etc/masiha-clinic/install.json
Private storage: /var/lib/masiha-clinic
Importer service: masiha-importer.service
Importer timer: masiha-importer.timer

Logs:
  journalctl -fu masiha-importer.service -o cat

If installed with --skip-ssl after DNS cutover run:
  certbot --nginx -d $DOMAIN --redirect

OUT
