#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

[[ $EUID = 0 ]] || { echo 'Run with sudo/root'; exit 1; }
DOMAIN=${1:-}
[[ $DOMAIN =~ ^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?\.[a-zA-Z]{2,}$ ]] || {
  echo 'Usage: sudo bash deploy/enable-domain.sh clinic.example.com'
  exit 1
}

ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
[[ -f /etc/masiha-clinic/config.php ]] || {
  echo 'Masiha Clinic is not installed on this server.'
  exit 1
}

# Start with HTTP so ACME validation can complete.
sed   -e "s/__DOMAIN__/$DOMAIN/g"   -e 's/__COOKIE_SECURE__/0/g'   "$ROOT/deploy/nginx.conf" > /etc/nginx/sites-available/masiha-clinic

ln -sfn /etc/nginx/sites-available/masiha-clinic /etc/nginx/sites-enabled/masiha-clinic
nginx -t
systemctl reload nginx

certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos   --register-unsafely-without-email --redirect

# HTTPS is now active; require Secure session cookies.
sed -i 's/fastcgi_param MASIHA_COOKIE_SECURE 0;/fastcgi_param MASIHA_COOKIE_SECURE 1;/'   /etc/nginx/sites-available/masiha-clinic

nginx -t
systemctl reload php8.3-fpm nginx

curl -fsS --max-time 15 "https://$DOMAIN/health"
echo
printf 'Domain enabled: https://%s\n' "$DOMAIN"
