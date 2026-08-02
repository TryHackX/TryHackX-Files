# TryHackX Files testing and quality gates

CI runs the same classes of checks across PHP 8.1/8.4, MySQL 8.4/MariaDB 11.4 and Python
3.11/3.14.

## Tooling

PHP runtime has no Composer dependency. Composer installs only pinned development analysis:

```bash
composer install --no-interaction --no-progress --prefer-dist --no-scripts
composer validate --strict
```

Install Python test dependencies from the hash-locked file:

```bash
python -m venv venv
venv/bin/python -m pip install --require-hashes -r requirements-dev-lock.txt
venv/bin/python -m pip check
```

On Windows use `venv\Scripts\python.exe`.

## Static and fast checks

```bash
php scripts/check-repository-policy.php
composer analyse
python -m ruff check upload_server.py tests/python
python -m compileall -q upload_server.py tests/python
node --test tests/js/*.test.js
```

PHP lint on Linux:

```bash
find public scripts src tests/php -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

PowerShell:

```powershell
Get-ChildItem public,scripts,src,tests/php -Recurse -Filter *.php |
    ForEach-Object { php -l $_.FullName }
```

## PHPUnit: an isolated database is mandatory

The bootstrap refuses to start without:

- `ALLOW_DESTRUCTIVE_TEST_DB=YES`;
- a fresh hexadecimal `TEST_DB_NONCE`;
- a test-looking `TEST_DB_NAME` ending exactly in that nonce;
- explicit host, user and password values;
- a host/database combination different from production configuration.

PowerShell example:

```powershell
$testNonce = [Guid]::NewGuid().ToString('N')
$env:ALLOW_DESTRUCTIVE_TEST_DB = 'YES'
$env:TEST_DB_NONCE = $testNonce
$env:TEST_DB_NAME = "filehost_test_$testNonce"
$env:TEST_DB_HOST = '127.0.0.1'
$env:TEST_DB_USER = 'filehost_test_runner'
$env:TEST_DB_PASS = 'dedicated-test-password'
php tools/phpunit.phar -c tests/php/phpunit.xml
```

The test user should be restricted to ephemeral test databases. Never bypass the guard or point
it at a running installation.

PHPUnit covers repositories, routes/controllers, permission dependencies, migrations and
forward-repair, storage invariants, concurrency leases, Premium/Ads, PayU and the P24 protocol.
P24 tests use an in-memory transport and therefore validate signatures and request contracts
without merchant credentials; they do not replace the sandbox checklist in [P24.md](P24.md).

## pytest

```bash
python -m pytest tests/python -q
```

The suite covers upload/download protocols, limits, Range, interrupted transfers, safe names,
metrics and ASGI behavior. It does not replace a real Apache/MySQL process smoke test.

## Docker Compose smoke

The isolated `docker-smoke` job and `tests/smoke/docker-transfer.sh` cover a clean install,
Apache, uvicorn, MySQL and interrupted transfers. Do not install Docker solely for local
validation when it is unavailable; let GitHub Actions or a dedicated host run this gate.

The destructive command below is only for a disposable Compose project:

```bash
docker compose build --pull app
docker compose up -d db app
# perform the isolated bootstrap from .github/workflows/ci.yml
bash tests/smoke/docker-transfer.sh
docker compose down --volumes --remove-orphans
```

`down --volumes` deletes that stack's data. Never target production.

## Release gate

A release requires repository policy, syntax/compile checks, PHPStan, Ruff, Node tests, full
PHPUnit on a fresh database, full pytest and an appropriate browser/HTTP smoke test. Schema
changes additionally require historical contracts, snapshots, forward-repair and failure
injection. Reservation/lease changes require concurrency and process-interruption tests.
