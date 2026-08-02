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

- installs Apache, MariaDB, PHP extensions and Python;
- installs hash-locked Python dependencies into `venv/`;
- creates private runtime directories;
- installs and enables (but does not start before configuration) the upload and mail systemd
  units;
- creates `/etc/default/filehost` with the logging switch.

Review the script before running it on an existing server. It does not create a database or
overwrite `config/config.local.php`.

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

## 3. Prepare the database

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

The installer requires a one-time random secret and a source-IP allowlist in the Apache service
environment. Generate a secret:

```bash
openssl rand -hex 32
sudo systemctl edit apache2
```

Add a temporary drop-in:

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

After success, remove the two environment lines with `sudo systemctl edit apache2`, reload the
daemon and restart Apache. Existing configuration or installation lock keeps the installer
closed even if the environment variable is accidentally left behind.

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
outbox; `filehost-mail-worker` drains it and submits mail through PHP `mail()`/Postfix or the SMTP
configuration selected in the panel. Postfix does not read the application database, so keep the
systemd worker enabled even when Postfix is installed.

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
the same job from cron:

```cron
*/15 * * * * /usr/bin/php /var/www/filehost/scripts/cleanup.php >/dev/null 2>&1
```

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
