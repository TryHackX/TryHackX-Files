# FileHost — one deployable unit: Apache/PHP + the Python upload server.
#
# Both processes intentionally live in one container: the PHP side proxies uploads,
# downloads, ZIPs, thumbnails and ShareX to the upload server over LOOPBACK
# (see public/.htaccess → 127.0.0.1:8001), and /metrics is loopback-only by design.
# Splitting them would mean rewriting those upstreams and exposing the metrics port.
# MySQL runs as its own service — see docker-compose.yml.

FROM php:8.2-apache

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        python3 python3-venv python3-pip supervisor \
        libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mysqli gd zip mbstring curl \
    && php -m | grep -Fx mbstring \
    && php -m | grep -Fx curl \
    && a2enmod rewrite proxy proxy_http headers \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

# Python env for the upload server. The lock contains all transitive versions and hashes.
RUN python3 -m venv /app/venv \
    && /app/venv/bin/python -m pip install --no-cache-dir --require-hashes \
        -r /app/requirements-lock.txt

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/filehost.conf

# Runtime dirs are volume mount points (see compose); the installer writes config/.
RUN mkdir -p /app/uploads /app/data /app/config \
    && chmod 0755 /app/docker/wait-for-install.sh \
    && chown -R www-data:www-data /app/uploads /app/data /app/config

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r '$s=@fsockopen("127.0.0.1",80,$e,$m,2); exit($s===false?1:0);' \
        && php -r '$c=curl_init("http://127.0.0.1:8001/ready"); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3]); curl_exec($c); $s=curl_getinfo($c,CURLINFO_HTTP_CODE); curl_close($c); exit($s===200?0:1);'

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/filehost.conf"]
