#!/bin/bash
set -e

if [ -r /root/.aws/credentials ]; then
  mkdir -p /var/www/.aws
  cp /root/.aws/credentials /var/www/.aws/credentials
  if [ -r /root/.aws/config ]; then
    cp /root/.aws/config /var/www/.aws/config
    chown www-data:www-data /var/www/.aws/config
    chmod 600 /var/www/.aws/config
    export AWS_CONFIG_FILE=/var/www/.aws/config
  fi
  chown www-data:www-data /var/www/.aws /var/www/.aws/credentials
  chmod 700 /var/www/.aws
  chmod 600 /var/www/.aws/credentials
  export AWS_SHARED_CREDENTIALS_FILE=/var/www/.aws/credentials
fi

if [ "${ENV:-}" = 'production' ] && [ -z "${APP_SECRET_ARN:-}" ]; then
  echo "APP_SECRET_ARN is required in production" >&2
  exit 1
fi

if [ -n "${APP_SECRET_ARN:-}" ]; then
  eval "$(php /usr/local/bin/load-secrets.php)"
fi

if [ "${ENV:-}" = 'production' ]; then
  : "${DB_HOST:?DB_HOST is required in production}"
  : "${DB_NAME:?DB_NAME is required in production}"
  : "${DB_USER:?DB_USER is required in production}"
  : "${DB_PASSWORD:?DB_PASSWORD is required in production}"
else
  : "${DB_HOST:=db}"
  : "${DB_NAME:=wordpress}"
  : "${DB_USER:=wordpress}"
  : "${DB_PASSWORD:=secret}"
fi
: "${WP_HOME:=http://localhost:8080}"
: "${WP_SITEURL:=http://localhost:8080}"
: "${WP_TITLE:=CMS}"
: "${WP_DEBUG:=false}"
: "${ADMIN_USER:=admin}"
: "${ADMIN_PASSWORD:=admin}"
: "${ADMIN_EMAIL:=admin@example.com}"

uploads_dir=/var/www/html/wordpress/wp-content/uploads

mkdir -p "$uploads_dir"
chown -R www-data:www-data "$uploads_dir"

wp_as_runtime_user() {
  su-exec www-data wp "$@"
}

mkdir -p /run/spritz
touch /run/spritz/metrics.json
chown www-data:www-data /run/spritz /run/spritz/metrics.json
chmod 770 /run/spritz
chmod 660 /run/spritz/metrics.json

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
if ! wp_as_runtime_user core is-installed --path=/var/www/html/wordpress 2>/dev/null; then
  echo "Installing WordPress..."
  wp_as_runtime_user core install \
    --url="$WP_HOME" \
    --title="$WP_TITLE" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASSWORD" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email \
    --path=/var/www/html/wordpress

  # Activate theme
  echo "Activating theme..."
  wp_as_runtime_user theme activate headless-placeholder \
    --path=/var/www/html/wordpress 2>/dev/null || true

  echo "WordPress installed"
fi

echo "Activating plugins..."
wp_as_runtime_user plugin activate \
  advanced-custom-fields \
  amazon-s3-and-cloudfront \
  daggerhart-openid-connect-generic \
  --path=/var/www/html/wordpress || true

echo "Backfilling standalone pages..."
wp_as_runtime_user eval '
  if (function_exists("spritz_backfill_standalone_pages")) {
      $count = spritz_backfill_standalone_pages();
      fwrite(STDOUT, "Standalone pages published: " . $count . PHP_EOL);
  }
' --path=/var/www/html/wordpress

echo "Verifying uploads path..."
wp_as_runtime_user eval '
  $upload = wp_get_upload_dir();
  if ($upload["error"] || !is_dir($upload["path"]) || !is_writable($upload["path"])) {
      fwrite(STDERR, "WordPress uploads path is not writable by the runtime user." . PHP_EOL);
      exit(1);
  }
' --path=/var/www/html/wordpress

exec /usr/bin/supervisord -c /etc/supervisord.conf
