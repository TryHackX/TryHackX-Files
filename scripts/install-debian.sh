#!/usr/bin/env bash
# TryHackX Files host installer for Debian 12/13.
# Run from a checked-out release: sudo bash scripts/install-debian.sh
#
# Running it again is how a release that changes the systemd units is applied, so a re-run keeps
# what the installation already uses: the service account of the installed units, PHP-FPM when no
# PHP module is enabled in Apache, and no local MariaDB when a configured installation has none.
# Packages that are already installed are not upgraded, and existing runtime directories keep
# their owner and mode.
#
# Switches (environment variables, see docs/DEBIAN.md):
#   FILEHOST_UNITS_ONLY=1       render the units and the storage drop-in, reload systemd and
#                               restart the services, nothing else: no packages, no Python
#                               environment, no ownership changes. Use it after a release that
#                               changes scripts/*.service or after moving the storage paths.
#   FILEHOST_SERVICE_USER, FILEHOST_SERVICE_GROUP
#                               the account of a new installation (default www-data). The account
#                               of the installed units is kept; another one is refused unless
#   FILEHOST_CHANGE_ACCOUNT=1   is set as well, which also hands config/, uploads/ and data/ over.
#   FILEHOST_INSTALL_DB=0|1     whether to install mariadb-server (default: for a new
#                               installation, or when it is already installed)
#   FILEHOST_PHP_SAPI=fpm|mod_php
#                               php-fpm with proxy_fcgi, or libapache2-mod-php (default: fpm when
#                               php-fpm is installed and Apache has no PHP module enabled)

UPLOAD_UNIT=/etc/systemd/system/filehost-upload.service

log() {
    echo "[TryHackX Files] $*"
}

warn() {
    echo "[TryHackX Files] $*" >&2
}

# The last `Key=` value of a unit file; nothing when the file or the key is missing.
unit_value() {
    local file="$1" key="$2"
    [[ -f "$file" ]] || return 0
    sed -n "s/^[[:space:]]*${key}=[[:space:]]*//p" "$file" | tail -n 1 | sed 's/[[:space:]]*$//'
}

# Prints "user group": the requested account, else the installed unit's, else www-data. A request
# for another account than the installed one fails unless the change is explicit.
resolve_account() {
    local unit_file="$1" requested_user="$2" requested_group="$3" change_account="$4"
    local installed_user installed_group user group
    installed_user="$(unit_value "$unit_file" User)"
    installed_group="$(unit_value "$unit_file" Group)"
    user="${requested_user:-${installed_user:-www-data}}"
    group="${requested_group:-${installed_group:-www-data}}"
    if [[ -n "$installed_user" && "$change_account" != "1" ]] \
        && [[ "$user" != "$installed_user" || "$group" != "${installed_group:-$group}" ]]; then
        warn "The installed services run as ${installed_user}:${installed_group:-?}, not ${user}:${group}."
        warn "Leave FILEHOST_SERVICE_USER and FILEHOST_SERVICE_GROUP unset to keep that account, or"
        warn "add FILEHOST_CHANGE_ACCOUNT=1 to move the installation to ${user}:${group} (docs/DEBIAN.md)."
        return 1
    fi
    printf '%s %s\n' "$user" "$group"
}

# Whether a package is installed (not merely known, or removed with its configuration kept).
package_installed() {
    [[ "$(dpkg-query -W -f='${Status}' "$1" 2>/dev/null || true)" == "install ok installed" ]]
}

# "fpm" when php-fpm is installed and no PHP module is enabled in Apache, which is what an FPM
# setup looks like; "mod_php" otherwise, including on a machine without Apache or PHP yet.
detect_php_sapi() {
    local fpm_packages apache_modules
    fpm_packages="$(dpkg-query -W -f='${Status}\n' 'php*-fpm' 2>/dev/null || true)"
    apache_modules="$(a2query -m 2>/dev/null || true)"
    if grep -qx 'install ok installed' <<<"$fpm_packages" \
        && ! grep -Eq '^php[0-9.]*( |$)' <<<"$apache_modules"; then
        echo fpm
    else
        echo mod_php
    fi
}

# Whether to install mariadb-server: as requested, else for a new installation, else only when
# the configured installation already has it (one without it uses MySQL or a remote server).
want_local_database() {
    local requested="$1" configured="$2"
    if [[ -n "$requested" ]]; then
        [[ "$requested" != "0" ]]
        return
    fi
    [[ "$configured" != "1" ]] || package_installed mariadb-server
}

