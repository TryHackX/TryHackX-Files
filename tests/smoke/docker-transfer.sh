#!/usr/bin/env bash
set -euo pipefail

: "${FILEHOST_CANONICAL_URL:?FILEHOST_CANONICAL_URL is required}"
: "${MYSQL_DATABASE:?MYSQL_DATABASE is required}"
: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD is required}"

work_dir="$(mktemp -d)"
cleanup() {
  rm -rf -- "${work_dir}"
}
trap cleanup EXIT

cookie_jar="${work_dir}/cookies.txt"
home_html="${work_dir}/home.html"

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
  return 1
}

download_metric() {
  docker compose exec --no-TTY app /app/venv/bin/python -c \
    'import urllib.request
body = urllib.request.urlopen("http://127.0.0.1:8001/metrics", timeout=5).read().decode()
print(next(line.split()[1] for line in body.splitlines() if line.startswith("filehost_downloads_total ")))'
}

get_download_token() {
  local file_id="$1"
  local response
  response="$(curl --fail --silent --show-error \
    --cookie "${cookie_jar}" --cookie-jar "${cookie_jar}" \
    --header "X-CSRF-Token: ${csrf_token}" \
    --header 'Content-Type: application/json' \
    --request POST --data "{\"id\":\"${file_id}\"}" \
    "${FILEHOST_CANONICAL_URL}/api.php?action=get_download_token")"
  printf '%s' "${response}" | json_field token
}

upload_file() {
  local path="$1"
  local filename="$2"
  local result
  result="$(curl --fail --silent --show-error \
    --header "X-Filename: ${filename}" \
    --header "X-Upload-Token: ${upload_token}" \
    --data-binary "@${path}" \
    "${FILEHOST_CANONICAL_URL}/api/upload")"
  printf '%s\n' "${result%%:*}"
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
  echo "Expected SQL value '${expected}', got '${actual}' for: ${query}" >&2
  return 1
}

curl --fail --silent --show-error --cookie-jar "${cookie_jar}" \
  --output "${home_html}" "${FILEHOST_CANONICAL_URL}/"
