#!/bin/sh
set -e

# Configure Apache port dynamically from Render's $PORT (defaults to 80)
PORT="${PORT:-80}"
sed -i "s/Listen [0-9]*/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost \*:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

# Ensure permissions on writable directories at container startup
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# Cache Laravel configuration and routes with runtime environment variables provided by Render
echo "Caching configuration and routes with runtime environment..."
php artisan config:cache || echo "Warning: config:cache failed"
php artisan route:cache || echo "Warning: route:cache failed"

echo "Starting Apache on port ${PORT}..."
exec apache2-foreground "$@"
