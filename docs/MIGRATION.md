# Upgrading an existing TryHackX Files installation

This guide upgrades a running installation to **2.77.1**, database schema **63**. A code-only
rollback is not assumed to be compatible with a newer schema.

> Create and restore-test a backup first. Migration locks, journaling and readiness checks reduce
> risk but do not replace a recoverable backup.

## 1. Target requirements

- PHP 8.1+ with `pdo_mysql`, `curl`, `mbstring`, `openssl` and preferably `fileinfo`;
- Python 3.11+;
- MySQL 8 or MariaDB 10.4+;
- Apache 2.4 with `proxy`, `proxy_http`, `rewrite` and `headers`;
- a virtual host whose `DocumentRoot` is exactly `public/`;
- one stable `APP_SECRET_KEY` available to PHP and CLI workers.

The Python server refuses readiness unless the database schema is exactly 63. An old process
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

## 5. Permissions and Apache

PHP, Python and cleanup need a consistent service account. Runtime storage is writable; secrets
are read-only:

```bash
sudo install -d -o www-data -g www-data -m 0750 \
  /var/www/filehost/uploads /var/www/filehost/data
sudo chown -R www-data:www-data \
  /var/www/filehost/uploads /var/www/filehost/data
sudo a2enmod proxy proxy_http rewrite headers
sudo apachectl configtest
```

The virtual host serves `/var/www/filehost/public`, never the project root.

## 6. Migration and restart order

1. Stop `filehost-upload` and `filehost-mail-worker`.
2. Switch PHP to the new release.
3. Make one PHP request. `Database::migrate()` takes an advisory lock, marks the schema not
   ready, journals each step, repairs safe partially applied structures, verifies contracts
   2–63 and publishes `schema_ready=1` only after success.
4. Verify schema 63 and readiness.
5. Promote `venv.new` to `venv`, then start the Python and mail services.

An unsupported newer schema is rejected and never downgraded automatically. Do not manually
change `schema_version` after a failure; fix the journaled cause or restore the backup.

2.77.0 adds table `remember_tokens` (schema 63) and the Python sidecar refuses readiness on
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
php scripts/check-db-integrity.php
php scripts/check-storage-integrity.php --json
php scripts/mail-worker.php --limit=1
```

`/ready` must return HTTP 200 with `"schema_version":63`. `/live` checks process liveness only.
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
php scripts/file-quarantine.php list
php scripts/file-quarantine.php restore FILE_ID
php scripts/file-quarantine.php purge FILE_ID --force
```

Restore never overwrites a colliding ID or mismatched bytes. Full account deletion intentionally
bypasses quarantine so a deletion request does not leave a recoverable copy.

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

Do not combine old code with schema 63 unless that release explicitly supports it. Restore one
consistent point in time:

1. stop PHP traffic, Python and cleanup;
2. restore previous code;
3. restore the database dump;
4. restore `uploads/`, `data/`, configuration and the same encryption key;
5. restore ownership and permissions;
6. start the previous services and run their smoke test.

New uploads accepted after the backup require an explicit preservation decision before rollback.

Container deployment is documented separately in [DOCKER.md](DOCKER.md).
