# Wdrożenie na hosting (cPanel, s8.netstrefa.pl)

Układ na serwerze:

```
~/yeastie_backend/                      aplikacja, POZA katalogiem publicznym
    app/  vendor/  writable/  spark  .env  secrets/service-account.json
<docroot yeastie.cprojekt.pl>/api/  tylko index.php i .htaccess
```

Adres API: `https://yeastie.cprojekt.pl/api/v1/`.

## 1. Hosting (raz)

1. **PHP 8.5** dla domeny (MultiPHP Manager) z rozszerzeniami `mysqli`, `intl`, `mbstring`, `curl`, `openssl`. CodeIgniter łączy się z bazą przez **`mysqli`**, więc to rozszerzenie musi być włączone.
2. **MariaDB**: baza `<konto>_yeastie` i użytkownik z **ALL PRIVILEGES** do niej. Jeśli hosting pozwala na drugiego użytkownika, można rozdzielić konta: API tylko `SELECT, INSERT, UPDATE, DELETE`, migracje dodatkowo `CREATE, ALTER, DROP, INDEX, REFERENCES` (`database.migrations.*` w `.env`).
3. Sprawdź w cPanelu automatyczne kopie bazy (otwarta decyzja 2).

## 2. Google (raz)

Wszystko w projekcie Google Cloud powiązanym z Firebase `yeastie-99dca`.

1. **Konto usługi** (IAM → Service accounts), np. `yeastie-api@…`:
   - rola **Firebase Authentication Admin** (`roles/firebaseauth.admin`), potrzebna do `DELETE /me`,
   - klucz JSON → `~/yeastie_backend/secrets/service-account.json` (uprawnienia `600`). Klucz nie trafia do repozytorium, czatu ani maila.
2. Włącz **Google Play Android Developer API** w tym projekcie.
3. **Play Console** → Users and permissions → zaproś e-mail konta usługi z uprawnieniami *View financial data* oraz *Manage orders and subscriptions* dla aplikacji `pl.cprojekt.yeastie`.
4. **RTDN** (Pub/Sub, tryb **pull**). Zapora hostingu odrzuca żądania push z Google Cloud (sprawdzone 2026-11-12: timeout połączenia, brak wpisów w logu dostępu, także z regionów europejskich), więc API samo pobiera powiadomienia cronem `yeastie:rtdn-pull`:
   - temat `play-rtdn`; nadaj `google-play-developer-notifications@system.gserviceaccount.com` rolę **Pub/Sub Publisher** na temacie,
   - subskrypcja `play-rtdn-pull` typu **pull**, termin potwierdzenia 60 s,
   - na tej subskrypcji nadaj kontu usługi API (`yeastie-api@…`) rolę **Pub/Sub Subscriber** (`roles/pubsub.subscriber`),
   - Play Console → Monetization setup → wpisz temat `projects/yeastie-99dca/topics/play-rtdn` i wyślij powiadomienie testowe.
5. Wpisz do `.env`: `yeastie.pubsubSubscription = projects/yeastie-99dca/subscriptions/play-rtdn-pull`. Punkt `POST /rtdn` (push, `yeastie.pubsubAudience` i `yeastie.pubsubServiceAccountEmail`) zostaje w kodzie na wypadek hostingu bez tej blokady; bez tych wpisów odrzuca każde żądanie.
6. Sprawdzenie (jednorazowe zadanie cron, wynik w logu; nie wypisuje tokenów ani klucza, nie dotyka prawdziwych kont ani zakupów):
   ```
   cd ~/yeastie_backend && /opt/cpanel/ea-php85/root/usr/bin/php spark yeastie:google-check > writable/logs/google-check.log 2>&1
   ```

## 3. Budowanie paczek (lokalnie)

```bash
docker compose run --rm php composer test
docker compose run --rm php sh tools/build.sh /home/ns55565/yeastie_backend
```

Powstają `build/yeastie_api.tar.gz` (do `~/yeastie_backend/`) i `build/public_api.tar.gz` (do `<docroot>/api/`).
Paczki nie zawierają `.env`, kluczy ani narzędzi deweloperskich.

