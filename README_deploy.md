# Wdrożenie na hosting (cPanel, s8.netstrefa.pl)

Układ na serwerze:

```
~/yeastie_api/                      aplikacja, POZA katalogiem publicznym
    app/  vendor/  writable/  spark  .env  secrets/service-account.json
<docroot yeastie.cprojekt.pl>/api/  tylko index.php i .htaccess
```

Adres API: `https://yeastie.cprojekt.pl/api/v1/`.

## 1. Hosting (raz)

1. **PHP 8.5** dla domeny (MultiPHP Manager) z rozszerzeniami `mysqli`, `intl`, `mbstring`, `curl`, `openssl`. CodeIgniter łączy się z bazą przez **`mysqli`**, więc to rozszerzenie musi być włączone.
2. **MariaDB**: baza `<konto>_yeastie` i dwóch użytkowników:
   - API: `SELECT, INSERT, UPDATE, DELETE`,
   - migracje: dodatkowo `CREATE, ALTER, DROP, INDEX, REFERENCES`.
3. Sprawdź w cPanelu automatyczne kopie bazy (otwarta decyzja 2).

## 2. Google (raz)

Wszystko w projekcie Google Cloud powiązanym z Firebase `yeastie-99dca`.

1. **Konto usługi** (IAM → Service accounts), np. `yeastie-api@…`:
   - rola **Firebase Authentication Admin** (`roles/firebaseauth.admin`), potrzebna do `DELETE /me`,
   - klucz JSON → `~/yeastie_api/secrets/service-account.json` (uprawnienia `600`). Klucz nie trafia do repozytorium, czatu ani maila.
2. Włącz **Google Play Android Developer API** w tym projekcie.
3. **Play Console** → Users and permissions → zaproś e-mail konta usługi z uprawnieniami *View financial data* oraz *Manage orders and subscriptions* dla aplikacji `pl.cprojekt.yeastie`.
4. **RTDN** (Pub/Sub):
   - temat np. `play-rtdn`; nadaj `google-play-developer-notifications@system.gserviceaccount.com` rolę **Pub/Sub Publisher** na temacie,
   - subskrypcja **push** na `https://yeastie.cprojekt.pl/api/v1/rtdn` z włączonym uwierzytelnianiem: konto usługi do podpisu (np. `rtdn-push@…`) i **audience** = ten sam URL,
   - Play Console → Monetization setup → wpisz temat i wyślij powiadomienie testowe (API odpowie 204).
5. Wpisz do `.env`: `yeastie.pubsubAudience` (URL z punktu 4) i `yeastie.pubsubServiceAccountEmail` (konto podpisujące push).

## 3. Budowanie paczek (lokalnie)

```bash
docker compose run --rm php composer test
docker compose run --rm php sh tools/build.sh /home/<konto>/yeastie_api
```

Powstają `build/yeastie_api.tar.gz` (do `~/yeastie_api/`) i `build/public_api.tar.gz` (do `<docroot>/api/`).
Paczki nie zawierają `.env`, kluczy ani narzędzi deweloperskich.

## 4. Wgranie

- **FTPS** (jawne FTP przez TLS) na host **`s8.netstrefa.pl`** z weryfikacją certyfikatu. Certyfikat nie obejmuje `ftp.cprojekt.pl`, więc nie używaj tej nazwy.
  Przykład: `lftp -e "set ftp:ssl-force true; set ssl:verify-certificate yes; put build/yeastie_api.tar.gz; bye" -u <login> s8.netstrefa.pl`
- Rozpakuj w Menedżerze plików cPanelu (Extract): `yeastie_api.tar.gz` do `~/yeastie_api/`, `public_api.tar.gz` do `<docroot>/api/`.
- Przy aktualizacji nadpisz pliki; `.env`, `secrets/` i `writable/` zostają.

## 5. Konfiguracja

1. `~/yeastie_api/.env` na wzór `env.example` (uprawnienia `600`): oba konta bazy, `yeastie.firebaseProjectId = yeastie-99dca`, ścieżka klucza konta usługi, ustawienia Pub/Sub.
2. `~/yeastie_api/writable/` musi być zapisywalne dla PHP (cache kluczy Google, logi, limit żądań).

## 6. Migracje

Kontem migracji (`database.migrations.*` z `.env`):

```bash
cd ~/yeastie_api && php spark yeastie:migrate
```

Bez SSH (Terminal w cPanelu też wystarczy): jednorazowe zadanie cron z tym samym poleceniem, usunięte po wykonaniu.
`spark migrate -g` się do tego nie nadaje: tworzy tabelę `migrations` kontem API.

## 7. Cron

Raz dziennie (cPanel → Cron Jobs):

```
15 3 * * * cd ~/yeastie_api && php spark yeastie:cleanup >/dev/null 2>&1
```

Usuwa wpisy idempotencji synchronizacji i identyfikatory RTDN starsze niż 30 dni.

## 8. Sprawdzenie

```bash
curl -i https://yeastie.cprojekt.pl/api/v1/entitlement   # 401, application/problem+json
curl -i http://yeastie.cprojekt.pl/api/v1/entitlement    # 403 (tylko HTTPS)
```

Jeśli żądanie HTTPS dostaje 403, serwer nie ustawia `HTTPS` w `.htaccess`: wtedy zamień warunek na `RewriteCond %{HTTP:X-Forwarded-Proto} !=https`.

## 9. Aplikacja

Po wdrożeniu włącz sieć w aplikacji: `isNetworkEnabled = true` w `androidApp/.../YeastieApplication.kt` (adres `https://yeastie.cprojekt.pl/api/v1/` jest już ustawiony).
