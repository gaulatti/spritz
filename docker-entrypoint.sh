#!/bin/bash
set -e

if [ -n "${APP_SECRET_ARN:-}" ]; then
  eval "$(php /usr/local/bin/load-secrets.php)"
fi

: "${DB_HOST:=db}"
: "${DB_NAME:=wordpress}"
: "${DB_USER:=wordpress}"
: "${DB_PASSWORD:=secret}"
: "${WP_HOME:=http://localhost:8080}"
: "${WP_SITEURL:=http://localhost:8080}"
: "${WP_TITLE:=CMS}"
: "${WP_DEBUG:=false}"
: "${ADMIN_USER:=admin}"
: "${ADMIN_PASSWORD:=admin}"
: "${ADMIN_EMAIL:=admin@example.com}"

# Wait for MySQL
until mysqladmin ping -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" --silent; do
  echo "Waiting for MySQL..."
  sleep 2
done
echo "MySQL is ready"

# Auto-install WordPress if not already installed
if ! wp core is-installed --path=/var/www/html/wordpress 2>/dev/null; then
  echo "Installing WordPress..."
  wp core install \
    --url="$WP_HOME" \
    --title="$WP_TITLE" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASSWORD" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email \
    --path=/var/www/html/wordpress

  # Activate plugins
  echo "Activating plugins..."
  wp plugin activate \
    advanced-custom-fields \
    amazon-s3-and-cloudfront \
    daggerhart-openid-connect-generic \
    --path=/var/www/html/wordpress 2>/dev/null || true

  # Activate theme
  echo "Activating theme..."
  wp theme activate headless-placeholder \
    --path=/var/www/html/wordpress 2>/dev/null || true

  echo "WordPress installed"
fi

exec /usr/bin/supervisord -c /etc/supervisord.conf
