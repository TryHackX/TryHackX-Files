# Native Debian 12/13 deployment

This deployment does not require Docker. Apache serves the PHP application and proxies transfer
routes to the loopback-only Python service. MariaDB/MySQL is local or separately managed.

## 1. Place the release

Use a dedicated path whose project root is not the Apache document root:

```bash
sudo install -d -o "$USER" -g www-data -m 0750 /var/www/filehost
# Copy or clone the release into /var/www/filehost.
cd /var/www/filehost
sudo bash scripts/install-debian.sh
```

The script:

- installs Apache, PHP with the extensions the application uses (`mysql`, `curl`, `mbstring`,
  `gd`, `xml`), Python 3.11+ and, unless `FILEHOST_INSTALL_DB=0` is set, MariaDB;
- enables the Apache modules `proxy`, `proxy_http`, `rewrite`, `headers` and `expires` (the
  long-cache rules in `public/.htaccess` do nothing without the last one);
- installs hash-locked Python dependencies into `venv/` (it stops early on Python older than 3.11);
- creates private runtime directories;
- installs and enables (but does not start before configuration) the upload and mail systemd
  units, hardened as described in [Service sandbox](#service-sandbox);
- creates `/etc/default/filehost` with the logging switch.

Review the script before running it on an existing server. It does not create a database or
overwrite `config/config.local.php`.

Two switches change what a new installation gets: `FILEHOST_INSTALL_DB=0` skips `mariadb-server`
(MySQL or a remote database), and `FILEHOST_PHP_SAPI=fpm` installs `php-fpm`, `proxy_fcgi` and
`setenvif` instead of `libapache2-mod-php`. The FPM pool and the `SetHandler` for the virtual host
are then configured by hand (see Service account below).

### Running it again

A release that changes `scripts/*.service`, and a move of the storage paths, are applied with:

```bash
sudo FILEHOST_UNITS_ONLY=1 bash scripts/install-debian.sh
```

That renders both units and the storage drop-in again for the account they already use, reloads
systemd and restarts the services, and does nothing else: no packages, no Python environment, no
ownership changes. A full run without the switch keeps what the installation already uses instead
of going back to the defaults:

- the account of the installed `filehost-upload.service`; another `FILEHOST_SERVICE_USER` is
  refused unless `FILEHOST_CHANGE_ACCOUNT=1` is set as well;
- PHP-FPM when `php-fpm` is installed and no PHP module is enabled in Apache, so a re-run never
  adds `libapache2-mod-php`, which would move the whole server, every other site included, to
  prefork and mod_php;
- no `mariadb-server` when `config/config.local.php` exists and MariaDB is not installed;
- installed packages are not upgraded (`apt-get install --no-upgrade`), and existing `config/`,
  `uploads/` and `data/` keep their owner and mode.

### Service account

Both services run as `www-data` by default, which is also the user of the default PHP-FPM pool
and of every other site on the machine. On a shared host give the application its own account
and its own PHP-FPM pool, so that another site cannot read `config/config.local.php` (database
credentials and `APP_SECRET_KEY`), the stored files or `data/`. These commands replace step 1:

```bash
sudo adduser --system --group --no-create-home filehost
# The project root has to be traversable for that account and for Apache; config/, uploads/
# and data/ stay private (0750, owned by filehost).
sudo install -d -o "$USER" -g "$USER" -m 0711 /var/www/filehost
# Copy or clone the release into /var/www/filehost.
cd /var/www/filehost
sudo FILEHOST_SERVICE_USER=filehost FILEHOST_SERVICE_GROUP=filehost FILEHOST_PHP_SAPI=fpm \
    bash scripts/install-debian.sh
```

The `0750 $USER:www-data` root of step 1 would lock the account out: the units fail with
`200/CHDIR` and PHP-FPM answers "Primary script unknown". Do not add `www-data` to the `filehost`
group instead; that would open `config/` to the PHP of every other site.

Then create the site's pool, `/etc/php/8.4/fpm/pool.d/filehost.conf` (`8.2` on Debian 12), and
reload it with `sudo systemctl reload php8.4-fpm`:

```ini
[filehost]
user = filehost
group = filehost
listen = /run/php/filehost.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 16
; max_execution_time counts CPU time on Linux, so a worker blocked on a slow client is never
; freed by it. This wall-clock limit is the backstop; keep it above the 300 seconds the
; application allows an answer PHP streams itself (setting php_stream_max_seconds).
request_terminate_timeout = 360s
; Advertisement banners are uploaded to PHP (ads_max_banner_kb, 5 MiB by default), while
; Debian's php.ini stops at 2 MiB. File uploads go to the upload service and never depend on
; these two values.
php_admin_value[upload_max_filesize] = 8M
php_admin_value[post_max_size] = 12M
```

`php_admin_value[open_basedir]` can confine the pool further; it then has to include the
project, the temporary directory, the session directory (`session.save_path`) and any storage
moved out of the project ([STORAGE.md](STORAGE.md)).

`FILEHOST_SERVICE_USER` and `FILEHOST_SERVICE_GROUP` set `User=` and `Group=` of both units and
the owner of `config/`, `uploads/` and `data/`; Apache itself only needs to reach `public/`. Later
runs read the account back from the installed unit, so the variables are needed only the first
time. Moving an existing installation to another account needs `FILEHOST_CHANGE_ACCOUNT=1` as
well, and `sudo chown -R filehost:filehost uploads data config` before the services are restarted.

### Service sandbox

`filehost-upload.service` runs with `ProtectSystem=strict`: the whole file system is read-only
for the service except `uploads/` and `data/`, so a compromised process cannot modify the code,
the virtual environment or `config/`. It also drops every capability, filters system calls to the
ordinary service set, hides `/home`, `/root` and other users' processes, restricts address
families and namespaces, and is bounded to 65 536 open files, 512 tasks and 75 % of the machine's
RAM (an out-of-memory condition kills and restarts only this unit). `systemd-analyze security
filehost-upload.service` shows the resulting exposure.

If the application directory is below `/home` or `/root`, the installer uses
`ProtectHome=read-only` instead. When uploads, generated data or the quarantine live outside the
application directory (`UPLOADS_PATH`, `DATA_PATH`, `FILE_QUARANTINE_PATH` in
`config/config.local.php`, see [STORAGE.md](STORAGE.md)), the installer writes a drop-in
`/etc/systemd/system/filehost-upload.service.d/10-storage.conf` that opens exactly those paths for
writing. Run `sudo FILEHOST_UNITS_ONLY=1 bash scripts/install-debian.sh` after changing them,
including through **Settings → Storage** in the panel: a restart alone keeps the old drop-in, and
every upload to the new directory then fails with "read-only file system" while downloads still
work.

## 2. Configure Apache

Create `/etc/apache2/sites-available/filehost.conf`:

```apache
<VirtualHost *:80>
    ServerName files.example.com
    DocumentRoot /var/www/filehost/public

    <Directory /var/www/filehost/public>
        Options -Indexes
        AllowOverride All
        Require all granted
    </Directory>

    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto "http"

    ErrorLog ${APACHE_LOG_DIR}/filehost-error.log
    CustomLog ${APACHE_LOG_DIR}/filehost-access.log combined
</VirtualHost>
```

Then validate and enable it:

```bash
sudo a2ensite filehost.conf
sudo apachectl configtest
sudo systemctl reload apache2
```

Only `/var/www/filehost/public` may be served. Never point `DocumentRoot` at the project root.
For production, configure HTTPS and change the forwarded scheme to `https`. If another reverse
proxy terminates TLS, add only that proxy to `TRUSTED_PROXIES` and preserve the canonical Host.
The client address is then taken from `X-Forwarded-For`, read from the right past your own
proxies. A proxy that instead overwrites `CF-Connecting-IP` or `X-Real-IP` can be named with
`define('TRUSTED_PROXY_HEADER', 'CF-Connecting-IP');` — no other header is believed.

With PHP-FPM instead of `libapache2-mod-php` (`FILEHOST_PHP_SAPI=fpm`), route PHP to the pool of
this site inside the same `VirtualHost`:

```apache
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/filehost.sock|fcgi://localhost"
    </FilesMatch>
```

`php_flag` and `php_value` lines in `.htaccess` files are ignored under FPM, so PHP settings
belong in the pool (`php_admin_value[...]`) or in `php.ini`. `public/.htaccess` already restricts
its `Content-Security-Policy` fallback to static files because PHP emits its own.

PHP's upload limits matter only for advertisement banners, which PHP receives itself (up to
`ads_max_banner_kb`, 5 MiB by default), and Debian's `php.ini` allows 2 MiB. With PHP-FPM they are
set in the pool (see [Service account](#service-account)); with `libapache2-mod-php`, add them to
the `<Directory>` block above:

```apache
        php_admin_value upload_max_filesize 8M
        php_admin_value post_max_size 12M
```

File uploads are proxied to the upload service, which enforces the group and system size limits
itself; they do not depend on PHP's limits. For the same reason `public/.htaccess` lifts Apache's
request-body limit (1 GiB by default since Apache 2.4.54) for the two proxied upload routes, so
keep `AllowOverride All`, or copy those rules into the virtual host.

## 3. Prepare the database

TryHackX Files needs MySQL 8.0.1+ or MariaDB 10.6+ (Debian 12 and 13 ship MariaDB 10.11 and
11.8). The e-mail outbox claims rows with `SELECT ... FOR UPDATE SKIP LOCKED`, which older servers
reject; the installer's connection test refuses them with a message naming the version.

Use a dedicated database account:

```sql
CREATE DATABASE filehost CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'filehost'@'localhost' IDENTIFIED BY 'replace-with-a-random-password';
GRANT ALL PRIVILEGES ON filehost.* TO 'filehost'@'localhost';
FLUSH PRIVILEGES;
```

The web installer can create tables, but the database/user should be provisioned explicitly in
production.

## 4. Temporarily authorize the web installer

The installer requires a one-time random secret and a source-IP allowlist in PHP's environment.
Generate a secret:

```bash
openssl rand -hex 32
```

With PHP-FPM, PHP never sees Apache's environment, so put both values into the site's pool
(`/etc/php/8.4/fpm/pool.d/filehost.conf`) and reload it with `sudo systemctl reload php8.4-fpm`:

```ini
env[FILEHOST_INSTALL_TOKEN] = replace-with-the-generated-secret
env[FILEHOST_INSTALL_ALLOW_IPS] = 203.0.113.10/32
```

With `libapache2-mod-php`, PHP runs inside Apache: open `sudo systemctl edit apache2` and add a
temporary drop-in:

```ini
[Service]
Environment="FILEHOST_INSTALL_TOKEN=replace-with-the-generated-secret"
Environment="FILEHOST_INSTALL_ALLOW_IPS=203.0.113.10/32"
```

```bash
sudo systemctl daemon-reload
sudo systemctl restart apache2
```

Open `https://files.example.com/install.php`, enter the same secret and complete the English
wizard. Use the exact public URL as the canonical URL.

After success, remove the two lines again: from the pool, then reload PHP-FPM; or with
`sudo systemctl edit apache2`, then reload the daemon and restart Apache. Existing configuration
or installation lock keeps the installer closed even if the environment variable is
accidentally left behind.

## 5. Start services

```bash
sudo systemctl start filehost-upload filehost-mail-worker
sudo systemctl --no-pager --full status filehost-upload filehost-mail-worker
curl --fail http://127.0.0.1:8001/ready
```

The Python process binds only to `127.0.0.1:8001`. Do not expose that port publicly.

To reduce routine output:

```bash
sudoedit /etc/default/filehost
# Set: FILEHOST_MINIMAL_LOGS=1
sudo systemctl restart filehost-upload filehost-mail-worker
```

This disables uvicorn access logs and per-batch mail-worker output while preserving warnings and
errors.

The mail worker and Postfix have different jobs. TryHackX Files writes messages to a durable database
outbox; `filehost-mail-worker` drains it and submits each message through the transport selected
in **Settings → E-mail**. Postfix does not read the application database, so keep the systemd
worker enabled even when Postfix is installed.

On a host that runs its own MTA, select **Local mail server (SMTP on 127.0.0.1:25)** rather than
**PHP mail()**. The installed unit is hardened with `NoNewPrivileges=true`, and that flag strips
the setgid bit from `/usr/sbin/postdrop` on exec. `mail()` then reaches a helper that cannot write
to `/var/spool/postfix/maildrop`, warns, sleeps ten seconds and tries again forever: the call
never returns, the worker stays `active (running)` while delivering nothing, and the journal fills
with one identical warning every ten seconds. Submitting over a socket needs no privilege
transition and fails within seconds when the MTA is down, which is what the outbox retry schedule
expects.

Set `FILEHOST_LOCAL_MTA=host:port` in `/etc/default/filehost` if the local mail server does not
listen on `127.0.0.1:25`. Verify a working transport with:

```bash
sudo systemctl --no-pager --full status filehost-mail-worker
sudo journalctl -u filehost-mail-worker --since "10 min ago"
```

The unit reports liveness to systemd every few seconds (`WatchdogSec=120`). A delivery that
blocks anyway is killed and restarted instead of stalling the queue, and `systemctl status` shows
the current queue depth. The worker exits when the database is unreachable or the schema is not
ready; the unit retries every ten seconds without a start limit, so a database restart or a
migration never leaves it permanently `failed`.

### Choosing the worker's sandbox

`NoNewPrivileges=true` stops this service — and anything it spawns — from gaining privileges
through a setuid or setgid program, a file capability or an SELinux transition. It is the reason
a hypothetical foothold in the worker cannot reach for a local privilege-escalation helper. It
also applies to nothing else on the machine: Postfix, Dovecot, Apache and any other unit keep
their own settings, and the only interaction is that `sendmail` and `postdrop`, being children of
the worker, inherit it.

That is the whole trade. Keep it, and use a socket transport; or drop it, and `PHP mail()` may
use `postdrop` again:

```bash
sudo bash scripts/mail-worker-hardening.sh status
```

Run it from the application directory. `relaxed` writes a drop-in that clears the flag, `strict`
removes it again, and both reload and restart the service themselves — there is nothing else to
restart. `status` prints the configured flag and the one the running process actually has, which
are not the same thing after an edit that was never applied.

This cannot be a control in the panel. `no_new_privs` is one-way for the life of a process, so
only systemd can decide it, at exec time, from a unit file root owns; giving the web user the
ability to rewrite that file and restart the service would turn a panel compromise into control
of a root-owned unit. **Settings → E-mail** therefore reports the current state and prints the
command, and root runs it.

If a long-running worker is not desired, disable it and create
`/etc/cron.d/filehost-mail-worker` with exactly this system-cron entry instead:

```bash
sudo systemctl disable --now filehost-mail-worker
```

```cron
* * * * * www-data /usr/bin/php /var/www/filehost/scripts/mail-worker.php --limit=100 --quiet
```

## 6. Cleanup and health

Either enable the internal cleanup scheduler in **Administration → Settings → System**, or run
the same job as the service account from `/etc/cron.d/filehost-cleanup` (system cron format, like
the mail-worker entry above):

```cron
*/15 * * * * www-data /usr/bin/php /var/www/filehost/scripts/cleanup.php >/dev/null
```

Run maintenance scripts as that account (`sudo -u www-data php scripts/...`), not as root. A file
a script creates as root, with the private modes the application uses, cannot be read by the
upload service afterwards: a restored or repaired upload would stop downloading, and the cleanup
could no longer purge a quarantine copy. `cleanup.php`, `file-quarantine.php`, `mail-worker.php` and
`check-storage-integrity.php --repair-missing` therefore refuse root and print the command for the
account that owns the storage; `--allow-root` lets a deliberate run through (chown what it
creates afterwards).

Useful checks:

```bash
curl --fail https://files.example.com/api.php?action=health
curl --fail http://127.0.0.1:8001/ready
sudo -u www-data php scripts/check-db-integrity.php
sudo -u www-data php scripts/check-storage-integrity.php --json
journalctl -u filehost-upload -u filehost-mail-worker --since today
```

Back up the database, `uploads/`, `data/`, `config/config.local.php` and the stable
`APP_SECRET_KEY` as one consistent recovery point. See [MIGRATION.md](MIGRATION.md) and
[STORAGE.md](STORAGE.md).
