#!/usr/bin/env bash
set -Eeuo pipefail
# A failure inside $(...) must stop the script on the line that caused it. Without this,
# bash clears errexit in command substitutions: a refused upload turned into an empty id
# and the run died much later on an unrelated SQL wait.
shopt -s inherit_errexit

smoke_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tests/smoke/gh-annotate.sh
. "${smoke_dir}/gh-annotate.sh"
SMOKE_FILE="tests/smoke/docker-transfer.sh"
failure_reported=0

# This script only ever runs inside the CI container stack, where nobody can attach to it and
# the job log needs a GitHub login. Every failure therefore says what was checked, what was
# expected and what came back - on stderr and as a public check-run annotation.
fail() {
  local label="$1" detail="$2" line="${3:-${BASH_LINENO[0]}}"
  echo "${label}: ${detail}" >&2
  gh_annotate_error "Docker smoke: ${label}" "${detail}" "${SMOKE_FILE}" "${line}"
  failure_reported=1
  exit 1
}

on_error() {
  local status=$? line="$1" command="$2"
  echo "docker-transfer.sh FAILED at line ${line}: ${command} (exit ${status})" >&2
  # A failing $(...) reports again from the line that used it; annotate that one only.
  if (( BASH_SUBSHELL == 0 )) && [[ "${failure_reported}" -eq 0 ]]; then
    failure_reported=1
    gh_annotate_error "Docker smoke failed at line ${line} (exit ${status})" "${command}" \
      "${SMOKE_FILE}" "${line}"
  fi
}
trap 'on_error "${LINENO}" "${BASH_COMMAND}"' ERR

: "${FILEHOST_CANONICAL_URL:?FILEHOST_CANONICAL_URL is required}"
: "${MYSQL_DATABASE:?MYSQL_DATABASE is required}"
: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD is required}"

work_dir="$(mktemp -d)"
on_exit() {
  local status=$?
  if [[ "${status}" -ne 0 ]]; then
    gh_annotate_compose_logs "Docker smoke: container log tail" app db
  fi
  rm -rf -- "${work_dir}"
}
trap on_exit EXIT

cookie_jar="${work_dir}/cookies.txt"
home_html="${work_dir}/home.html"

# Compare one value and say what was expected when it does not match.
expect() {
  local label="$1" expected="$2" actual="$3"
  if [[ "${actual}" != "${expected}" ]]; then
    fail "${label}" "expected '${expected}', got '${actual}'" "${BASH_LINENO[0]}"
  fi
}

json_field() {
  local field="$1"
  python3 -c \
    'import json,sys; value=json.load(sys.stdin).get(sys.argv[1], ""); print(value)' \
    "${field}"
}

sql() {
  docker compose exec --no-TTY \
    -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" \
    db mysql --batch --skip-column-names --user=root "${MYSQL_DATABASE}" \
    --execute "$1"
}

# One HTTP exchange; leaves HTTP_STATUS and the start of HTTP_BODY for the caller to judge.
http_call() {
  local body_file="${work_dir}/http-body"
  : >"${body_file}"
  HTTP_STATUS="$(curl --silent --show-error --output "${body_file}" \
    --write-out '%{http_code}' "$@" || true)"
  HTTP_BODY="$(head -c 2000 "${body_file}" || true)"
}

require_status() {
  local label="$1" expected="$2" line="$3"
  if [[ "${HTTP_STATUS}" != "${expected}" ]]; then
    fail "${label}" "expected HTTP ${expected}, got ${HTTP_STATUS}: ${HTTP_BODY}" "${line}"
  fi
}

reload_upload_server() {
  docker compose exec --no-TTY app /app/venv/bin/python -c \
    'import urllib.request; print(urllib.request.urlopen("http://127.0.0.1:8001/reload", timeout=5).status)'
}

wait_ready() {
  local status=""
  for _attempt in {1..60}; do
    status="$(docker compose exec --no-TTY app /app/venv/bin/python -c \
      'import urllib.request; print(urllib.request.urlopen("http://127.0.0.1:8001/ready", timeout=2).status)' \
      2>/dev/null || true)"
    if [[ "${status}" == "200" ]]; then
      return 0
    fi
    sleep 1
  done
  fail "upload server readiness" "/ready never answered 200 (last: '${status}')" "${BASH_LINENO[0]}"
}

