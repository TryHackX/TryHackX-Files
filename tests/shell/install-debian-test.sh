#!/usr/bin/env bash
# What scripts/install-debian.sh decides on a re-run, checked with a fixture unit file and
# stand-ins for the package and Apache queries. Nothing is installed: every file goes to a
# temporary directory. CI runs it next to ShellCheck:
#   bash tests/shell/install-debian-test.sh
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo="$(cd "$here/../.." && pwd)"
fixtures="$here/fixtures"
# Sourcing defines the installer's functions without running it.
# shellcheck source=scripts/install-debian.sh
source "$repo/scripts/install-debian.sh"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

failures=0
check() {
    local description="$1" expected="$2" actual="$3"
    if [[ "$actual" == "$expected" ]]; then
        echo "ok   - $description"
    else
        echo "FAIL - $description: expected '$expected', got '$actual'"
        failures=$((failures + 1))
    fi
}

# "yes" when the command succeeds, "no" when it fails.
outcome() {
    if "$@" >/dev/null 2>&1; then
        echo yes
    else
        echo no
    fi
}

# Stand-ins for the two queries; the variables say what they report.
FPM_STATUS=''
MARIADB_STATUS=''
APACHE_MODULES=''
dpkg-query() {
    case "${*: -1}" in
        mariadb-server) printf '%s' "$MARIADB_STATUS" ;;
        *) printf '%s\n' "$FPM_STATUS" ;;
    esac
}
a2query() {
    printf '%s\n' "$APACHE_MODULES"
}

dedicated="$fixtures/filehost-upload.dedicated.service"
missing="$work/no-such.service"

echo "# service account"
check "a re-run keeps the dedicated account of the installed unit" "filehost filehost" \
    "$(resolve_account "$dedicated" "" "" 0)"
check "naming the installed account again is accepted" "filehost filehost" \
    "$(resolve_account "$dedicated" filehost filehost 0)"
check "another account than the installed one is refused" no \
    "$(outcome resolve_account "$dedicated" www-data www-data 0)"
check "FILEHOST_CHANGE_ACCOUNT=1 makes that change explicit" "www-data www-data" \
    "$(resolve_account "$dedicated" www-data www-data 1)"
check "a new installation defaults to www-data" "www-data www-data" \
    "$(resolve_account "$missing" "" "" 0)"
check "a new installation takes the requested account" "filehost filehost" \
    "$(resolve_account "$missing" filehost filehost 0)"
check "commented-out keys are ignored" "filehost" "$(unit_value "$dedicated" User)"

echo "# PHP handler"
FPM_STATUS='install ok installed'
APACHE_MODULES=$'mpm_event (enabled by maintainer script)\nproxy_fcgi (enabled by site administrator)'
check "php-fpm installed and no PHP module in Apache: FPM is kept" fpm "$(detect_php_sapi)"
APACHE_MODULES=$'mpm_prefork (enabled by maintainer script)\nphp8.4 (enabled by maintainer script)'
check "a PHP module enabled in Apache: mod_php" mod_php "$(detect_php_sapi)"
FPM_STATUS='deinstall ok config-files'
APACHE_MODULES='mpm_event (enabled by maintainer script)'
check "a removed php-fpm does not count" mod_php "$(detect_php_sapi)"
FPM_STATUS=''
APACHE_MODULES=''
check "a machine without PHP or Apache yet: the documented default" mod_php "$(detect_php_sapi)"

echo "# local database"
MARIADB_STATUS=''
check "a new installation gets MariaDB" yes "$(outcome want_local_database "" 0)"
check "a configured installation without MariaDB does not get one" no "$(outcome want_local_database "" 1)"
MARIADB_STATUS='install ok installed'
check "a configured installation keeps its MariaDB" yes "$(outcome want_local_database "" 1)"
check "FILEHOST_INSTALL_DB=0 skips it" no "$(outcome want_local_database 0 0)"
MARIADB_STATUS=''
check "FILEHOST_INSTALL_DB=1 installs it" yes "$(outcome want_local_database 1 1)"

echo "# rendered units"
read -r kept_user kept_group <<<"$(resolve_account "$dedicated" "" "" 0)"
mkdir -p "$work/units"
render_units /var/www/filehost "$kept_user" "$kept_group" "$work/units" "$repo/scripts"
check "the re-rendered upload unit keeps User=" filehost "$(unit_value "$work/units/filehost-upload.service" User)"
check "the re-rendered mail unit keeps Group=" filehost "$(unit_value "$work/units/filehost-mail-worker.service" Group)"
check "the application directory is filled in" /var/www/filehost \
    "$(unit_value "$work/units/filehost-upload.service" WorkingDirectory)"
check "no placeholder is left" 0 "$(cat "$work"/units/*.service | grep -c '__[A-Z_]*__' || true)"
render_units /home/alice/filehost filehost filehost "$work/units" "$repo/scripts" 2>/dev/null
check "below /home the unit keeps the home directory readable" read-only \
    "$(unit_value "$work/units/filehost-upload.service" ProtectHome)"
mkdir -p "$work/installed"
printf 'old\n' > "$work/installed/filehost-upload.service"
check "rendering without the templates fails" no \
    "$(outcome render_units /var/www/filehost filehost filehost "$work/installed" "$work/no-templates")"
check "and leaves the installed unit as it was" old "$(cat "$work/installed/filehost-upload.service")"

echo "# storage drop-in"
mkdir -p "$work/site/config"
printf '%s\n' '<?php' "define('UPLOADS_PATH', '/mnt/storage/uploads');" "define('DATA_PATH', 'data2');" \
    > "$work/site/config/config.local.php"
write_storage_dropin "$work/site" "$work/dropin" >/dev/null
check "storage outside the application directory is opened" \
    "ReadWritePaths=-/mnt/storage/uploads -$work/site/data2" \
    "$(grep '^ReadWritePaths=' "$work/dropin/10-storage.conf")"
printf '%s\n' '<?php' > "$work/site/config/config.local.php"
write_storage_dropin "$work/site" "$work/dropin" >/dev/null
check "the drop-in is removed when the storage moves back" no "$(outcome test -e "$work/dropin/10-storage.conf")"

echo "# runtime directories"
if [[ "$(uname -s)" == Linux ]]; then
    mkdir -p "$work/app/uploads" "$work/app/data"
    chmod 0755 "$work/app/uploads" "$work/app/data"
    prepare_directories "$work/app" "$(id -un)" "$(id -gn)" 0
    check "a re-run creates a missing config/ privately" 750 "$(stat -c '%a' "$work/app/config")"
    check "a re-run leaves an existing directory as it is" 755 "$(stat -c '%a' "$work/app/uploads")"
    prepare_directories "$work/app" "$(id -un)" "$(id -gn)" 1
    check "a new installation hands the checkout's uploads/ over" 750 "$(stat -c '%a' "$work/app/uploads")"
else
    echo "skip - directory modes need Linux"
fi

if (( failures > 0 )); then
    echo "$failures check(s) failed" >&2
    exit 1
fi
echo "all checks passed"
