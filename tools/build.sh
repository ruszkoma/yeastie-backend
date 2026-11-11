#!/bin/sh
# Builds the upload packages for the production host. Run inside the php container:
#   docker compose run --rm php sh tools/build.sh /home/<account>/yeastie_api
# The argument is the absolute path of the application directory on the server (outside the
# docroot). Output:
#   build/yeastie_api.tar.gz  -> unpack into that directory
#   build/public_api.tar.gz   -> unpack into <docroot of yeastie.cprojekt.pl>/api/
# Neither package contains .env, keys or development files.
set -eu
cd /app

APP_ROOT="${1:-}"
case "$APP_ROOT" in
  /*) ;;
  *) echo "Usage: sh tools/build.sh /absolute/path/to/yeastie_api" >&2; exit 2 ;;
esac

OUT=build/release
rm -rf "$OUT" build/yeastie_api.tar.gz build/public_api.tar.gz
mkdir -p "$OUT/yeastie_api" "$OUT/public_api"

# Application, without tests and tooling.
cp -R app "$OUT/yeastie_api/app"
cp spark composer.json composer.lock env.example "$OUT/yeastie_api/"
for dir in cache logs session uploads debugbar; do
  mkdir -p "$OUT/yeastie_api/writable/$dir"
  cp "writable/$dir/index.html" "$OUT/yeastie_api/writable/$dir/" 2>/dev/null || true
done
# spark changes into public/ next to it; the real front controller lives in the docroot.
mkdir -p "$OUT/yeastie_api/public"
printf 'Require all denied\n' > "$OUT/yeastie_api/public/.htaccess"
mkdir -p "$OUT/yeastie_api/secrets"
printf 'Require all denied\n' > "$OUT/yeastie_api/secrets/.htaccess"
printf 'Require all denied\n' > "$OUT/yeastie_api/.htaccess"

# Production dependencies only.
composer install --working-dir="$OUT/yeastie_api" --no-dev --optimize-autoloader --no-interaction --quiet

# Front controller pointing at the application directory.
cp deploy/api/.htaccess "$OUT/public_api/.htaccess"
sed "s|__YEASTIE_APP_ROOT__|$APP_ROOT|" deploy/api/index.php > "$OUT/public_api/index.php"

tar -czf build/yeastie_api.tar.gz -C "$OUT/yeastie_api" .
tar -czf build/public_api.tar.gz -C "$OUT/public_api" .
echo "Built build/yeastie_api.tar.gz and build/public_api.tar.gz for $APP_ROOT"
