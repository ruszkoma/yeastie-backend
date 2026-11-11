#!/bin/sh
# Local end-to-end check: migrations with the migrations user, then real HTTP requests through
# public/index.php with the API user. Run with:
#   docker compose run --rm php sh tools/smoke.sh
set -eu
cd /app

KEYS=writable/dev
mkdir -p "$KEYS"
if [ ! -f "$KEYS/dev-private.pem" ]; then
  openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "$KEYS/dev-private.pem" 2>/dev/null
  openssl pkey -in "$KEYS/dev-private.pem" -pubout -out "$KEYS/dev-public.pem"
fi

# Settings for this run only; never a production .env.
export CI_ENVIRONMENT=development
export app_baseURL=http://127.0.0.1:8080/
export database_default_hostname=db database_default_database=yeastie
export database_default_username=yeastie_api database_default_password=api-local
export database_migrations_hostname=db database_migrations_database=yeastie
export database_migrations_username=yeastie_migrations database_migrations_password=migrations-local
export yeastie_firebaseProjectId=yeastie-dev
export yeastie_devPublicKeyFile=/app/$KEYS/dev-public.pem

php spark yeastie:migrate

php -S 127.0.0.1:8080 -t public public/index.php >/tmp/server.log 2>&1 &
SERVER=$!
trap 'kill $SERVER' EXIT
sleep 1

php tools/smoke.php http://127.0.0.1:8080/ "$KEYS/dev-private.pem" yeastie-dev db yeastie yeastie_api api-local
