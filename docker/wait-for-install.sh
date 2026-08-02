#!/bin/sh
set -eu

# The image is intentionally usable before the web installer has created its
# persistent configuration volume. Keep dependent long-running services idle
# instead of letting Supervisor exhaust start retries and mark them FATAL.
while [ ! -f /app/config/config.local.php ]; do
	sleep 2
done

exec "$@"
