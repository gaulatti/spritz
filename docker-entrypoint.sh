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

mysql_ready() {
  php -r '
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = mysqli_init();
    $flags = 0;
    if (defined("MYSQLI_CLIENT_SSL")) {
        $flags |= MYSQLI_CLIENT_SSL;
    }
    if (defined("MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT")) {
        $flags |= MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
    }
    if (!@$conn->real_connect(getenv("DB_HOST"), getenv("DB_USER"), getenv("DB_PASSWORD"), getenv("DB_NAME"), null, null, $flags)) {
        fwrite(STDERR, "MySQL connection failed: " . $conn->connect_error . PHP_EOL);
        exit(1);
    }
    $conn->close();
  '
}

# Wait for MySQL using PHP mysqli, the same client path WordPress uses.
until mysql_ready; do
  echo "Waiting for MySQL..."
  sleep 10
done
echo "MySQL is ready"

# Auto-install WordPress if not already installed
if ! wp core is-installed --allow-root --path=/var/www/html/wordpress 2>/dev/null; then
  echo "Installing WordPress..."
  wp core install \
    --url="$WP_HOME" \
    --title="$WP_TITLE" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASSWORD" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email \
    --allow-root \
    --path=/var/www/html/wordpress

  # Activate theme
  echo "Activating theme..."
  wp theme activate headless-placeholder \
    --allow-root \
    --path=/var/www/html/wordpress 2>/dev/null || true

  echo "WordPress installed"
fi

echo "Activating plugins..."
wp plugin activate \
  advanced-custom-fields \
  amazon-s3-and-cloudfront \
  daggerhart-openid-connect-generic \
  --allow-root \
  --path=/var/www/html/wordpress || true

exec /usr/bin/supervisord -c /etc/supervisord.conf
