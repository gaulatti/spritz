#!/usr/bin/env bash

set -euo pipefail

image="${1:-spritz:test}"
suffix="$$"
network_name="spritz-uploads-test-network-${suffix}"
database_name="spritz-uploads-test-db-${suffix}"
application_name="spritz-uploads-test-app-${suffix}"
volume_name="spritz-uploads-test-volume-${suffix}"
upload_subdirectory="$(date -u +%Y/%m)"

cleanup() {
  docker rm -f "$application_name" "$database_name" >/dev/null 2>&1 || true
  docker volume rm "$volume_name" >/dev/null 2>&1 || true
  docker network rm "$network_name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker network create "$network_name" >/dev/null
docker volume create "$volume_name" >/dev/null

docker run --rm \
  --volume "$volume_name:/uploads" \
  --entrypoint sh \
  "$image" \
  -c 'mkdir -p "/uploads/$1" && chown -R root:root /uploads && chmod 755 /uploads "/uploads/$1"' \
  sh "$upload_subdirectory"

docker run -d \
  --name "$database_name" \
  --network "$network_name" \
  --env MYSQL_ROOT_PASSWORD=root \
  --env MYSQL_DATABASE=wordpress \
  --env MYSQL_USER=wordpress \
  --env MYSQL_PASSWORD=secret \
  mysql:8.4 >/dev/null

docker run -d \
  --name "$application_name" \
  --network "$network_name" \
  --env DB_HOST="$database_name" \
  --env DB_NAME=wordpress \
  --env DB_USER=wordpress \
  --env DB_PASSWORD=secret \
  --env TRANSLATION_WORKER_DISABLED=1 \
  --volume "$volume_name:/var/www/html/wordpress/wp-content/uploads" \
  "$image" >/dev/null

for _ in $(seq 1 120); do
  if ! docker inspect --format '{{.State.Running}}' "$application_name" 2>/dev/null | grep -q true; then
    docker logs "$application_name"
    echo "Spritz stopped before becoming ready" >&2
    exit 1
  fi
  if docker exec "$application_name" curl --fail --silent http://127.0.0.1/wp-login.php >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

docker exec "$application_name" curl --fail --silent http://127.0.0.1/wp-login.php >/dev/null

uploads_path="/var/www/html/wordpress/wp-content/uploads/${upload_subdirectory}"
runtime_owner="$(docker exec "$application_name" sh -c 'printf "%s:%s" "$(id -u www-data)" "$(id -g www-data)"')"
uploads_owner="$(docker exec "$application_name" stat -c '%u:%g' "$uploads_path")"

if [ "$uploads_owner" != "$runtime_owner" ]; then
  echo "Expected $uploads_path to be owned by $runtime_owner; found $uploads_owner" >&2
  exit 1
fi

docker exec --user www-data "$application_name" test -w "$uploads_path"

echo "uploads startup ownership test passed"