# config/, uploads/ and data/ for the service account. A new installation, or an explicit account
# change, hands them over (uploads/ and data/ already exist in a checkout); a re-run only creates
# what is missing, because `install -d` would otherwise re-own and re-mode existing directories.
prepare_directories() {
    local app_dir="$1" user="$2" group="$3" take_over="$4" dir
    for dir in config uploads data; do
        if [[ "$take_over" == "1" || ! -d "$app_dir/$dir" ]]; then
            install -d -o "$user" -g "$group" -m 0750 "$app_dir/$dir"
        fi
    done
}

# Renders the unit templates of template_dir for the application directory and account into
# target_dir.
render_units() {
    local app_dir="$1" user="$2" group="$3" target_dir="$4" template_dir="$5"
    local escaped_app_dir escaped_user escaped_group protect_home unit
    escaped_app_dir="$(printf '%s' "$app_dir" | sed 's/[&|\\]/\\&/g')"
    escaped_user="$(printf '%s' "$user" | sed 's/[&|\\]/\\&/g')"
    escaped_group="$(printf '%s' "$group" | sed 's/[&|\\]/\\&/g')"
    # ProtectHome=true hides /home and /root from the services, which would make an application
    # directory below them unreachable. Fall back to read-only there (ReadWritePaths still opens
    # uploads/ and data/).
    protect_home="true"
    case "$app_dir" in
        /home/*|/root/*)
            protect_home="read-only"
            warn "$app_dir is below /home or /root: using ProtectHome=read-only."
            warn "A directory such as /var/www/filehost is recommended."
            ;;
    esac
    for unit in filehost-upload filehost-mail-worker; do
        # Rendered beside the target and then moved over it, so a failure keeps the old unit.
        if ! sed -e "s|__APP_DIR__|$escaped_app_dir|g" \
                -e "s|__USER__|$escaped_user|g" \
                -e "s|__GROUP__|$escaped_group|g" \
                -e "s|^ProtectHome=true\$|ProtectHome=$protect_home|" \
                "$template_dir/$unit.service" > "$target_dir/$unit.service.new" \
            || ! mv -f "$target_dir/$unit.service.new" "$target_dir/$unit.service"; then
            rm -f "$target_dir/$unit.service.new"
            warn "Could not render $unit.service from $template_dir; the installed unit is unchanged."
            return 1
        fi
    done
}

# ProtectSystem=strict makes everything read-only except uploads/ and data/. Storage moved out of
# the application directory (docs/STORAGE.md) therefore has to be opened explicitly; derive that
# from config/config.local.php so an update cannot silently turn uploads into a read-only error.
write_storage_dropin() {
    local app_dir="$1" dropin_dir="$2"
    local dropin="$dropin_dir/10-storage.conf" constant value
    local paths=()
    if [[ -f "$app_dir/config/config.local.php" ]]; then
        for constant in UPLOADS_PATH DATA_PATH FILE_QUARANTINE_PATH; do
            value="$(sed -n "s/^[[:space:]]*define([[:space:]]*['\"]${constant}['\"][[:space:]]*,[[:space:]]*['\"]\\([^'\"]*\\)['\"].*/\\1/p" \
                "$app_dir/config/config.local.php" | head -n 1)"
            if [[ "$value" == /* ]]; then
                paths+=("-$value")
            elif [[ -n "$value" ]]; then
                # Relative paths resolve from the project root.
                paths+=("-$app_dir/${value#./}")
            fi
        done
    fi
    if (( ${#paths[@]} > 0 )); then
        install -d "$dropin_dir"
        {
            echo "# Written by scripts/install-debian.sh from config/config.local.php; do not edit."
            echo "[Service]"
            echo "ReadWritePaths=${paths[*]}"
        } > "$dropin"
        log "Storage outside the application directory: ${paths[*]}"
    elif [[ -f "$dropin" ]] && head -n 1 "$dropin" | grep -q 'Written by scripts/install-debian.sh'; then
        rm -f "$dropin"
        rmdir "$dropin_dir" 2>/dev/null || true
    fi
}

# Packages and Apache modules; a re-run installs only what is missing.
install_packages() {
    local configured="$1" sapi
    local packages=(apache2 ca-certificates curl
        php php-cli php-mysql php-curl php-mbstring php-gd php-xml
        python3 python3-venv)
    local modules=(proxy proxy_http rewrite headers expires)
    if want_local_database "${FILEHOST_INSTALL_DB:-}" "$configured"; then
        packages+=(mariadb-server)
    else
        log "Not installing mariadb-server (FILEHOST_INSTALL_DB=0, or a configured installation without it)."
    fi
    sapi="${FILEHOST_PHP_SAPI:-$(detect_php_sapi)}"
    if [[ "$sapi" == "fpm" ]]; then
        packages+=(php-fpm)
        modules+=(proxy_fcgi setenvif)
    else
        packages+=(libapache2-mod-php)
    fi
    log "PHP runs through ${sapi} (FILEHOST_PHP_SAPI=fpm|mod_php overrides this)."

    log "Installing missing Apache, PHP, database and Python prerequisites..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    # --no-upgrade: installing what is missing must not upgrade, and restart, an installed
    # package such as mariadb-server in the middle of a release.
    apt-get install -y --no-upgrade "${packages[@]}"
    a2enmod "${modules[@]}"
}

main() {
    set -euo pipefail

    if [[ "${EUID}" -ne 0 ]]; then
        warn "Run this script as root (sudo bash scripts/install-debian.sh)."
        exit 1
    fi

    local app_dir units_only configured installed_user account service_user service_group take_over
    app_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
    cd "$app_dir"
    units_only="${FILEHOST_UNITS_ONLY:-0}"
    configured=0
    if [[ -f "$app_dir/config/config.local.php" ]]; then
        configured=1
    fi

    installed_user="$(unit_value "$UPLOAD_UNIT" User)"
    account="$(resolve_account "$UPLOAD_UNIT" "${FILEHOST_SERVICE_USER:-}" "${FILEHOST_SERVICE_GROUP:-}" \
        "${FILEHOST_CHANGE_ACCOUNT:-0}")"
    service_user="${account% *}"
    service_group="${account#* }"
    if ! id "$service_user" >/dev/null 2>&1; then
        warn "Service user '$service_user' does not exist."
        exit 1
    fi
    if [[ "$units_only" == "1" && -z "$installed_user" ]]; then
        warn "FILEHOST_UNITS_ONLY=1 updates an installed copy, and $UPLOAD_UNIT does not exist."
        warn "Run the installer once without it."
        exit 1
    fi
    if [[ -n "$installed_user" ]]; then
        log "Service account ${service_user}:${service_group}, as the installed units use."
    else
        log "Service account ${service_user}:${service_group}."
    fi

    if [[ "$units_only" != "1" ]]; then
        install_packages "$configured"

        # The hash-locked environment is compiled for Python 3.11 and newer.
        if ! python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 11) else 1)'; then
            warn "Python 3.11 or newer is required (found $(python3 -V 2>&1))."
            exit 1
        fi
        log "Creating the verified Python environment..."
        python3 -m venv "$app_dir/venv"
        "$app_dir/venv/bin/python" -m pip install --require-hashes -r "$app_dir/requirements-lock.txt"

        log "Preparing private runtime directories..."
        take_over=0
        if [[ -z "$installed_user" || "${FILEHOST_CHANGE_ACCOUNT:-0}" == "1" ]]; then
            take_over=1
        fi
        prepare_directories "$app_dir" "$service_user" "$service_group" "$take_over"
    fi

    log "Installing systemd units..."
    render_units "$app_dir" "$service_user" "$service_group" /etc/systemd/system "$app_dir/scripts"
    write_storage_dropin "$app_dir" /etc/systemd/system/filehost-upload.service.d

    if [[ "$units_only" != "1" && ! -e /etc/default/filehost ]]; then
        printf '%s\n' \
            '# TryHackX Files service environment' \
            '# Set to 1 to suppress routine HTTP and worker output while retaining warnings/errors.' \
            'FILEHOST_MINIMAL_LOGS=0' \
            '# Where the "Local mail server" e-mail method submits. Uncomment only when a local' \
            '# MTA does not listen on 127.0.0.1:25.' \
            '#FILEHOST_LOCAL_MTA=127.0.0.1:25' \
            > /etc/default/filehost
        chmod 0640 /etc/default/filehost
        chown root:"$service_group" /etc/default/filehost
    fi

    systemctl daemon-reload
    systemctl enable filehost-upload.service filehost-mail-worker.service

    if [[ "$configured" == "1" ]]; then
        systemctl restart filehost-upload.service filehost-mail-worker.service
        log "Services started. Check them with:"
        echo "  systemctl --no-pager --full status filehost-upload filehost-mail-worker"
    else
        log "Host dependencies and services are installed."
        log "Configure an Apache virtual host with DocumentRoot $app_dir/public,"
        log "Complete the web installer, then run:"
        echo "  systemctl start filehost-upload filehost-mail-worker"
    fi

    if [[ "$units_only" != "1" ]]; then
        log "Reloading Apache..."
        systemctl reload apache2
    fi
}

# tests/shell/install-debian-test.sh sources this file for the functions above; run, it installs.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    main "$@"
fi
