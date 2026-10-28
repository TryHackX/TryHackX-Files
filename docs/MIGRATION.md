# Upgrading an existing TryHackX Files installation

This guide upgrades a running installation to **2.81.5**, database schema **64**. A code-only
rollback is not assumed to be compatible with a newer schema.

> Create and restore-test a backup first. Migration locks, journaling and readiness checks reduce
> risk but do not replace a recoverable backup.

## 1. Target requirements

- PHP 8.1+ with `pdo_mysql`, `curl`, `mbstring`, `openssl` and preferably `fileinfo`;
- Python 3.11+;
- MySQL 8.0.1+ or MariaDB 10.6+ (the e-mail outbox needs `FOR UPDATE SKIP LOCKED`);
- Apache 2.4 with `proxy`, `proxy_http`, `rewrite`, `headers` and preferably `expires`;
- a virtual host whose `DocumentRoot` is exactly `public/`;
- one stable `APP_SECRET_KEY` available to PHP and CLI workers.

The Python server refuses readiness unless the database schema is exactly 64. An old process
therefore cannot transfer files against a partial or unsupported migration.

## 2. Back up and prove recovery

Enable maintenance mode or stop uploads, then preserve one consistent point in time:

1. full database dump;
2. `uploads/`;
3. `data/file-quarantine/` when quarantine retention is enabled;
4. `config/config.local.php`;
5. `data/install.lock` and required operational state;
6. the managed `APP_SECRET_KEY`, or exact `data/.appkey` bytes for a legacy development install.

Linux example:

```bash
cd /var/www/filehost
mysqldump --single-transaction --routines --triggers \
  -u DB_USER -p DB_NAME > /backup/filehost-db.sql
tar --xattrs --acls -czf /backup/filehost-runtime.tar.gz \
  uploads config/config.local.php data
find uploads -type f -print0 | sort -z | xargs -0 sha256sum \
  > /backup/filehost-uploads.sha256
```

Do not put the secret in the repository or shell logs. Restore the dump and archive at least once
in an isolated location and compare file counts, total bytes and checksums.

Changing the encryption key is not an upgrade. Losing it makes encrypted settings unreadable.
A legacy file key can be provided as `APP_SECRET_KEY=hex:<exact 64 hex characters>`.

## 3. Deploy code without overwriting runtime data

Prefer a new release directory. When updating in place, exclude:

- `config/config.local.php`;
- `uploads/`;
- `data/`;
- `venv/`;
- `.env`.

```bash
rsync -a --delete \
  --exclude 'config/config.local.php' \
  --exclude 'uploads/' \
  --exclude 'data/' \
  --exclude 'venv/' \
  --exclude '.env' \
  ./new-release/ /var/www/filehost/
```

Do not use `git checkout --theirs` to protect configuration; “theirs” depends on the Git
operation and can select the wrong side.

## 4. Install pinned Python dependencies

```bash
cd /var/www/filehost
python3 -m venv venv.new
venv.new/bin/python -m pip install --require-hashes -r requirements-lock.txt
venv.new/bin/python -m py_compile upload_server.py
```

Do not upgrade packages opportunistically during deployment. Lock-file updates require a
separate reviewed change and the full test suite.

Always build the new environment next to the old one (`venv.new`, as above) and swap it in step 6
rather than installing over `venv/`: a release that drops a package (the lock no longer installs
`websockets`, `watchfiles`, `python-dotenv` and PyYAML, and adds `uvloop` on Linux) would otherwise
leave the old ones behind. After the swap, `venv/bin/python -c "import uvloop"` confirms the Linux
event loop is present.

A release that changes `scripts/*.service` (the systemd sandbox, resource limits and restart
policy have changed) is applied on Debian with
`sudo FILEHOST_UNITS_ONLY=1 bash scripts/install-debian.sh`. It renders both units again for the
account they already run as, writes the storage drop-in when `UPLOADS_PATH`, `DATA_PATH` or
`FILE_QUARANTINE_PATH` point outside the application directory, reloads systemd and restarts the
services when the installation is already configured. It installs no packages, leaves `venv/`
alone and changes no ownership. Because it restarts the services, run it at step 5 of the
migration order below, in place of starting them by hand. A full run without the switch also
installs missing packages (never upgrading installed ones) and runs `pip install` into the
existing `venv/`; use that only on a stopped service or after the `venv.new` swap. Compare
`systemctl cat filehost-upload` with the template if the units were edited by hand.

## 5. Permissions and Apache

