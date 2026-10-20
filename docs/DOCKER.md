# TryHackX Files with Docker Compose

> **Status: tested end to end in CI.** The `docker-smoke` job of
> [the CI workflow](../.github/workflows/ci.yml) builds the image from scratch for every pull
> request and every push to `main`, installs a fresh schema on empty volumes and runs uploads and
> downloads through Apache, interrupted full and Range transfers, a killed upload server, lease
> recovery and the integrity reports. A release is tagged only after it passes. The job runs on
> x86-64 Linux, so treat the first start on another architecture, or on volumes from an older
> release, as a deployment test.

The image and Compose model:

- Apache serves only `/app/public`;
- `.dockerignore` excludes host runtime data, configuration, secrets, Git and virtual
  environments;
- Apache modules cover rewrite and the loopback proxy to Python;
- Supervisor runs Apache, upload server, mail worker and cleanup as appropriate;
- configuration-dependent workers wait for web installation instead of restart-looping;
- Python and cleanup run as `www-data`;
- PHP/Python runtime dependencies are pinned or explicitly installed.

## Services

| Service | Contents | Published port |
|---|---|---|
| `app` | Apache + PHP and Python upload server under Supervisor | `${HTTP_PORT:-8080}` → 80 |
| `db` | MySQL 8.4 | internal only |

The image is built on the official `php:8.5-apache-trixie` (PHP 8.5 and Python 3.13 on Debian 13,
the production combination), pinned by digest; Dependabot refreshes tag and digest. It starts from
`php.ini-production` plus `docker/php-overrides.ini` (errors to the container log, no
`X-Powered-By`, PHP uploads up to 8 MiB for advertisement banners; file uploads go to the
upload server) and `docker/apache-security.conf` (no Apache version banner), and its GD supports
JPEG, PNG, WebP and FreeType. The Python environment is installed from `requirements-lock.txt`
before the sources are copied, so editing code does not reinstall it.

Apache and Python share one container because the public rules proxy transfer, ZIP, thumbnail,
ShareX and QR routes to loopback `127.0.0.1:8001`. Python trusts forwarded client addresses only
from its loopback peer.

## First start

```bash
cp .env.example .env
$EDITOR .env
docker compose up -d --build
docker compose ps
docker compose logs -f app
```

Set unique database passwords, the exact public `FILEHOST_CANONICAL_URL`, a random
`FILEHOST_INSTALL_TOKEN` of at least 32 characters and a narrow
`FILEHOST_INSTALL_ALLOW_IPS`.

Open `http://localhost:8080/install.php`, enter the bootstrap secret, then use:

- database host: `db`, not `localhost`;
- database user/password/name: values from `.env`;
- canonical URL: the same value as `FILEHOST_CANONICAL_URL`.

The installer writes `config/config.local.php` to the `appconfig` volume. Before that file exists,
Apache serves the installer while Python and the mail worker wait. The `app` health check can be
unhealthy during installation. After completion:

```bash
docker compose restart app
docker compose ps
```

When TLS terminates at an external reverse proxy, add only its direct address to
`TRUSTED_PROXIES`, forward `X-Forwarded-Proto: https` and retain the canonical Host header.

Remove installer token/allowlist values from the running environment after successful setup.

## Volumes and backups

- `uploads` → `/app/uploads`: stored user files;
- `appdata` → `/app/data`: thumbnail cache, installer state and default quarantine;
- `appconfig` → `/app/config`: private local configuration;
- `db_data`: MySQL data.

Back up all durable volumes at one consistent point in time. Quarantine retention can temporarily
require an additional copy of deleted file bytes. A custom `FILE_QUARANTINE_PATH` requires its
own mount and backup.

Restoring only configuration or only installer state does not reopen setup: either an existing
configuration or lock closes the installer. Interrupted-finalization recovery is an explicit
offline procedure.

## Minimal logs and metrics

Set `FILEHOST_MINIMAL_LOGS=1` in `.env` to disable uvicorn access logs and routine worker output.
Warnings and errors remain. This primarily reduces terminal/journal I/O; it is not a substitute
for profiling and is not expected to materially reduce application memory.

`/metrics` is not publicly proxied. Scrape it inside the container:

```bash
docker compose exec app curl -s http://127.0.0.1:8001/metrics
```

For remote scraping, configure `metrics_token`, add an explicit private proxy route and send
`Authorization: Bearer <token>`.

## Validation

```bash
docker compose ps
docker compose exec app curl --fail http://127.0.0.1:8001/ready
docker compose exec --user www-data app php scripts/check-db-integrity.php
docker compose exec --user www-data app php scripts/check-storage-integrity.php --json
```

`docker compose exec` runs as root unless `--user` says otherwise. Run the PHP scripts as
`www-data`, the account Apache and the workers use, so that nothing they write into the volumes
belongs to root.

Then upload and download a file through Apache, test a Range response and a collection ZIP.
The CI `docker-smoke` job performs a stricter isolated test, including client-IP forwarding,
no-oversell concurrency, broken full/Range bodies, process termination and lease recovery.

`FILEHOST_DOWNLOAD_LEASE_SECONDS` accepts 5–600 seconds; keep the production default of 120.
The value 5 is only for isolated smoke tests.

Never commit `.env`, and never run `docker compose down --volumes` against production data.
