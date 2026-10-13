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

# Optional switches (all default to the behaviour described in docs/DEBIAN.md):
#   FILEHOST_INSTALL_DB=0     do not install mariadb-server (MySQL or a remote database is used)
#   FILEHOST_PHP_SAPI=fpm     install php-fpm + proxy_fcgi instead of libapache2-mod-php; the FPM
#                             pool and the handler for the virtual host are configured by hand
packages=(apache2 ca-certificates curl
    php php-cli php-mysql php-curl php-mbstring php-gd php-xml
    python3 python3-venv)
modules=(proxy proxy_http rewrite headers expires)
if [[ "${FILEHOST_INSTALL_DB:-1}" != "0" ]]; then
    packages+=(mariadb-server)
fi
if [[ "${FILEHOST_PHP_SAPI:-mod_php}" == "fpm" ]]; then
    packages+=(php-fpm)
    modules+=(proxy_fcgi setenvif)
else
    packages+=(libapache2-mod-php)
fi

echo "[TryHackX Files] Installing Apache, PHP, database and Python prerequisites..."
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y "${packages[@]}"
a2enmod "${modules[@]}"

# The hash-locked environment is compiled for Python 3.11 and newer.
if ! python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 11) else 1)'; then
    echo "[TryHackX Files] Python 3.11 or newer is required (found $(python3 -V 2>&1))." >&2
    exit 1
fi

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
# ProtectHome=true hides /home and /root from the services, which would make an application
# directory below them unreachable. Fall back to read-only there (ReadWritePaths still opens
# uploads/ and data/).
protect_home="true"
case "$APP_DIR" in
    /home/*|/root/*)
        protect_home="read-only"
        echo "[TryHackX Files] $APP_DIR is below /home or /root: using ProtectHome=read-only." >&2
        echo "[TryHackX Files] A directory such as /var/www/filehost is recommended." >&2
        ;;
esac
for unit in filehost-upload filehost-mail-worker; do
    sed -e "s|__APP_DIR__|$escaped_app_dir|g" \
        -e "s|__USER__|$escaped_user|g" \
        -e "s|__GROUP__|$escaped_group|g" \
        -e "s|^ProtectHome=true\$|ProtectHome=$protect_home|" \
        "scripts/$unit.service" > "/etc/systemd/system/$unit.service"
done

# ProtectSystem=strict makes everything read-only except uploads/ and data/. Storage moved out of
# the application directory (docs/STORAGE.md) therefore has to be opened explicitly; derive that
# from config/config.local.php so an update cannot silently turn uploads into a read-only error.
storage_dropin_dir="/etc/systemd/system/filehost-upload.service.d"
storage_dropin="$storage_dropin_dir/10-storage.conf"
storage_paths=()
if [[ -f "$APP_DIR/config/config.local.php" ]]; then
    for constant in UPLOADS_PATH DATA_PATH FILE_QUARANTINE_PATH; do
        value="$(sed -n "s/^[[:space:]]*define([[:space:]]*['\"]${constant}['\"][[:space:]]*,[[:space:]]*['\"]\\([^'\"]*\\)['\"].*/\\1/p" \
            "$APP_DIR/config/config.local.php" | head -n 1)"
        if [[ "$value" == /* ]]; then
            storage_paths+=("-$value")
        elif [[ -n "$value" ]]; then
            # Relative paths resolve from the project root.
            storage_paths+=("-$APP_DIR/${value#./}")
        fi
    done
fi
if (( ${#storage_paths[@]} > 0 )); then
    install -d "$storage_dropin_dir"
    {
        echo "# Written by scripts/install-debian.sh from config/config.local.php; do not edit."
        echo "[Service]"
        echo "ReadWritePaths=${storage_paths[*]}"
    } > "$storage_dropin"
    echo "[TryHackX Files] Storage outside the application directory: ${storage_paths[*]}"
elif [[ -f "$storage_dropin" ]] && head -n 1 "$storage_dropin" | grep -q 'Written by scripts/install-debian.sh'; then
    rm -f "$storage_dropin"
    rmdir "$storage_dropin_dir" 2>/dev/null || true
fi

if [[ ! -e /etc/default/filehost ]]; then
    printf '%s\n' \
        '# TryHackX Files service environment' \
        '# Set to 1 to suppress routine HTTP and worker output while retaining warnings/errors.' \
        'FILEHOST_MINIMAL_LOGS=0' \
        '# Where the "Local mail server" e-mail method submits. Uncomment only when a local' \
        '# MTA does not listen on 127.0.0.1:25.' \
        '#FILEHOST_LOCAL_MTA=127.0.0.1:25' \
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