PHP, Python and cleanup need a consistent service account: `www-data`, or the dedicated account
from [DEBIAN.md](DEBIAN.md#service-account). The installed upload unit names it. Runtime storage is
writable; secrets are read-only:

```bash
SERVICE_USER="$(systemctl show --property=User --value filehost-upload)"
SERVICE_GROUP="$(systemctl show --property=Group --value filehost-upload)"
sudo install -d -o "$SERVICE_USER" -g "$SERVICE_GROUP" -m 0750 \
  /var/www/filehost/uploads /var/www/filehost/data
sudo chown -R "$SERVICE_USER:$SERVICE_GROUP" \
  /var/www/filehost/uploads /var/www/filehost/data
sudo a2enmod proxy proxy_http rewrite headers
sudo apachectl configtest
```

Run every maintenance script below as that account, not as root: a file a script creates as root
(a restored upload, a repaired manifest, a quarantine copy) cannot be read by the upload service
afterwards. The scripts that write such files refuse root and print the right command.

The virtual host serves `/var/www/filehost/public`, never the project root.

## 6. Migration and restart order

1. Stop `filehost-upload` and `filehost-mail-worker`.
2. Switch PHP to the new release.
3. Make one PHP request. `Database::migrate()` takes an advisory lock, marks the schema not
   ready, journals each step, repairs safe partially applied structures, verifies contracts
   2–64 and publishes `schema_ready=1` only after success.
4. Verify schema 64 and readiness.
5. Promote `venv.new` to `venv`, then start the Python and mail services (on Debian, when the
   release changes the units, with `sudo FILEHOST_UNITS_ONLY=1 bash scripts/install-debian.sh`,
   which starts them itself).

An unsupported newer schema is rejected and never downgraded automatically. Do not manually
change `schema_version` after a failure; fix the journaled cause or restore the backup.

After a failed step, web requests wait five minutes before running it again and answer 503
meanwhile, so a broken upgrade is not re-run by every visitor. Once the cause is fixed, retry at
once as the service account — `sudo -u "$SERVICE_USER" php scripts/migrate.php` — instead of
waiting (exit 0: schema current and ready; 2: the migration failed again, see the journal).

2.81.5 is a **code-only upgrade — the schema stays at 64.** It changes the upload page only (a group
of uploads is no longer interrupted by the verification); no requirement changes. Restart
`filehost-upload` so `/ready` reports the version. From an earlier release, follow the notes below too.

2.81.4 is a **code-only upgrade — the schema stays at 64.** It changes the upload page only (the
verification is asked for when files are picked, not on arrival); no requirement changes. Restart
`filehost-upload` so `/ready` reports the version. From an earlier release, follow the notes below too.

2.81.3 is a **code-only upgrade — the schema stays at 64.** Install the release's hash-locked
requirements into the virtual environment (FastAPI 0.161.1, pycparser 3.12) and restart
`filehost-upload` and `filehost-mail-worker`; the systemd unit templates are unchanged.
From an earlier release, follow the notes below too.

2.81.2 is a **code-only upgrade — the schema stays at 64.** Install the release's hash-locked
requirements into the virtual environment (FastAPI 0.156.3, Starlette 1.9.0, Pillow 13.0.0) and
restart `filehost-upload` and `filehost-mail-worker`; the systemd unit templates are unchanged.
From an earlier release, follow the notes below too.

2.81.1 is a **code-only upgrade — the schema stays at 64.** It changes one front-end file; from
2.81.0 nothing else has to be done. From an earlier release, follow the 2.81.0 notes below too.

2.81.0 is a **code-only upgrade — the schema stays at 64.** Install the release's hash-locked
requirements into the virtual environment (FastAPI 0.143.2); the systemd unit templates are
unchanged. Uploads now always need the upload token the site hands out, and the maintenance
scripts refuse to run as root, so run them — and any cron line for `cleanup.php` — as the
service account. Later unit changes and moved storage paths are applied with
`sudo FILEHOST_UNITS_ONLY=1 bash scripts/install-debian.sh`.

2.79.0 is a **code-only upgrade — the schema stays at 64.** It adds the selectable captcha
provider (Cloudflare Turnstile, reCAPTCHA v3, reCAPTCHA v2, hCaptcha) and stores a key pair
per provider under new settings keys, which are created on demand; nothing has to be migrated
and nothing has to be re-entered. An installation upgrading from 2.78.0 or earlier keeps
reCAPTCHA v2 selected with the keys it already had, because that provider still reads the
historical `recaptcha_site_key` / `recaptcha_secret_key` pair.

2.78.0 adds column `users.email_change_stage` (schema 64) and drops any half-finished
e-mail change, because those were issued under the old one-sided rule. 2.77.0 added table
`remember_tokens` (schema 63) and the Python sidecar refuses readiness on
anything else, so the two halves must move together. Follow the order above exactly: the old
sidecar answering `/ready` against a migrated database is a stopped file service, not a
degraded one. Existing sessions are unaffected; nobody is signed out by the upgrade.

Upgrading to 2.76.9 on a host that runs its own MTA: reinstall the systemd unit (it gained a
watchdog and stricter sandboxing) and change **Settings → E-mail → Sending method** from
**PHP mail()** to **Local mail server (SMTP on 127.0.0.1:25)**. The two belong together —
the hardened unit sets `NoNewPrivileges=true`, and `mail()` cannot reach Postfix through its
setgid `postdrop` helper under that flag. The worker logs a warning when it finds that
combination, but it cannot fix the setting for you.

```bash
curl --fail --show-error https://files.example.com/
curl --fail --show-error https://files.example.com/api.php?action=health
curl --fail --show-error http://127.0.0.1:8001/ready
sudo -u "$SERVICE_USER" php scripts/check-db-integrity.php
sudo -u "$SERVICE_USER" php scripts/check-storage-integrity.php --json
sudo -u "$SERVICE_USER" php scripts/mail-worker.php --limit=1
```

(`SERVICE_USER` as set in step 5.)

`/ready` must return HTTP 200 with `"schema_version":64`. `/live` checks process liveness only.
The database-integrity response must contain `success: true` and zero orphan counts.

Optional SQL verification, using the real prefix:

```sql
SELECT setting_key, setting_value
FROM fh_settings
WHERE setting_key IN ('schema_version', 'schema_ready');

SELECT version, status, attempts, last_error
FROM fh_migration_journal
ORDER BY version DESC
LIMIT 10;
```

## 7. Deleted-file quarantine

`file_quarantine_days=0` retains the historical permanent-delete behavior. A value from 1 to
3650 copies and verifies artifacts before active deletion and purges them after the retention
window.

The default path is `data/file-quarantine/`. A dedicated volume may be configured:

```php
define('FILE_QUARANTINE_PATH', '/mnt/recovery/filehost-quarantine');
```

It must not overlap `uploads/` or `public/`, including after symlink resolution. Budget enough
capacity for an additional copy of deleted data and include it in consistent backups.

```bash
sudo -u "$SERVICE_USER" php scripts/file-quarantine.php list
sudo -u "$SERVICE_USER" php scripts/file-quarantine.php restore FILE_ID
sudo -u "$SERVICE_USER" php scripts/file-quarantine.php purge FILE_ID --force
```

As root, a restore would leave `uploads/FILE_ID` owned by root with private modes, so the upload
service could not serve or delete the file; the script refuses root for that reason.
Restore never overwrites a colliding ID or mismatched bytes. Deleting one's own account bypasses
the quarantine so the request leaves no recoverable copy; an account deleted by an administrator
is quarantined like any other administrative delete.

## 8. Post-upgrade smoke test

- [ ] home page, assets and sign-in modal load without console errors;
- [ ] a small upload creates both the database row and storage bytes;
- [ ] full and `Range: bytes=N-` downloads return correct `200/206` responses;
- [ ] collection ZIP download works;
- [ ] sign-in, panel access and one CSRF-protected action work;
- [ ] `/ready` remains HTTP 200;
- [ ] the mail worker runs and dead/oldest queue metrics do not grow;
- [ ] cleanup runs as the same service account;
- [ ] when quarantine is enabled, a test file survives delete → list → restore with its checksum;
- [ ] logs contain no tokens, passwords or full secrets;
- [ ] storage count and total bytes match expectations;
- [ ] assigning the moderator role adds the Moderator group automatically;
- [ ] a moderator keeps the Moderator group after a Premium plan changes the separate
      plan/limits group, both groups appear in the user list and enforcement uses the better
      allowance from either group;
- [ ] Premium and Ads show the same shared invoice configuration.
- [ ] a group transfer allowance rejects a file that would exceed the remaining period;
- [ ] killing an upload or download stops the browser transfer and records it as interrupted;
- [ ] file sorting by uploader IP works only for staff with IP visibility;
- [ ] each sortable table cycles descending → ascending → unsorted, and Shift-click displays
      stable numbered priorities when advanced sorting is allowed;
- [ ] configured account-input limits apply to registration and account creation without locking
      out an existing account whose credentials predate a stricter policy;

Only remove the old Python environment after the smoke test and rollback window.

## 9. Rollback

Do not combine old code with schema 64 unless that release explicitly supports it. Restore one
consistent point in time:

1. stop PHP traffic, Python and cleanup;
2. restore previous code;
3. restore the database dump;
4. restore `uploads/`, `data/`, configuration and the same encryption key;
5. restore ownership and permissions;
6. start the previous services and run their smoke test.

New uploads accepted after the backup require an explicit preservation decision before rollback.

Container deployment is documented separately in [DOCKER.md](DOCKER.md).
