# CLAUDE.md (backend)

Wytyczne dla Claude Code przy pracy nad API aplikacji Yeastie. Opis produktu i zasady całego projektu są w `../mobile/CLAUDE.md`; tutaj tylko to, co dotyczy backendu.

**Kod, nazwy, komentarze i commity po angielsku.** Ten plik i rozmowy mogą być po polsku.

## Zakres

API z kontraktu `../docs/api/openapi.yaml` (źródło prawdy dla klienta i backendu): `GET/POST /sync`, `GET /entitlement`, `POST /trial`, `POST /purchases/verify`, `POST /rtdn`, `DELETE /me`. Powiadomienia RTDN w produkcji pobiera cron `yeastie:rtdn-pull` (Pub/Sub pull), bo zapora hostingu odrzuca push; `POST /rtdn` zostaje jako druga droga. Zmiana zachowania API zaczyna się od zmiany kontraktu i klienta w `../mobile`.

## Stos

- PHP 8.5 (hosting bez `sodium`), **CodeIgniter 4.7** (decyzja z 2026-11-11, sprawdzony na PHP 8.5), MariaDB 11.4 przez `mysqli`.
- Zależności: tylko `codeigniter4/framework` i `firebase/php-jwt`. Google (Play Developer API, Identity Toolkit, OAuth konta usługi) przez `CURLRequest`, bez `google/apiclient`. Nową bibliotekę najpierw sprawdzamy w kontenerze PHP 8.5.
- Composera nie ma na serwerze: `vendor/` jedzie w paczce z `tools/build.sh`.

## Układ

```
app/
  Config/Yeastie.php       ustawienia API (wartości z .env, prefiks yeastie.)
  Config/Services.php      wiązanie zależności; szwy testowe: clock, firebaseKeys, oidcKeys, playDeveloperApi, pubSub, firebaseAdmin
  Config/Routes.php        grupa v1, filtry firebaseAuth / pubSubAuth
  Controllers/             cienkie: JSON, ApiProblem → problem+json
  Filters/                 FirebaseAuth (token, użytkownik, limit żądań), PubSubAuth (OIDC od Pub/Sub)
  Libraries/Auth/          JwtVerifier (tylko RS256), FirebaseTokenVerifier, PubSubTokenVerifier, GoogleKeySource
  Libraries/Google/        ServiceAccountTokens, PlayDeveloperApi, PubSub, FirebaseAdmin (interfejsy + implementacje)
  Libraries/Http/          ApiProblem, Problems, ProblemExceptionHandler
  Libraries/Time/          Clock, Iso (ISO 8601 UTC z ms ↔ DATETIME(3))
  Services/Sync/           SyncService (reguły scalania), ChangeIn, EntityTypes
  Services/Premium/        EntitlementService, PurchaseService, RtdnService
  Services/AccountService  DELETE /me
  Commands/                yeastie:migrate, yeastie:cleanup, yeastie:rtdn-pull (cron co 5 min), yeastie:google-check
  Database/Migrations/
deploy/api/                front controller i .htaccess dla <docroot>/api/
tools/                     build.sh, smoke.sh, smoke.php
tests/                     PHPUnit: feature/ (pełny stos na MariaDB), unit/, _support/ (fake'i, klucze testowe)
docker/                    obraz PHP jak na hostingu, init MariaDB
```

## Zasady

- **Użytkownik tylko z tokenu** (`sub` zweryfikowanego tokenu Firebase w `CurrentUser`), nigdy z parametrów ani treści. Każde zapytanie o dane użytkownika filtruje po `user_id`.
- Serwer nie interpretuje payloadów synchronizacji (poza `usage_counter.value`) i przyjmuje nieznane pola.
- Scalanie: dokumenty po `updated_at` (remis wygrywa przychodząca zmiana), przyrostowe tylko wstawienie i ustawienie `deleted_at`, licznik przez max. `client_change_id` daje idempotencję. Zapisy jednego użytkownika szereguje blokada `sync_user`, więc `seq` rośnie w kolejności commitów.
- Premium zapisuje tylko serwer: z weryfikacji w Google, RTDN i jednorazowego trialu. Klient nie ma jak go ustawić (`/sync` przyjmuje tylko typy z `EntityTypes`).
- Token zakupu należy do jednego konta (`obfuscatedExternalAccountId` = SHA-256 z UID). Po usunięciu konta token zostaje odpięty i nie przechodzi na inne konto.
- Oczekiwane błędy to `ApiProblem` → `application/problem+json` z kodem z kontraktu. Nieoczekiwane → 500 bez szczegółów.
- Nigdy nie logujemy tokenów, payloadów ani kluczy. Sekrety tylko w `.env` i `secrets/` poza katalogiem publicznym, poza repozytorium i poza czatem.
- Awaria Google daje 503 (`server_error`); `DELETE /me` jest wtedy wycofywane w całości.
- Zmiana schematu = nowa migracja (nigdy edycja wdrożonej). Migracje uruchamia `yeastie:migrate` kontem migracji (`database.migrations.*`), a gdy go nie ma (hosting z jednym użytkownikiem bazy, stan na 2026-11-11), kontem API. Bez SSH migracje idą przez jednorazowy cron.

## Komendy

```bash
docker compose up -d db
docker compose run --rm php composer test          # wszystkie testy
docker compose run --rm php sh tools/smoke.sh      # migracje + HTTP przez public/index.php, konto API
docker compose run --rm php sh tools/build.sh /home/<konto>/yeastie_api
```

Przed uznaniem zadania za skończone: `composer test` i `tools/smoke.sh`. Wdrożenie: `README_deploy.md`.

## Testy

- Feature testy idą przez cały stos (routing, filtry, kontrolery, MariaDB). Google zastępują fake'i z `tests/_support`, czas `FixedClock`, tokeny podpisuje `TestKeys`.
- Obowiązkowo: autoryzacja (zły podpis, `alg`, `iss`, `aud`, czas), izolacja użytkowników, brak zapisu premium przez klienta, reguły scalania, idempotencja, RTDN, usuwanie konta.
- `CODEIGNITER_SCREAM_DEPRECATIONS=1`: ostrzeżenia o przestarzałym kodzie z PHP 8.5 są błędami testów.

## Git

Conventional Commits, małe commity. Gałąź `main`.
