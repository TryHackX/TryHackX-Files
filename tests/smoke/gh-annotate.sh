#!/usr/bin/env bash
# GitHub Actions error annotations for the Docker smoke.
#
# A job log on GitHub can only be read while signed in, but the annotations a run produces
# are public: GET /repos/{owner}/{repo}/check-runs/{id}/annotations answers without a token.
# So every smoke failure is also emitted as an `::error` workflow command - what was checked,
# what was expected, what came back, and a filtered tail of the container logs.
#
# Source it for the functions, or run it:
#   gh-annotate.sh error "<title>" "<message>" [file] [line]
#   gh-annotate.sh logs  "<title>" [compose service...]
#
# Outside GitHub Actions nothing extra is printed. GitHub keeps at most 10 error
# annotations per step, so callers emit one per failure plus one log tail.

GH_ANNOTATION_MAX_CHARS=3500

# Workflow-command data: %, CR and LF must be escaped or the message is cut at the first line.
gh_escape_data() {
  local value="$1"
  value="${value//'%'/'%25'}"
  value="${value//$'\r'/}"
  value="${value//$'\n'/'%0A'}"
  printf '%s' "${value}"
}

# Properties (title, file) additionally reserve ':' and ','.
gh_escape_property() {
  local value
  value="$(gh_escape_data "$1")"
  value="${value//':'/'%3A'}"
  value="${value//','/'%2C'}"
  printf '%s' "${value}"
}

# gh_annotate_error <title> <message> [file] [line]
gh_annotate_error() {
  [[ -n "${GITHUB_ACTIONS:-}" ]] || return 0
  local title="$1" message="$2" file="${3:-}" line="${4:-}"
  local location=""
  if [[ -n "${file}" ]]; then
    location="file=$(gh_escape_property "${file}"),"
    if [[ "${line}" =~ ^[0-9]+$ ]]; then
      location+="line=${line},"
    fi
  fi
  message="${message:0:${GH_ANNOTATION_MAX_CHARS}}"
  printf '::error %stitle=%s::%s\n' \
    "${location}" "$(gh_escape_property "${title}")" "$(gh_escape_data "${message}")"
}

# gh_annotate_compose_logs <title> [service...]
# The interesting lines of the recent container output: errors, tracebacks, warnings. When
# nothing matches, the plain tail is sent instead, so the annotation is never empty.
gh_annotate_compose_logs() {
  [[ -n "${GITHUB_ACTIONS:-}" ]] || return 0
  local title="$1"
  shift
  local logs filtered
  logs="$(docker compose logs --no-color --tail=300 "$@" 2>&1 || true)"
  filtered="$(printf '%s\n' "${logs}" \
    | grep -E 'Traceback|Error|error|FAILED|failed|refused|denied|Warning|warning|CRIT|exited|⚠|❌' \
    | tail -n 40 || true)"
  if [[ -z "${filtered}" ]]; then
    filtered="$(printf '%s\n' "${logs}" | tail -n 25)"
  fi
  # Keep the end of the tail, which is closest to the failure.
  if (( ${#filtered} > GH_ANNOTATION_MAX_CHARS )); then
    filtered="…${filtered: -${GH_ANNOTATION_MAX_CHARS}}"
  fi
  gh_annotate_error "${title}" "${filtered}"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  set -uo pipefail
  case "${1:-}" in
    error)
      gh_annotate_error "${2:-Docker smoke failed}" "${3:-}" "${4:-}" "${5:-}"
      ;;
    logs)
      title="${2:-Docker smoke: container log tail}"
      shift 2 || shift $#
      gh_annotate_compose_logs "${title}" "$@"
      ;;
    *)
      echo "usage: $0 error <title> <message> [file] [line] | logs <title> [service...]" >&2
      exit 64
      ;;
  esac
fi