download_metric() {
  docker compose exec --no-TTY app /app/venv/bin/python -c \
    'import urllib.request
body = urllib.request.urlopen("http://127.0.0.1:8001/metrics", timeout=5).read().decode()
print(next(line.split()[1] for line in body.splitlines() if line.startswith("filehost_downloads_total ")))'
}

# The guest group's per-session file cap (guest_max_files, 5 by default) applies to every
# upload token, so a fresh token is fetched before the cap would refuse the next upload.
UPLOADS_PER_TOKEN=5
upload_token=""
uploads_on_token=0

fresh_upload_token() {
  local line="$1"
  http_call --cookie "${cookie_jar}" --cookie-jar "${cookie_jar}" \
    --header "X-CSRF-Token: ${csrf_token}" \
    --header 'Content-Type: application/json' \
    --request POST --data '{}' \
    "${FILEHOST_CANONICAL_URL}/api.php?action=verify_captcha"
  require_status "verify_captcha" 200 "${line}"
  upload_token="$(printf '%s' "${HTTP_BODY}" | json_field token)"
  [[ -n "${upload_token}" ]] || fail "verify_captcha" "no upload token in: ${HTTP_BODY}" "${line}"
  uploads_on_token=0
}

# upload_file <path> <name>  ->  UPLOADED_ID
upload_file() {
  local path="$1" filename="$2" line="${BASH_LINENO[0]}"
  if [[ -z "${upload_token}" || "${uploads_on_token}" -ge "${UPLOADS_PER_TOKEN}" ]]; then
    fresh_upload_token "${line}"
  fi
  http_call \
    --header "X-Filename: ${filename}" \
    --header "X-Upload-Token: ${upload_token}" \
    --data-binary "@${path}" \
    "${FILEHOST_CANONICAL_URL}/api/upload"
  require_status "upload ${filename}" 200 "${line}"
  uploads_on_token=$((uploads_on_token + 1))
  UPLOADED_ID="${HTTP_BODY%%:*}"
  [[ "${UPLOADED_ID}" =~ ^[A-Za-z0-9_-]{1,32}$ ]] \
    || fail "upload ${filename}" "no file id in response: ${HTTP_BODY}" "${line}"
}

# get_download_token <file id>  ->  DOWNLOAD_TOKEN
get_download_token() {
  local file_id="$1" line="${BASH_LINENO[0]}"
  http_call --cookie "${cookie_jar}" --cookie-jar "${cookie_jar}" \
    --header "X-CSRF-Token: ${csrf_token}" \
    --header 'Content-Type: application/json' \
    --request POST --data "{\"id\":\"${file_id}\"}" \
    "${FILEHOST_CANONICAL_URL}/api.php?action=get_download_token"
  require_status "get_download_token ${file_id}" 200 "${line}"
  DOWNLOAD_TOKEN="$(printf '%s' "${HTTP_BODY}" | json_field token)"
  [[ -n "${DOWNLOAD_TOKEN}" ]] \
    || fail "get_download_token ${file_id}" "no token in: ${HTTP_BODY}" "${line}"
}

wait_for_value() {
  local query="$1"
  local expected="$2"
  local actual=""
  for _attempt in {1..60}; do
    actual="$(sql "${query}")"
    if [[ "${actual}" == "${expected}" ]]; then
      return 0
    fi
    sleep 0.25
  done
  fail "SQL wait" "expected '${expected}', got '${actual}' for: ${query}" "${BASH_LINENO[0]}"
}

[[ -f "${smoke_dir}/abort-before-body.py" ]] \
  || fail "smoke helper" "tests/smoke/abort-before-body.py is missing from the checkout"

curl --fail --silent --show-error --cookie-jar "${cookie_jar}" \
  --output "${home_html}" "${FILEHOST_CANONICAL_URL}/"
