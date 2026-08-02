#!/usr/bin/env bash
# TryHackX Files host installer for Debian 12/13.
# Run from a checked-out release: sudo bash scripts/install-debian.sh
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
    echo "[TryHackX Files] Run this script as root (sudo bash scripts/install-debian.sh)." >&2
    exit 1
fi

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVICE_USER="${FILEHOST_SERVICE_USER:-www-data}"
SERVICE_GROUP="${FILEHOST_SERVICE_GROUP:-www-data}"
cd "$APP_DIR"

if ! id "$SERVICE_USER" >/dev/null 2>&1; then
    echo "[TryHackX Files] Service user '$SERVICE_USER' does not exist." >&2
    exit 1
fi

echo "[TryHackX Files] Installing Apache, PHP, MariaDB and Python prerequisites..."
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y \
    apache2 mariadb-server ca-certificates curl \
    php libapache2-mod-php php-cli php-mysql php-curl php-mbstring php-xml php-intl \
    python3 python3-venv python3-pip
a2enmod proxy proxy_http rewrite headers

echo "[TryHackX Files] Creating the verified Python environment..."
python3 -m venv "$APP_DIR/venv"
"$APP_DIR/venv/bin/python" -m pip install --require-hashes -r "$APP_DIR/requirements-lock.txt"

echo "[TryHackX Files] Preparing private runtime directories..."
install -d -o "$SERVICE_USER" -g "$SERVICE_GROUP" -m 0750 \
    "$APP_DIR/config" "$APP_DIR/uploads" "$APP_DIR/data"

echo "[TryHackX Files] Installing systemd units..."
escaped_app_dir="$(printf '%s' "$APP_DIR" | sed 's/[&|\\]/\\&/g')"
escaped_user="$(printf '%s' "$SERVICE_USER" | sed 's/[&|\\]/\\&/g')"
escaped_group="$(printf '%s' "$SERVICE_GROUP" | sed 's/[&|\\]/\\&/g')"
sed -e "s|__APP_DIR__|$escaped_app_dir|g" \
    -e "s|__USER__|$escaped_user|g" \
    -e "s|__GROUP__|$escaped_group|g" \
    scripts/filehost-upload.service > /etc/systemd/system/filehost-upload.service
sed -e "s|__APP_DIR__|$escaped_app_dir|g" \
    -e "s|__USER__|$escaped_user|g" \
    -e "s|__GROUP__|$escaped_group|g" \
    scripts/filehost-mail-worker.service > /etc/systemd/system/filehost-mail-worker.service

if [[ ! -e /etc/default/filehost ]]; then
    printf '%s\n' \
        '# TryHackX Files service environment' \
        '# Set to 1 to suppress routine HTTP and worker output while retaining warnings/errors.' \
        'FILEHOST_MINIMAL_LOGS=0' \
        > /etc/default/filehost
    chmod 0640 /etc/default/filehost
    chown root:"$SERVICE_GROUP" /etc/default/filehost
fi

systemctl daemon-reload
systemctl enable filehost-upload.service filehost-mail-worker.service

if [[ -f "$APP_DIR/config/config.local.php" ]]; then
    systemctl restart filehost-upload.service filehost-mail-worker.service
    echo "[TryHackX Files] Services started. Check them with:"
    echo "  systemctl --no-pager --full status filehost-upload filehost-mail-worker"
else
    echo "[TryHackX Files] Host dependencies and services are installed."
    echo "[TryHackX Files] Configure an Apache virtual host with DocumentRoot $APP_DIR/public,"
    echo "[TryHackX Files] Complete the web installer, then run:"
    echo "  systemctl start filehost-upload filehost-mail-worker"
fi

echo "[TryHackX Files] Reloading Apache..."
systemctl reload apache2
