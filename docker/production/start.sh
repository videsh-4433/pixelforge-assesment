#!/bin/sh
set -e

# Cache config/routes/views now that the real environment variables are available.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force

# Background processes, restarted if they exit. Free hosts give us one container, so the
# queue worker (submits generations) and scheduler (polls for lost webhooks) live here too.
(while true; do php artisan queue:work --sleep=1 --max-time=3600 || true; sleep 1; done) &
(while true; do php artisan schedule:work || true; sleep 1; done) &

exec frankenphp php-server --root /app/public --listen ":${PORT:-10000}"
