# Yeastie API

Backend of the Yeastie mobile app: sync of user data, premium entitlement, Google Play purchase
verification, RTDN and account deletion. The contract is `../docs/api/openapi.yaml`.

- PHP 8.5, CodeIgniter 4.7, MariaDB 11.4 (mysqli), `firebase/php-jwt`. No `sodium`.
- Runs on shared cPanel hosting; see `README_deploy.md`.
- Working rules for contributors and Claude: `CLAUDE.md`.

## Local development (Docker)

```bash
docker compose up -d db                               # MariaDB 11.4 with local users
docker compose run --rm php composer install
docker compose run --rm php composer test             # PHPUnit against the test database
docker compose run --rm php sh tools/smoke.sh         # migrations + real HTTP requests
docker compose run --rm php sh tools/build.sh /home/<account>/yeastie_api   # upload packages
```
