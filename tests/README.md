# Tests

The project has independent PHP, Python and browser-JavaScript suites, all used by
[CI](../.github/workflows/ci.yml). Do not hard-code test/assertion counts in documentation; the
current runner output is authoritative.

CI covers PHP 8.1, 8.4 and 8.5 with MySQL 8.4 and MariaDB 11.4 and 11.8 (the pairs are listed
in the workflow; 8.5 with MariaDB 11.8 is the production combination), plus Python 3.11, 3.13 and
3.14. Separate policy checks validate syntax, pinned action SHAs, lock hashes and agreement,
digest-pinned container bases, the release number in every file that states it, and common secret
formats. They also check that each lock satisfies every name and version range of the
requirements file it was compiled from, and that the uv version below is the one
`requirements-dev.txt` pins.

## PHPUnit

The suite covers repositories, HTTP/API controllers, declarative routes, permissions, migrations,
schema integrity, payments, advertising, notifications, quotas, concurrent leases and security
regressions. Every run creates a freshly named database, builds/migrates the schema and deletes
only that guarded test database plus private temporary storage.

The PHPUnit line depends on the PHP version, and CI pins each phar by SHA-256 (the policy check
fails when a checksum used in the workflow is missing from this table):

| PHP | PHPUnit | SHA-256 |
|---|---|---|
| 8.1, 8.2 | 10.5.66 | `42bcac97bbf9fb1aecf5a7d6a1b37123a11e7e295458978f0e268999f6d9e50f` |
| 8.3, 8.4, 8.5 | 12.5.39 | `cf9a68e3e294257fac2da235c2dcfef29efd90462cafd67941ef32f52142dfce` |

```bash
curl --fail --location --silent --show-error \
  --output tools/phpunit.phar \
  https://phar.phpunit.de/phpunit-12.5.39.phar
echo "cf9a68e3e294257fac2da235c2dcfef29efd90462cafd67941ef32f52142dfce  tools/phpunit.phar" \
  | sha256sum --check --strict
```

On Windows without `sha256sum`: `Get-FileHash tools\phpunit.phar -Algorithm SHA256`.

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
locks include all transitive versions and hashes. They are compiled **universally** with `uv`
(pinned in `requirements-dev.txt`), so one file installs on Windows and on Linux with Python
3.11 or newer: platform-specific packages carry environment markers (`uvloop` is installed only
where it exists, `colorama` only on Windows). A plain `pip-compile` run on one machine cannot do
that, which is how production once ended up without `uvloop`.

```bash
python -m pip install uv==0.13.1
uv pip compile requirements.txt --universal --python-version 3.11 --generate-hashes --upgrade \
  -o requirements-lock.txt
uv pip compile requirements-dev.txt requirements.txt --universal --python-version 3.11 \
  --generate-hashes --allow-unsafe --upgrade -o requirements-dev-lock.txt
```

Without `--upgrade` the existing pins are kept and only new requirements are resolved. A pin that
no longer satisfies its requirement (a raised floor) is resolved again, to the newest release. To
take one exact version instead, for example the one a Dependabot pull request proposes, add
`-P name==version` (`--upgrade-package`) per package; uv leaves that option out of the file
header. Then prove that the result still installs and agrees with itself:

```bash
python -m venv /tmp/lockcheck && /tmp/lockcheck/bin/python -m pip install --require-hashes \
  -r requirements-dev-lock.txt && /tmp/lockcheck/bin/python -m pip check
python -m ruff check upload_server.py tests/python && python -m pytest tests/python -q
php scripts/check-repository-policy.php
```

(`venv\Scripts\python.exe` on Windows.) The policy check fails when the two files disagree on a
shared pin or a requirement is missing from the lock. Update locks in an explicit change and run
both full suites; `pydantic` and `pydantic-core` always move together because pydantic pins the
core exactly.

## Node

```bash
node --test tests/js/*.test.js
```

These tests cover security-sensitive DOM renderers, declarative dispatch, modular panel behavior
and regressions which do not require a browser process.

## Shell

```bash
bash tests/shell/install-debian-test.sh
```

Checks what `scripts/install-debian.sh` keeps when it runs again (service account, PHP handler,
local database, rendered units, storage drop-in, runtime directories) against a fixture unit, with
the package and Apache queries stubbed. It installs nothing; the directory-mode checks run on
Linux only. CI runs it after ShellCheck.

## Docker E2E

The `docker-smoke` job builds empty volumes, creates a schema through the CI-only bootstrap,
restarts the stack and waits for both Apache and `/ready`. It then exercises upload/download
through Apache, forwarded client IP, no-oversell concurrency, interrupted full and Range bodies,
hard uvicorn termination, lease recovery and final integrity reports.

`scripts/ci-bootstrap-install.php` requires CLI plus `FILEHOST_CI_BOOTSTRAP=1`, never deletes a
database and never overwrites existing configuration. It is not a production installer.

See [docs/TESTING.md](../docs/TESTING.md) for all quality gates.
