#!/usr/bin/env bash
# Switch the mail worker's sandbox between strict and relaxed.
#
# `NoNewPrivileges=true` is what makes the shipped unit safe, and it is also what stops PHP
# `mail()` from reaching Postfix: `postdrop` is setgid, and the flag disarms it. The choice
# belongs to whoever owns the machine — but it cannot be a button in the panel. The kernel
# flag is one-way for the life of a process, so nothing running can clear it; only systemd can
# decide it, at exec time, from a unit file that root owns. Giving the web user the power to
# rewrite that file and restart the service would turn any panel compromise into control of a
# root-owned unit, which is the exact thing the flag is there to prevent.
#
# So this script is the button, and root presses it.
#
#   sudo bash scripts/mail-worker-hardening.sh status
#   sudo bash scripts/mail-worker-hardening.sh relaxed   # PHP mail() can reach postdrop
#   sudo bash scripts/mail-worker-hardening.sh strict     # back to the shipped hardening
set -euo pipefail

UNIT=filehost-mail-worker.service
DROPIN_DIR="/etc/systemd/system/${UNIT}.d"
DROPIN="${DROPIN_DIR}/10-hardening.conf"

usage() {
    cat >&2 <<'USAGE'
usage: mail-worker-hardening.sh <status|strict|relaxed>

  status    report the flag the running worker actually has
  relaxed   allow PHP mail() to use a setgid helper (drops NoNewPrivileges)
  strict    restore the shipped hardening (recommended; use the local mail
            server or an external SMTP server as the sending method)
USAGE
    exit 64
}

require_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        echo "Run this as root: sudo bash scripts/mail-worker-hardening.sh $1" >&2
        exit 1
    fi
}

report() {
    local pid effective
    pid="$(systemctl show "${UNIT}" -p ExecMainPID --value 2>/dev/null || echo 0)"
    effective="$(systemctl show "${UNIT}" -p NoNewPrivileges --value 2>/dev/null || echo '?')"
    echo "unit            : ${UNIT}"
    echo "active          : $(systemctl is-active "${UNIT}" 2>/dev/null || true)"
    echo "NoNewPrivileges : ${effective} (configured)"
    if [[ "${pid}" =~ ^[0-9]+$ ]] && [[ "${pid}" -gt 0 ]] && [[ -r "/proc/${pid}/status" ]]; then
        echo "                  $(grep '^NoNewPrivs:' "/proc/${pid}/status" | tr -s '\t' ' ') (running pid ${pid})"
    fi
    if [[ -f "${DROPIN}" ]]; then
        echo "drop-in         : ${DROPIN}"
        sed 's/^/                  /' "${DROPIN}"
    else
        echo "drop-in         : none (shipped unit as-is)"
    fi
}

apply() {
    systemctl daemon-reload
    systemctl restart "${UNIT}"
    # Long enough for the worker to complete one loop and publish its own snapshot.
    sleep 8
    report
}

case "${1:-}" in
    status)
        report
        ;;
    relaxed)
        require_root relaxed
        mkdir -p "${DROPIN_DIR}"
        cat > "${DROPIN}" <<'CONF'
# Written by scripts/mail-worker-hardening.sh.
#
# NoNewPrivileges is off so PHP mail() can hand messages to a setgid helper such as Postfix's
# postdrop. This is a deliberate trade: the worker loses one sandbox restriction, and a helper
# that cannot write its spool will block the worker instead of returning an error. The watchdog
# in the unit turns that into a visible restart loop rather than silence, but it does not make
# the delivery work. Prefer the local mail server transport, which needs no privilege at all.
[Service]
NoNewPrivileges=no
CONF
        chmod 0644 "${DROPIN}"
        echo "Hardening relaxed for ${UNIT}."
        apply
        ;;
    strict)
        require_root strict
        rm -f "${DROPIN}"
        rmdir "${DROPIN_DIR}" 2>/dev/null || true
        echo "Shipped hardening restored for ${UNIT}."
        apply
        ;;
    *)
        usage
        ;;
esac
