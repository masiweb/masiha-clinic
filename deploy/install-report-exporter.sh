#!/usr/bin/env bash
# Isolated, idempotent PDF and Excel dependencies for Masiha Clinic.
set -Eeuo pipefail
[[ $EUID -eq 0 ]] || { echo "Run as root." >&2; exit 1; }
BASE=/opt/masiha-clinic-report
PY="$BASE/.venv/bin/python"
if [[ -x "$PY" ]] && "$PY" -c 'import weasyprint,xlsxwriter;assert weasyprint.__version__=="66.0";assert xlsxwriter.__version__=="3.2.9"' >/dev/null 2>&1; then
 echo "Patient export dependencies already installed."
 exit 0
fi
export DEBIAN_FRONTEND=noninteractive
apt-get install -y python3-venv python3-pip libpangoft2-1.0-0 libharfbuzz-subset0 fontconfig fonts-dejavu-core
install -d -m 755 "$BASE"
python3 -m venv "$BASE/.venv"
"$BASE/.venv/bin/pip" install --disable-pip-version-check "weasyprint==66.0" "xlsxwriter==3.2.9"
"$PY" -c 'import weasyprint,xlsxwriter; print("WeasyPrint",weasyprint.__version__,"XlsxWriter",xlsxwriter.__version__)'
echo "PDF/XLSX export engine ready."
