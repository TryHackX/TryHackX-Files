<div align="center">

# TryHackX Files

**A fast, self-hosted file sharing application.**

[Repository](https://github.com/TryHackX/TryHackX-Files) ·
[TryHackX on GitHub](https://github.com/TryHackX)

PHP handles sessions, the administration panel and business logic. A loopback-only asynchronous
Python service streams files directly to disk and serves downloads without loading whole objects
into memory.

![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![Python](https://img.shields.io/badge/Python-3.11%2B-3776AB?logo=python&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL%20%2F%20MariaDB-8.x-4479A1?logo=mysql&logoColor=white)
![Platform](https://img.shields.io/badge/Windows%20%7C%20Debian-informational)
![License](https://img.shields.io/badge/license-PolyForm%20Noncommercial%201.0.0-blue)

</div>

## Features

- streamed multi-file drag-and-drop uploads with progress and guest deletion tokens;
- bounded-memory full and Range downloads which remain interruptible by an administrator;
- accounts, e-mail/admin activation, password recovery and e-mail changes;
- files, collections and on-the-fly ZIP downloads;
- per-group storage, retention, bandwidth and renewable download-transfer allowances (guest
  usage is accounted by IP);
- granular staff permissions for reports, destructive moderation, traffic, audit, Premium
  metrics/payments/subscribers/refunds and advertising review/refunds/management/packages;
- permanent full access for every administrator account, including the installer-created owner;
- two-factor authentication, API keys, webhooks and durable notifications/e-mail outbox;
- abuse reporting, IP/e-mail/name bans and maintenance mode;
- optional recoverable deleted-file quarantine with checksums and operator restore/purge;
- automatic retention, quota enforcement, payment reconciliation and cleanup;
- image/video thumbnails with resource caps;
- PL, EN, ES and ZH application translations plus validated custom JSON languages;
- Premium plans and paid advertising;
- per-plan control over which enforced limits appear on each Premium card;
- tri-state sorting across panel tables, with server-side multi-column sorting for paged file and
  user lists and Shift-click priorities;
- built-in PayU and Przelewy24 server-side checkout with a shared idempotent ledger;
- receipts/invoices shared by Premium and Advertising: editing either settings panel changes the
  same seller/prefix/footer/enabled configuration;
- advertising zones, review queue, packages, statistics and image previews;
- administration broadcast messages through in-app, standard HTML e-mail or sanitized custom HTML;
- fail-closed English web installer;
- Windows launchers, native Debian systemd services and an unverified Docker Compose option.

## Architecture

```text
Browser
  ├─ pages and browser API ──> Apache + PHP ──> MySQL
  │                                  └─> durable mail outbox ──> PHP mail worker
  └─ upload/download/ZIP/thumb ──> Apache proxy ──> Python :8001 ──> private storage
```

| Component | Technology |
|---|---|
| Web backend | PHP 8.1+, PDO and sessions |
| Transfer server | Python 3.11+, FastAPI, uvicorn, aiofiles and aiomysql |
| Database | MySQL 8 or MariaDB 10.4+ |
| HTTP server | Apache 2.4 with `mod_proxy`, `mod_proxy_http`, `mod_rewrite`, `headers` |

The database prefix is configurable (default `fh_`). Migrations run under a global lock and
publish readiness only after the full schema contract passes. Python accepts transfers only
against the exact supported schema version.

## Installation

### Requirements

- PHP 8.1+ with `pdo_mysql`, `curl`, `mbstring`, `openssl` and preferably `fileinfo`;
- MySQL 8 or MariaDB 10.4+;
- Python 3.11+;
- Apache 2.4 with the modules listed above.

Place the project outside the public document root and set the virtual host `DocumentRoot`
exactly to the `public/` subdirectory. Configuration, storage, scripts and source code must never
be served directly.

### Windows / WampServer

The supported launch points are:

```bat
scripts\start-upload-server.bat
scripts\start-mail-worker.bat
```

The first script creates `venv`, installs hash-verified dependencies from
`requirements-lock.txt` and starts the loopback server on port 8001. Do not start a second copy
of `upload_server.py`.

For production-like use, run both commands through Windows services or Task Scheduler. To enable
minimal logging for both launchers, copy the local environment template once:

```bat
copy scripts\filehost-env.bat.example scripts\filehost-env.bat
```

The example already contains `FILEHOST_MINIMAL_LOGS=1`. The copied file is ignored by Git.

### Debian 12/13 without Docker

```bash
sudo bash scripts/install-debian.sh
```

Then configure the Apache virtual host, complete the web installer and start the systemd units.
The full procedure is in [docs/DEBIAN.md](docs/DEBIAN.md).

### Web installer

Before the first request, configure these variables for the PHP/Apache process:

```text
FILEHOST_INSTALL_TOKEN=<unique random secret, at least 32 characters>
FILEHOST_INSTALL_ALLOW_IPS=<operator IP or a narrow CIDR; loopback is allowed by default>
```

Generate a secret with `openssl rand -hex 32`, open `/install.php`, enter that secret and complete
the English wizard:

1. environment checks;
2. database connection;
3. canonical URL and first administrator;
4. schema/configuration publication.

The secret can be claimed by one IP-bound session for at most one hour. Successful setup writes
`config/config.local.php` and `data/install.lock`; either file closes the installer. Remove the
bootstrap environment variables after setup.

Reinstallation is an offline recovery operation: stop services, back up database/storage/config,
then move installer state out of the project deliberately. The wizard never overwrites an
existing local configuration.

## Configuration

The installer creates the untracked private file `config/config.local.php`:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'filehost');
define('DB_PASS', 'replace-me');
define('DB_NAME', 'filehost');
define('DB_PREFIX', 'fh_');
define('APP_CANONICAL_URL', 'https://files.example.com');
```

The canonical URL is mandatory and protects redirects, security e-mails and origin validation.
Behind a reverse proxy, configure `TRUSTED_PROXIES` narrowly and retain the canonical scheme,
host and port.

Database secrets are encrypted with a stable `APP_SECRET_KEY` of at least 32 bytes. Production
should supply it through a secret manager or preserve the installer-generated value. Back it up:
loss or uncoordinated rotation makes encrypted settings unreadable. The file fallback
`data/.appkey` is allowed only in an explicit development/local/test environment.

Application limits, mail, moderation, payments, ads, retention and cleanup are configured in the
administration panel. Python refreshes database-backed settings automatically.

### Minimal logs

This is an operating-system environment variable, not a PHP setting and not a line in
`upload_server.py`:

```text
FILEHOST_MINIMAL_LOGS=1
```

On Windows, put it in the ignored `scripts\filehost-env.bat` described above. On Debian, set it
in `/etc/default/filehost` and restart both systemd services. In Docker only, place it in `.env`.

It disables uvicorn access logs and routine startup/request/mail-batch messages. Warnings and
errors remain. The main benefit is less console/journal I/O and a cleaner production log; memory
or throughput improvement is expected to be small and should be measured.

### E-mail outbox worker

The mail worker is not Postfix and does not replace it. TryHackX Files commits outgoing messages to a
durable database outbox; the worker claims those rows and hands each message to the configured
transport. Postfix alone does not poll the TryHackX Files database, so the worker is required for
queued application e-mail.

**Settings → E-mail → Sending method** offers three transports:

| Method | Submission | Use it when |
| --- | --- | --- |
| PHP `mail()` (local sendmail) | the `sendmail_path` binary | shared hosting, where only `mail()` is available |
| Local mail server | SMTP to `127.0.0.1:25` | this host runs Postfix, Exim or another MTA |
| SMTP (external server) | SMTP to the configured relay | mail leaves through a provider |

Prefer **Local mail server** over `mail()` wherever an MTA runs on the same host. `mail()` reaches
Postfix through the setgid `postdrop` helper, and the hardened `filehost-mail-worker` unit sets
`NoNewPrivileges=true`, which strips that bit on exec: `postdrop` then warns about an unwritable
maildrop and sleeps ten seconds in a loop that never ends, so `mail()` never returns. The worker
warns about that combination in the journal, restarts itself through the systemd watchdog when a
delivery blocks anyway, and `FILEHOST_LOCAL_MTA=host:port` moves the local submission endpoint
when the MTA is not on this host's port 25.

The preferred Debian setup is the long-running `filehost-mail-worker` systemd service installed
by `scripts/install-debian.sh`. As an alternative, disable that service and place this entry in
`/etc/cron.d/filehost-mail-worker` (system cron format, including the `www-data` account):

```cron
* * * * * www-data /usr/bin/php /var/www/filehost/scripts/mail-worker.php --limit=100 --quiet
```

Use one mode or the other, not both. Multiple workers are lease-safe, but duplicate schedulers
only add avoidable polling.

### Cleanup

Run the shared cleanup job every 15 minutes, or enable its internal scheduler in the panel:

```cron
*/15 * * * * /usr/bin/php /var/www/filehost/scripts/cleanup.php >/dev/null 2>&1
```

Cleanup expires tokens, recovers abandoned leases, enforces quotas/retention, reconciles payments,
sends warnings and purges expired quarantine objects.

## Payments and sales documents

PayU and Przelewy24 are native server-side providers. Saving a configured built-in provider makes
it active for both Premium plans and Advertising checkout. A payment grants a product only after
provider authentication, exact amount/currency correlation and idempotent fulfillment.

Przelewy24 additionally performs the provider-mandated transaction verification after a signed
notification. Refund acceptance is asynchronous and remains `REFUNDING` until the signed result.
See [docs/P24.md](docs/P24.md) for the protocol and pending merchant-sandbox checklist.

Receipt/invoice settings are deliberately one shared configuration. The Premium and Advertising
settings pages render and update the same database values, with an explicit note in both places.

## Permissions

Administrators implicitly hold every known permission and cannot be restricted by their group.
The installer-created owner account cannot be demoted, deactivated or deleted.
Each account has one plan/limits group. Selecting the `moderator` role automatically adds the
non-deletable Moderator system group as a second assignment. It contributes permissions and a
complete limit profile, but never replaces the account's plan or Premium expiry. Enforcement
combines both profiles and uses the more favourable file, storage, speed, concurrency, transfer
and retention allowance. A moderator can therefore buy Premium and the user list shows both
Moderator and the purchased plan group. Ordinary users never receive staff-only capabilities,
and administrators implicitly hold them all.

Configure the Moderator group's shared permissions in **Settings → Groups**. In
**Users → Manage user**, selecting or removing the `moderator` role attaches or clears that group
automatically; the separate group action continues to select the account's plan/limits group.
Parent dependencies are enforced when saving, for example:

- resolving/deleting reported files requires report visibility;
- Premium refunds require payment-ledger visibility;
- advertising refunds require advertising approval access.

Controllers and declarative route policy re-check every capability; hiding a tab is not the
security boundary.

Username, e-mail and new-password length policy is configured in **Settings → Security → Access
Control**. New credentials use those values immediately. Sign-in and current-password
verification retain bounded legacy maxima so lowering a creation policy cannot lock out an
existing account.

## Repository layout

```text
public/                 only Apache DocumentRoot; pages, browser API and assets
src/                    application bootstrap, repositories, controllers and views
config/                 private installation configuration
docs/                   deployment, storage, migration, testing and roadmap
docker/                 optional image, Apache and Supervisor configuration
scripts/                workers, cleanup, integrity checks and Debian installer
tests/                  PHPUnit, pytest, Node and smoke tests
upload_server.py        FastAPI upload/download service
requirements-lock.txt   hash-locked Python runtime dependencies
uploads/                private runtime file bytes
data/                   private runtime state and generated cache
```

## Documentation

- [Native Debian deployment](docs/DEBIAN.md)
- [Upgrade and rollback](docs/MIGRATION.md)
- [Storage and multi-disk layout](docs/STORAGE.md)
- [Przelewy24 integration](docs/P24.md)
- [Testing and release gates](docs/TESTING.md)
- [Release procedure](docs/RELEASING.md)
- [Docker status and usage](docs/DOCKER.md)
- [Open roadmap](docs/ROADMAP.md)
- [Security policy](SECURITY.md)
- [Licensing overview](LICENSING.md)
- [Contribution policy](CONTRIBUTING.md)

## Security

- bcrypt password hashing and hashed capability tokens;
- prepared PDO statements;
- explicit CSRF protection and fresh-authentication checks for sensitive browser actions;
- strict canonical-origin validation and CSP on major surfaces;
- encrypted stored secrets;
- signed and idempotent payment callbacks;
- storage outside the web root with manifest/checksum validation;
- fail-closed installer and schema readiness;
- role plus capability checks for every delegated moderation endpoint.

Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## License

TryHackX Files is source-available under the
[PolyForm Noncommercial License 1.0.0](LICENSE). Noncommercial use is permitted under that
license; commercial use requires a separate written agreement with the project owner.

See [LICENSING.md](LICENSING.md) for the licensing overview and
[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) for components that remain under their own terms.