## 4. Wgranie

- **FTPS** (jawne FTP przez TLS) na host **`s8.netstrefa.pl`** z weryfikacją certyfikatu. Certyfikat nie obejmuje `ftp.cprojekt.pl`, więc nie używaj tej nazwy.
  Przykład: `lftp -e "set ftp:ssl-force true; set ssl:verify-certificate yes; put build/yeastie_api.tar.gz; bye" -u <login> s8.netstrefa.pl`
- Rozpakuj w Menedżerze plików cPanelu (Extract): `yeastie_api.tar.gz` do `~/yeastie_backend/`, `public_api.tar.gz` do `<docroot>/api/`.
- Przy aktualizacji nadpisz pliki; `.env`, `secrets/` i `writable/` zostają.

## 5. Konfiguracja

1. `~/yeastie_backend/.env` na wzór `env.example` (uprawnienia `600`): konto bazy, `yeastie.firebaseProjectId = yeastie-99dca`, ścieżka klucza konta usługi, ustawienia Pub/Sub.
2. `~/yeastie_backend/writable/` musi być zapisywalne dla PHP (cache kluczy Google, logi, limit żądań).

## 6. Migracje

Kontem migracji (`database.migrations.*` z `.env`, a bez nich kontem API). Z SSH lub Terminala w cPanelu:

```bash
cd ~/yeastie_backend && php spark yeastie:migrate
```

**Bez SSH:** jednorazowe zadanie cron na najbliższą minutę, potem usunięte:

```
cd ~/yeastie_backend && /opt/cpanel/ea-php85/root/usr/bin/php spark yeastie:migrate > writable/logs/migrate.log 2>&1
```

W `writable/logs/migrate.log` ma być `Migrations are up to date.`

**PHP w cronie:** na s8.netstrefa.pl samo `php` w cronie to `/usr/bin/php`, czyli **php-cgi**, a `spark` wymaga php-cli („The cli tool is not supported when running php-cgi”). Zawsze podawaj pełną ścieżkę **`/opt/cpanel/ea-php85/root/usr/bin/php`** (sprawdzone 2026-11-11: cli 8.5.12), w migracjach i w cronie sprzątającym.
`spark migrate -g` się do tego nie nadaje: tworzy tabelę `migrations` kontem domyślnym.

## 7. Cron

Raz dziennie (cPanel → Cron Jobs):

```
15 3 * * * cd ~/yeastie_backend && /opt/cpanel/ea-php85/root/usr/bin/php spark yeastie:cleanup >/dev/null 2>&1
```

Usuwa wpisy idempotencji synchronizacji i identyfikatory RTDN starsze niż 30 dni.

Co 5 minut:

```
*/5 * * * * cd ~/yeastie_backend && /opt/cpanel/ea-php85/root/usr/bin/php spark yeastie:rtdn-pull > writable/logs/rtdn-pull.log 2>&1
```

Pobiera powiadomienia Google Play (odnowienia, anulowania, zwroty) z Pub/Sub i aktualizuje uprawnienia. Log zawiera wynik ostatniego uruchomienia (`Applied N notifications.`), bez tokenów. Zakup nie zależy od tego zadania: aplikacja potwierdza go od razu przez `POST /purchases/verify`. Pub/Sub trzyma niepotwierdzone wiadomości 7 dni.

## 8. Sprawdzenie

```bash
curl -i https://yeastie.cprojekt.pl/api/v1/entitlement   # 401, application/problem+json
curl -i http://yeastie.cprojekt.pl/api/v1/entitlement    # 403 (tylko HTTPS)
```

Jeśli żądanie HTTPS dostaje 403, serwer nie ustawia `HTTPS` w `.htaccess`: wtedy zamień warunek na `RewriteCond %{HTTP:X-Forwarded-Proto} !=https`.

## 9. Aplikacja

Po wdrożeniu włącz sieć w aplikacji: `isNetworkEnabled = true` w `androidApp/.../YeastieApplication.kt` (adres `https://yeastie.cprojekt.pl/api/v1/` jest już ustawiony).
