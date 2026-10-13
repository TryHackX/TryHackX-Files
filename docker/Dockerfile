# TryHackX Files — one deployable unit: Apache/PHP + the Python upload server.
#
# Both processes intentionally live in one container: the PHP side proxies uploads,
# downloads, ZIPs, thumbnails and ShareX to the upload server over LOOPBACK
# (see public/.htaccess → 127.0.0.1:8001), and /metrics is loopback-only by design.
# Splitting them would mean rewriting those upstreams and exposing the metrics port.
# MySQL runs as its own service — see docker-compose.yml.

# PHP 8.5 on Debian 13 matches production (PHP 8.5, Python 3.13). The digest makes the build
# reproducible; Dependabot's docker ecosystem refreshes tag and digest together.
FROM php:8.5-apache-trixie@sha256:974e3a920309308e1690ed607e6cc3061d6906f90cc8d30fe519daa48bccf101

# PYTHONUNBUFFERED: the upload server's print() warnings must reach `docker compose logs` at once.
ENV DEBIAN_FRONTEND=noninteractive \
    PYTHONUNBUFFERED=1

# mbstring, curl, openssl and fileinfo are compiled into the official image; only the extensions
# the application really uses are added: pdo_mysql, and GD with JPEG, PNG, WebP and FreeType for
# advertisement banners (the cropper reads and writes WebP).
RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 python3-venv supervisor \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql gd \
    && php -m | grep -Fx mbstring \
    && php -m | grep -Fx curl \
    && php -r 'foreach (["imagecreatefromjpeg", "imagecreatefrompng", "imagecreatefromwebp", "imagewebp"] as $f) { if (!function_exists($f)) { fwrite(STDERR, "GD lacks $f\n"); exit(1); } }' \
    && a2enmod rewrite proxy proxy_http headers expires \
    && rm -rf /var/lib/apt/lists/*

# The official image ships without a php.ini, so PHP's built-in defaults apply (display_errors on,
# X-Powered-By on). Start from the production template and apply the container overrides.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php-overrides.ini /usr/local/etc/php/conf.d/zz-filehost.ini
COPY docker/apache-security.conf /etc/apache2/conf-available/filehost-security.conf
RUN a2enconf filehost-security

WORKDIR /app

# Python env for the upload server. The lock contains all transitive versions and hashes. It is
# installed BEFORE the sources are copied so that editing the code does not reinstall every
# dependency; requirements-lock.txt is the only file this layer depends on.
COPY requirements-lock.txt /app/requirements-lock.txt
RUN python3 -m venv /app/venv \
    && /app/venv/bin/python -m pip install --no-cache-dir --require-hashes \
        -r /app/requirements-lock.txt

COPY . /app

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/filehost.conf

# Runtime dirs are volume mount points (see compose); the installer writes config/.
RUN mkdir -p /app/uploads /app/data /app/config \
    && chmod 0755 /app/docker/wait-for-install.sh \
    && chown -R www-data:www-data /app/uploads /app/data /app/config

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r '$s=@fsockopen("127.0.0.1",80,$e,$m,2); exit($s===false?1:0);' \
        && php -r '$c=curl_init("http://127.0.0.1:8001/ready"); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3]); curl_exec($c); $s=curl_getinfo($c,CURLINFO_HTTP_CODE); exit($s===200?0:1);'

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/filehost.conf"]
