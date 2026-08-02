# Tests

The project has independent PHP, Python and browser-JavaScript suites, all used by
[CI](../.github/workflows/ci.yml). Do not hard-code test/assertion counts in documentation; the
current runner output is authoritative.

CI covers PHP 8.1/8.4 with MySQL 8.4 and MariaDB 11.4, plus Python 3.11/3.14. Separate policy
checks validate syntax, pinned action SHAs, lock hashes, floating container tags and common
secret formats.

## PHPUnit

The suite covers repositories, HTTP/API controllers, declarative routes, permissions, migrations,
schema integrity, payments, advertising, notifications, quotas, concurrent leases and security
regressions. Every run creates a freshly named database, builds/migrates the schema and deletes
only that guarded test database plus private temporary storage.

PHPUnit 11.5.56 is pinned with SHA-256:

```bash
curl --fail --location --silent --show-error \
  --output tools/phpunit.phar \
  https://phar.phpunit.de/phpunit-11.5.56.phar
echo "915fa161f496dc04a45cd6032855879bca0bab644048cd0516982dffe678e9f1  tools/phpunit.phar" \
  | sha256sum --check --strict
```

Linux/macOS/CI:

```bash
export ALLOW_DESTRUCTIVE_TEST_DB=YES
export TEST_DB_NONCE="$(php -r 'echo bin2hex(random_bytes(12));')"
export TEST_DB_NAME="filehost_local_test_${TEST_DB_NONCE}"
export TEST_DB_HOST=127.0.0.1
export TEST_DB_USER=filehost_test_runner
export TEST_DB_PASS='dedicated-test-password'
php tools/phpunit.phar -c tests/php/phpunit.xml
```

PowerShell:

```powershell
$env:ALLOW_DESTRUCTIVE_TEST_DB = 'YES'
$env:TEST_DB_NONCE = php -r "echo bin2hex(random_bytes(12));"
$env:TEST_DB_NAME = "filehost_local_test_$env:TEST_DB_NONCE"
$env:TEST_DB_HOST = '127.0.0.1'
$env:TEST_DB_USER = 'filehost_test_runner'
$env:TEST_DB_PASS = 'dedicated-test-password'
php tools/phpunit.phar -c tests/php/phpunit.xml
```

The nonce must be fresh hexadecimal text 16–64 characters long, and the database name must end
exactly in `_test_<nonce>`. The bootstrap reads configured production coordinates without
executing `config.local.php` and refuses the production database. Use a dedicated test account
restricted to ephemeral databases.

## pytest

```bash
python -m pip install --require-hashes -r requirements-dev-lock.txt
python -m pytest tests/python -q
```

The suite covers file-server helpers and asynchronous protocols including Range/throttling,
reservations, transfer settlement, heartbeat leases, interrupted streams and upload boundaries.

`requirements.txt` and `requirements-dev.txt` are human-maintained inputs. The runtime and test
locks include all transitive versions and hashes:

```bash
python -m pip install pip-tools==7.6.0
pip-compile --generate-hashes --strip-extras \
  --output-file requirements-lock.txt requirements.txt
pip-compile --generate-hashes --strip-extras \
  --output-file requirements-dev-lock.txt requirements.txt requirements-dev.txt
```

Update locks in an explicit change and run both full suites.

## Node

```bash
node --test tests/js/*.test.js
```

These tests cover security-sensitive DOM renderers, declarative dispatch, modular panel behavior
and regressions which do not require a browser process.

## Docker E2E

The `docker-smoke` job builds empty volumes, creates a schema through the CI-only bootstrap,
restarts the stack and waits for both Apache and `/ready`. It then exercises upload/download
through Apache, forwarded client IP, no-oversell concurrency, interrupted full and Range bodies,
hard uvicorn termination, lease recovery and final integrity reports.

`scripts/ci-bootstrap-install.php` requires CLI plus `FILEHOST_CI_BOOTSTRAP=1`, never deletes a
database and never overwrites existing configuration. It is not a production installer.

See [docs/TESTING.md](../docs/TESTING.md) for all quality gates.
