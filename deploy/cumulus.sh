#!/usr/bin/env bash

set -euo pipefail

ghcr_user="$1"
ghcr_token_base64="$2"
app_secret_arn="$3"
app_secret_key="$4"
image="$5"
nginx_config_base64="$6"
docker_config_dir='/tmp/spritz-docker-config'

rm -rf "$docker_config_dir"
install -d -m 0700 "$docker_config_dir"
printf '%s' "$ghcr_token_base64" | base64 -d | docker --config "$docker_config_dir" login ghcr.io --username "$ghcr_user" --password-stdin
docker --config "$docker_config_dir" pull "$image"
rm -rf "$docker_config_dir"

docker network inspect gaulatti-services >/dev/null 2>&1 || docker network create gaulatti-services
docker stop spritz >/dev/null 2>&1 || true
docker rm spritz >/dev/null 2>&1 || true
docker run -d \
  --name spritz \
  --network gaulatti-services \
  -p 127.0.0.1:3002:80 \
  -e AWS_REGION=us-east-1 \
  -e APP_SECRET_ARN="$app_secret_arn" \
  -e APP_SECRET_KEY="$app_secret_key" \
  -e ENV=production \
  --restart=always \
  --log-driver=awslogs \
  --log-opt awslogs-region=us-east-1 \
  --log-opt awslogs-group=/services/spritz \
  --log-opt "awslogs-stream=spritz-$(date +%Y%m%dT%H%M%S)" \
  -v spritz-uploads:/var/www/html/wordpress/wp-content/uploads \
  "$image"

for _ in $(seq 1 90); do
  if curl --fail --silent http://127.0.0.1:3002/wp-login.php >/dev/null; then
    break
  fi
  sleep 2
done
curl --fail --silent http://127.0.0.1:3002/wp-login.php >/dev/null

printf '%s' "$nginx_config_base64" | base64 -d > /tmp/cumulus-spritz.conf
install -m 0644 /tmp/cumulus-spritz.conf /etc/nginx/conf.d/cumulus-spritz.conf
rm -f /tmp/cumulus-spritz.conf
nginx -t
systemctl reload nginx
certbot --nginx --non-interactive --agree-tos --register-unsafely-without-email --redirect -d spritz.modoitaliano.fm
systemctl enable --now certbot-renew.timer
curl --fail --silent https://spritz.modoitaliano.fm/wp-login.php >/dev/null

echo SPRITZ_HEALTHY
