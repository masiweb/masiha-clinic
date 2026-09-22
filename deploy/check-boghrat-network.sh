#!/usr/bin/env bash
set -Eeuo pipefail

COUNT=${1:-3}
[[ $COUNT =~ ^[1-9][0-9]?$ ]] || {
  echo 'Usage: bash deploy/check-boghrat-network.sh [1-99]'
  exit 1
}

targets=(
  "APP|https://app.boghrat.com/"
  "ACCOUNT|https://account.boghrat.com/auth/login"
  "ADMAPI|https://admapi.boghrat.com/boghratsite/application/Config/getwebconfig"
)

fail=0
for ((round=1; round<=COUNT; round++)); do
  echo "=== round $round/$COUNT $(date -u '+%F %T UTC') ==="
  for item in "${targets[@]}"; do
    name=${item%%|*}
    url=${item#*|}
    out=$(curl -4 -sS -o /dev/null --max-time 12       -w 'HTTP=%{http_code} CONNECT=%{time_connect} TLS=%{time_appconnect} START=%{time_starttransfer} TOTAL=%{time_total}'       "$url" 2>&1) || true
    printf '%-8s %s\n' "$name" "$out"
    code=$(sed -n 's/.*HTTP=\([0-9][0-9][0-9]\).*/\1/p' <<<"$out")
    [[ $code = 200 || $name = APP && $code = 301 || $name = APP && $code = 302 ]] || fail=1
  done
  echo
  (( round == COUNT )) || sleep 2
done

if (( fail )); then
  echo 'WARNING: One or more Boghrat endpoints were not consistently reachable.'
  exit 2
fi

echo 'Boghrat network preflight passed.'