csrf_token="$(sed -n 's/.*<meta name="csrf-token" content="\([^"]*\)".*/\1/p' \
  "${home_html}" | head -n1)"
test -n "${csrf_token}"

token_json="$(curl --fail --silent --show-error \
  --cookie "${cookie_jar}" --cookie-jar "${cookie_jar}" \
  --header "X-CSRF-Token: ${csrf_token}" \
  --header 'Content-Type: application/json' \
  --request POST --data '{}' \
  "${FILEHOST_CANONICAL_URL}/api.php?action=verify_captcha")"
upload_token="$(printf '%s' "${token_json}" | json_field token)"
test -n "${upload_token}"

# Basic public transfer through Apache, including one-time download-token consumption.
basic_payload="${work_dir}/basic.txt"
basic_download="${work_dir}/basic.download"
printf 'FileHost Docker CI transfer smoke\n' > "${basic_payload}"
basic_id="$(upload_file "${basic_payload}" 'ci-basic.txt')"
test -n "${basic_id}"

uploaded_ip="$(sql "SELECT uploaded_ip FROM fh_files WHERE id='${basic_id}'")"
python3 -c \
  'import ipaddress,sys; value=ipaddress.ip_address(sys.argv[1]); assert not value.is_loopback' \
  "${uploaded_ip}"

basic_token="$(get_download_token "${basic_id}")"
test -n "${basic_token}"
curl --fail --silent --show-error --output "${basic_download}" \
  "${FILEHOST_CANONICAL_URL}/api/download?token=${basic_token}"
cmp "${basic_payload}" "${basic_download}"
reuse_status="$(curl --silent --output /dev/null --write-out '%{http_code}' \
  "${FILEHOST_CANONICAL_URL}/api/download?token=${basic_token}")"
test "${reuse_status}" = "403"

# Slow downloads make concurrency and disconnect states observable without a race against
# the unthrottled streaming path. Five seconds is used only by this isolated stack, never as a production default.
sql "INSERT INTO fh_settings (setting_key, setting_value) VALUES
  ('limit_download_guest','131072')
  ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
reload_upload_server >/dev/null

large_payload="${work_dir}/large.bin"
python3 -c \
  'from pathlib import Path; Path(__import__("sys").argv[1]).write_bytes(b"FH-CI-" * 87382)' \
  "${large_payload}"

# Cap=1: while one transfer owns the active counter effect, a second token must receive 410.
cap_id="$(upload_file "${large_payload}" 'ci-cap.bin')"
sql "UPDATE fh_files SET max_downloads=1 WHERE id='${cap_id}'"
cap_token_a="$(get_download_token "${cap_id}")"
cap_token_b="$(get_download_token "${cap_id}")"
cap_status_file="${work_dir}/cap-a.status"
curl --silent --show-error --output "${work_dir}/cap-a.bin" --write-out '%{http_code}' \
  "${FILEHOST_CANONICAL_URL}/api/download?token=${cap_token_a}" >"${cap_status_file}" &
cap_curl_pid=$!
wait_for_value \
  "SELECT COUNT(*) FROM fh_download_reservations WHERE resource_id='${cap_id}' AND state='started'" \
  "1"
cap_second_status="$(curl --silent --output /dev/null --write-out '%{http_code}' \
  "${FILEHOST_CANONICAL_URL}/api/download?token=${cap_token_b}")"
test "${cap_second_status}" = "410"
wait "${cap_curl_pid}"
test "$(cat "${cap_status_file}")" = "200"
cmp "${large_payload}" "${work_dir}/cap-a.bin"
wait_for_value "SELECT downloads FROM fh_files WHERE id='${cap_id}'" "1"
test "$(sql "SELECT used FROM fh_download_tokens WHERE token='${cap_token_b}'")" = "0"

# A client disconnect in the middle of a full response consumes its capability but does not
# increment the durable file counter. The reservation must settle as released.
partial_id="$(upload_file "${large_payload}" 'ci-partial.bin')"
partial_token="$(get_download_token "${partial_id}")"
set +e
curl --silent --show-error --max-time 1 --output "${work_dir}/partial.bin" \
  "${FILEHOST_CANONICAL_URL}/api/download?token=${partial_token}"
partial_exit=$?
set -e
test "${partial_exit}" -ne 0
wait_for_value \
  "SELECT state FROM fh_download_reservations WHERE resource_id='${partial_id}' ORDER BY created_at DESC LIMIT 1" \
  "released"
test "$(sql "SELECT used FROM fh_download_tokens WHERE token='${partial_token}'")" = "1"
test "$(sql "SELECT downloads FROM fh_files WHERE id='${partial_id}'")" = "0"

# The same rule applies to an interrupted Range. A later complete Range is one completed
# download and increments the counter exactly once.
range_id="$(upload_file "${large_payload}" 'ci-range.bin')"
range_partial_token="$(get_download_token "${range_id}")"
set +e
curl --silent --show-error --max-time 1 --range 0-262143 \
  --output "${work_dir}/range-partial.bin" \
  "${FILEHOST_CANONICAL_URL}/api/download?token=${range_partial_token}"
range_exit=$?
set -e
test "${range_exit}" -ne 0
wait_for_value \
  "SELECT state FROM fh_download_reservations WHERE resource_id='${range_id}' ORDER BY created_at DESC LIMIT 1" \
  "released"
test "$(sql "SELECT downloads FROM fh_files WHERE id='${range_id}'")" = "0"

# Close a real uvicorn socket while the reservation is committed but before the first response
# body is offered to ASGI. The test-only delay is enabled only in this isolated CI container.
prebody_id="$(upload_file "${large_payload}" 'ci-prebody.bin')"
sql "UPDATE fh_files SET one_time=1 WHERE id='${prebody_id}'"
prebody_token="$(get_download_token "${prebody_id}")"
sql "UPDATE fh_download_tokens SET ip_address='127.0.0.1' WHERE token='${prebody_token}'"
prebody_metric_before="$(download_metric)"
docker compose exec --no-TTY app /app/venv/bin/python \
  /app/tests/smoke/abort-before-body.py \
  "http://127.0.0.1:8001/download?token=${prebody_token}" 1.5 &
prebody_client_pid=$!
wait_for_value \
  "SELECT state FROM fh_download_reservations WHERE resource_id='${prebody_id}' ORDER BY created_at DESC LIMIT 1" \
  "reserved"
wait "${prebody_client_pid}"
wait_for_value \
  "SELECT state FROM fh_download_reservations WHERE resource_id='${prebody_id}' ORDER BY created_at DESC LIMIT 1" \
  "released"
test "$(sql "SELECT used FROM fh_download_tokens WHERE token='${prebody_token}'")" = "0"
test "$(sql "SELECT downloads FROM fh_files WHERE id='${prebody_id}'")" = "0"
test "$(sql "SELECT consumed_at IS NULL AND consume_reservation_id IS NULL FROM fh_files WHERE id='${prebody_id}'")" = "1"
test "$(sql "SELECT COUNT(*) FROM fh_active_downloads WHERE file_id='${prebody_id}'")" = "0"
test "$(download_metric)" = "${prebody_metric_before}"

range_complete_token="$(get_download_token "${range_id}")"
range_complete_status="$(curl --fail --silent --show-error --range 0-262143 \
  --output "${work_dir}/range-complete.bin" --write-out '%{http_code}' \
  "${FILEHOST_CANONICAL_URL}/api/download?token=${range_complete_token}")"
test "${range_complete_status}" = "206"
test "$(wc -c <"${work_dir}/range-complete.bin")" = "262144"
wait_for_value "SELECT downloads FROM fh_files WHERE id='${range_id}'" "1"

# Kill the uvicorn process after its first body bytes. No finally block can run; after the
# short test lease expires, startup reaping must release the active row idempotently while
# retaining the already-consumed token and one-time claim.
crash_id="$(upload_file "${large_payload}" 'ci-crash.bin')"
sql "UPDATE fh_files SET one_time=1 WHERE id='${crash_id}'"
crash_token="$(get_download_token "${crash_id}")"
curl --silent --show-error --output "${work_dir}/crash.bin" \
  "${FILEHOST_CANONICAL_URL}/api/download?token=${crash_token}" &
crash_curl_pid=$!
wait_for_value \
  "SELECT COUNT(*) FROM fh_download_reservations WHERE resource_id='${crash_id}' AND state='started'" \
  "1"
worker_pid="$(docker compose exec --no-TTY app supervisorctl pid upload | tr -d '\r')"
test "${worker_pid}" -gt 1
docker compose exec --no-TTY --user root app kill -9 "${worker_pid}"
set +e
wait "${crash_curl_pid}"
set -e

sleep 7
docker compose restart app
wait_ready
wait_for_value \
  "SELECT state FROM fh_download_reservations WHERE resource_id='${crash_id}' ORDER BY created_at DESC LIMIT 1" \
  "released"
test "$(sql "SELECT used FROM fh_download_tokens WHERE token='${crash_token}'")" = "1"
test "$(sql "SELECT downloads FROM fh_files WHERE id='${crash_id}'")" = "0"
test "$(sql "SELECT COUNT(*) FROM fh_active_downloads WHERE file_id='${crash_id}'")" = "0"
test "$(sql "SELECT consumed_at IS NOT NULL FROM fh_files WHERE id='${crash_id}'")" = "1"

before_reap="$(sql "SELECT CONCAT(state,':',bytes_sent) FROM fh_download_reservations
  WHERE resource_id='${crash_id}' ORDER BY created_at DESC LIMIT 1")"
docker compose restart app
wait_ready
after_reap="$(sql "SELECT CONCAT(state,':',bytes_sent) FROM fh_download_reservations
  WHERE resource_id='${crash_id}' ORDER BY created_at DESC LIMIT 1")"
test "${after_reap}" = "${before_reap}"

echo "Docker transfer and interrupted-download smoke passed."