csrf_token="$(sed -n 's/.*<meta name="csrf-token" content="\([^"]*\)".*/\1/p' \
  "${home_html}" | head -n1)"
[[ -n "${csrf_token}" ]] || fail "home page" "no csrf-token meta tag on ${FILEHOST_CANONICAL_URL}/"

# Basic public transfer through Apache, including one-time download-token consumption.
basic_payload="${work_dir}/basic.txt"
basic_download="${work_dir}/basic.download"
printf 'FileHost Docker CI transfer smoke\n' > "${basic_payload}"
upload_file "${basic_payload}" 'ci-basic.txt'
basic_id="${UPLOADED_ID}"

uploaded_ip="$(sql "SELECT uploaded_ip FROM fh_files WHERE id='${basic_id}'")"
python3 -c \
  'import ipaddress,sys; value=ipaddress.ip_address(sys.argv[1]); assert not value.is_loopback' \
  "${uploaded_ip}" \
  || fail "uploaded_ip" "expected the client address Apache saw, got '${uploaded_ip}'"

get_download_token "${basic_id}"
basic_token="${DOWNLOAD_TOKEN}"
basic_status="$(curl --silent --show-error --output "${basic_download}" --write-out '%{http_code}' \
  "${FILEHOST_CANONICAL_URL}/api/download?token=${basic_token}")"
expect "a basic download" "200" "${basic_status}"
cmp "${basic_payload}" "${basic_download}" \
  || fail "a basic download" "the downloaded bytes differ from the upload"
reuse_status="$(curl --silent --output /dev/null --write-out '%{http_code}' \
  "${FILEHOST_CANONICAL_URL}/api/download?token=${basic_token}")"
expect "a consumed download token is refused" "403" "${reuse_status}"

# Slow downloads make concurrency and disconnect states observable without a race against
# the unthrottled streaming path. Five seconds is used only by this isolated stack, never as a
# production default.
#
# The throttle lives on the guest *group*, not on the flat setting. `limit_download_guest` was
# copied into `groups.limit_download` once, by the migration that introduced groups, and the
# sidecar has read the group row ever since; the panel keeps the old key in sync only for
# legacy readers. Writing just the setting left the throttle at zero, so every assertion below
# that needs a transfer still in flight raced against a payload delivered in one burst.
sql "UPDATE fh_groups SET limit_download=131072 WHERE slug='guest'"
sql "INSERT INTO fh_settings (setting_key, setting_value) VALUES
  ('limit_download_guest','131072')
  ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
expect "the guest group carries the test throttle" "131072" \
  "$(sql "SELECT limit_download FROM fh_groups WHERE slug='guest'")"
reload_upload_server >/dev/null

large_payload="${work_dir}/large.bin"
python3 -c \
  'from pathlib import Path; Path(__import__("sys").argv[1]).write_bytes(b"FH-CI-" * 87382)' \
  "${large_payload}"

# Cap=1: while one transfer owns the active counter effect, a second token must receive 410.
upload_file "${large_payload}" 'ci-cap.bin'
cap_id="${UPLOADED_ID}"
sql "UPDATE fh_files SET max_downloads=1 WHERE id='${cap_id}'"
get_download_token "${cap_id}"
cap_token_a="${DOWNLOAD_TOKEN}"
get_download_token "${cap_id}"
cap_token_b="${DOWNLOAD_TOKEN}"
cap_status_file="${work_dir}/cap-a.status"
curl --silent --show-error --output "${work_dir}/cap-a.bin" --write-out '%{http_code}' \
  "${FILEHOST_CANONICAL_URL}/api/download?token=${cap_token_a}" >"${cap_status_file}" &
cap_curl_pid=$!
wait_for_value \
  "SELECT COUNT(*) FROM fh_download_reservations WHERE resource_id='${cap_id}' AND state='started'" \
  "1"
cap_second_status="$(curl --silent --output /dev/null --write-out '%{http_code}' \
  "${FILEHOST_CANONICAL_URL}/api/download?token=${cap_token_b}")"
expect "a second token while the cap is held" "410" "${cap_second_status}"
# Judged by its HTTP status and bytes below, which say more than curl's exit code.
wait "${cap_curl_pid}" || true
expect "the capped download itself" "200" "$(cat "${cap_status_file}")"
cmp "${large_payload}" "${work_dir}/cap-a.bin" \
  || fail "the capped download itself" "the downloaded bytes differ from the upload"
wait_for_value "SELECT downloads FROM fh_files WHERE id='${cap_id}'" "1"
expect "the refused token stays unused" "0" \
  "$(sql "SELECT used FROM fh_download_tokens WHERE token='${cap_token_b}'")"

# A client disconnect in the middle of a full response consumes its capability but does not
# increment the durable file counter. The reservation must settle as released.
upload_file "${large_payload}" 'ci-partial.bin'
partial_id="${UPLOADED_ID}"
get_download_token "${partial_id}"
partial_token="${DOWNLOAD_TOKEN}"
partial_exit=0
curl --silent --show-error --max-time 1 --output "${work_dir}/partial.bin" \
  "${FILEHOST_CANONICAL_URL}/api/download?token=${partial_token}" || partial_exit=$?
if [[ "${partial_exit}" -eq 0 ]]; then
  fail "an interrupted download" "it was not interrupted: curl exited 0"
fi
wait_for_value \
  "SELECT state FROM fh_download_reservations WHERE resource_id='${partial_id}' ORDER BY created_at DESC LIMIT 1" \
  "released"
expect "an interrupted download still consumes its token" "1" \
  "$(sql "SELECT used FROM fh_download_tokens WHERE token='${partial_token}'")"
expect "an interrupted download does not count" "0" \
  "$(sql "SELECT downloads FROM fh_files WHERE id='${partial_id}'")"

# The same rule applies to an interrupted Range. A later complete Range is one completed
# download and increments the counter exactly once.
upload_file "${large_payload}" 'ci-range.bin'
range_id="${UPLOADED_ID}"
get_download_token "${range_id}"
range_partial_token="${DOWNLOAD_TOKEN}"
range_exit=0
curl --silent --show-error --max-time 1 --range 0-262143 \
  --output "${work_dir}/range-partial.bin" \
  "${FILEHOST_CANONICAL_URL}/api/download?token=${range_partial_token}" || range_exit=$?
if [[ "${range_exit}" -eq 0 ]]; then
  fail "an interrupted Range download" "it was not interrupted: curl exited 0"
fi
wait_for_value \
  "SELECT state FROM fh_download_reservations WHERE resource_id='${range_id}' ORDER BY created_at DESC LIMIT 1" \
  "released"
expect "an interrupted Range does not count" "0" \
  "$(sql "SELECT downloads FROM fh_files WHERE id='${range_id}'")"

# Close a real uvicorn socket while the reservation is committed but before the first response
# body is offered to ASGI. The test-only delay is enabled only in this isolated CI container.
# The helper is streamed in on stdin: the image deliberately ships without tests/.
upload_file "${large_payload}" 'ci-prebody.bin'
prebody_id="${UPLOADED_ID}"
sql "UPDATE fh_files SET one_time=1 WHERE id='${prebody_id}'"
get_download_token "${prebody_id}"
prebody_token="${DOWNLOAD_TOKEN}"
sql "UPDATE fh_download_tokens SET ip_address='127.0.0.1' WHERE token='${prebody_token}'"
prebody_metric_before="$(download_metric)"
docker compose exec --no-TTY app /app/venv/bin/python - \
  "http://127.0.0.1:8001/download?token=${prebody_token}" 1.5 \
  < "${smoke_dir}/abort-before-body.py" &
prebody_client_pid=$!
wait_for_value \
  "SELECT state FROM fh_download_reservations WHERE resource_id='${prebody_id}' ORDER BY created_at DESC LIMIT 1" \
  "reserved"
wait "${prebody_client_pid}"
wait_for_value \
  "SELECT state FROM fh_download_reservations WHERE resource_id='${prebody_id}' ORDER BY created_at DESC LIMIT 1" \
  "released"
expect "a pre-body abort leaves the token unused" "0" \
  "$(sql "SELECT used FROM fh_download_tokens WHERE token='${prebody_token}'")"
expect "a pre-body abort does not count" "0" \
  "$(sql "SELECT downloads FROM fh_files WHERE id='${prebody_id}'")"
expect "a pre-body abort releases the one-time claim" "1" \
  "$(sql "SELECT consumed_at IS NULL AND consume_reservation_id IS NULL FROM fh_files WHERE id='${prebody_id}'")"
expect "a pre-body abort leaves no active row" "0" \
  "$(sql "SELECT COUNT(*) FROM fh_active_downloads WHERE file_id='${prebody_id}'")"
expect "a pre-body abort does not move the metric" "${prebody_metric_before}" "$(download_metric)"

get_download_token "${range_id}"
range_complete_token="${DOWNLOAD_TOKEN}"
range_complete_status="$(curl --silent --show-error --range 0-262143 \
  --output "${work_dir}/range-complete.bin" --write-out '%{http_code}' \
  "${FILEHOST_CANONICAL_URL}/api/download?token=${range_complete_token}")"
expect "a complete Range request" "206" "${range_complete_status}"
expect "the Range payload size" "262144" "$(wc -c <"${work_dir}/range-complete.bin" | tr -d ' ')"
wait_for_value "SELECT downloads FROM fh_files WHERE id='${range_id}'" "1"

# Kill the uvicorn process after its first body bytes. No finally block can run; after the
# short test lease expires, startup reaping must release the active row idempotently while
# retaining the already-consumed token and one-time claim. This is the sixth upload, so it
# also proves the per-token cap is handled by rotating the upload token.
upload_file "${large_payload}" 'ci-crash.bin'
crash_id="${UPLOADED_ID}"
sql "UPDATE fh_files SET one_time=1 WHERE id='${crash_id}'"
get_download_token "${crash_id}"
crash_token="${DOWNLOAD_TOKEN}"
curl --silent --show-error --output "${work_dir}/crash.bin" \
  "${FILEHOST_CANONICAL_URL}/api/download?token=${crash_token}" &
crash_curl_pid=$!
wait_for_value \
  "SELECT COUNT(*) FROM fh_download_reservations WHERE resource_id='${crash_id}' AND state='started'" \
  "1"
# supervisord is started with -c on the file below, which is also where its control socket is
# declared; supervisorctl's default config search would look for Debian's socket instead.
worker_pid="$(docker compose exec --no-TTY app \
  supervisorctl -c /etc/supervisor/conf.d/filehost.conf pid upload | tr -d '\r')"
if [[ ! "${worker_pid}" =~ ^[0-9]+$ ]] || (( worker_pid <= 1 )); then
  fail "supervisorctl pid upload" "no upload worker pid: '${worker_pid}'"
fi
# The shell builtin, so the image does not need procps for a `kill` binary.
docker compose exec --no-TTY --user root app sh -c 'kill -9 "$1"' sh "${worker_pid}"
wait "${crash_curl_pid}" || true

sleep 7
docker compose restart app
wait_ready
wait_for_value \
  "SELECT state FROM fh_download_reservations WHERE resource_id='${crash_id}' ORDER BY created_at DESC LIMIT 1" \
  "released"
expect "a crashed transfer keeps its token consumed" "1" \
  "$(sql "SELECT used FROM fh_download_tokens WHERE token='${crash_token}'")"
expect "a crashed transfer does not count" "0" \
  "$(sql "SELECT downloads FROM fh_files WHERE id='${crash_id}'")"
expect "startup reaping clears the active row" "0" \
  "$(sql "SELECT COUNT(*) FROM fh_active_downloads WHERE file_id='${crash_id}'")"
expect "a crashed transfer keeps the one-time claim" "1" \
  "$(sql "SELECT consumed_at IS NOT NULL FROM fh_files WHERE id='${crash_id}'")"

before_reap="$(sql "SELECT CONCAT(state,':',bytes_sent) FROM fh_download_reservations
  WHERE resource_id='${crash_id}' ORDER BY created_at DESC LIMIT 1")"
docker compose restart app
wait_ready
after_reap="$(sql "SELECT CONCAT(state,':',bytes_sent) FROM fh_download_reservations
  WHERE resource_id='${crash_id}' ORDER BY created_at DESC LIMIT 1")"
expect "reaping is idempotent across restarts" "${before_reap}" "${after_reap}"

echo "Docker transfer and interrupted-download smoke passed."
