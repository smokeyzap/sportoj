# training-api (fase 1a)

Tijdelijke PHP REST API (`/api/v1`) bovenop de definitieve MariaDB-database van de Trainingsapp. De bron van waarheid is
`../docs/spec/` (BUSINESS_RULES, openapi.yaml, schema.sql, seed.sql, ACCEPTANCE_TESTS, ...). Dit project implementeert die spec;
een latere Laravel-versie moet een drop-in vervanger kunnen zijn.

- PHP **8.2+** (`pdo_mysql`, `mbstring`, `openssl`; Argon2id aanbevolen), MariaDB **10.6+** (InnoDB, utf8mb4).
- Runtime heeft **geen** composer-packages nodig (eigen autoloader als `vendor/` ontbreekt). `composer install` is voor tests.
- Overzicht van gedrag, keuzes en beperkingen: [`IMPLEMENTATION_NOTES.md`](IMPLEMENTATION_NOTES.md).
- Open vragen over het OpenAPI-contract: [`docs/OPENAPI_GAPS.md`](docs/OPENAPI_GAPS.md).
- Resultaat per acceptatietest: [`docs/ACCEPTANCE_RESULTS.md`](docs/ACCEPTANCE_RESULTS.md).

## Structuur

```text
app/            Http (Kernel, Router, Request/Response), Auth, Controllers, Services (domeinlogica), Repositories (SQL),
                Presenters (JSON-vormen), Install, Support
bootstrap/      autoload + applicatie
bin/            install.php, create-user.php, cleanup.php (alleen CLI)
database/       schema.sql (baseline 1.0.0, zie noot), seed.sql (dataset 1.0.0)
public/         index.php (front controller), install.php, .htaccess  <- enige webroot
scripts/        backup_database.sh, restore_verify.sh
storage/        logs/, ratelimit/, install.key (tijdelijk), installed.lock   <- buiten de webroot houden
tests/          Unit/, Acceptance/, Support/, acceptance_report.php
```

## Installatie op een server

Geen SSH? Zie [`docs/INSTALL_WITHOUT_SSH.md`](docs/INSTALL_WITHOUT_SSH.md) (FTP/bestandsbeheer + browser-installer).

1. Zet de code buiten de publieke map; alleen `public/` is de webroot (HTTPS verplicht in productie).
2. `cp .env.example .env` (liefst buiten de webroot en met `ENV_FILE=/pad/.env`), vul database en `FRONTEND_ORIGINS` in.
   Geheimen horen niet in Git. De belangrijkste variabelen staan in `.env.example`; alle zijn ook als echte omgevingsvariabele te zetten.
3. Maak `storage/logs` en `storage/ratelimit` schrijfbaar voor de webserver.
4. Kies **één** installatieroute (de installer sluit zichzelf met `storage/installed.lock`; er is geen reset-functie):
   - **CLI (aanbevolen)**
     ```bash
     INSTALL_ADMIN_PASSWORD='...' php bin/install.php --name="Naam" --email=naam@example.nl --timezone=Europe/Amsterdam
     # of: ... --password-stdin   (het wachtwoord staat nooit in argv)
     ```
   - **Browser**: `php bin/install.php --generate-key` maakt `storage/install.key` (mode 0600); open `https://api.../install.php`, voer de
     sleutel in, controleer het systeemrapport en vul de eerste gebruiker in. Zonder sleutelbestand of na installatie geeft `install.php` een neutrale 404.
   De installer voert `schema.sql` uit op een **lege** database, daarna `seed.sql`, maakt de gebruiker en verifieert (schema 1.0.0, dataset 1.0.0,
   5 blokken, 30 workouts, 143 oefenregels). Een niet-lege of onbekende database wordt geweigerd zonder iets aan te passen.
5. Meer gebruikers: `INSTALL_ADMIN_PASSWORD=... php bin/create-user.php --name=... --email=...` (er is bewust geen registratie-endpoint).
6. Controle: `curl https://api.../health` -> `{"status":"ok","schema_version":"1.0.0","dataset_version":"1.0.0"}`.

Webserver: alles naar `public/index.php`.

```nginx
location / { try_files $uri /index.php?$query_string; }
location ~ ^/(index|install)\.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php-fpm.sock; }
```

Apache gebruikt `public/.htaccess`. Achter een eigen reverse proxy: `TRUST_PROXY_HEADERS=true` (laatste `X-Forwarded-For`-hop voor rate limits,
`X-Forwarded-Proto` voor de HTTPS-controle); zet dit nooit aan zonder zo'n proxy.

Cookies: frontend en API bij voorkeur onder hetzelfde registrable domain (`SameSite=Lax`). `SESSION_COOKIE_SAMESITE` is configureerbaar
(`None` vereist `SESSION_COOKIE_SECURE=true`). Er is geen login-CSRF (bewuste keuze).

### Cron

```cron
15 3 * * *  cd /pad/training-api && scripts/backup_database.sh   >> /pad/logs/backup.log 2>&1
45 3 * * *  cd /pad/training-api && php bin/cleanup.php          >> /pad/logs/cleanup.log 2>&1
```

## Backup en restore

`scripts/backup_database.sh` leest `DB_*` en `BACKUP_*` uit de omgeving/`.env` en schrijft naar `BACKUP_DIR` (absoluut, buiten de webroot):
`daily/`, `weekly/` (zondag), `monthly/` (1e van de maand), elk met `.sha256`. Een back-up telt pas als dump- en gzip-exitcode, minimumgrootte,
`gzip -t`, aanwezigheid van `CREATE TABLE`/`INSERT` en de checksum kloppen; pas daarna wordt retentie toegepast (14/8/6, configureerbaar).
`BACKUP_REMOTE_COMMAND` kopieert elk herstelpunt extern (variabelen `BACKUP_FILE`, `BACKUP_TIER`); faalt dat, dan blijft alle lokale retentie staan en eindigt het script met code 3.

```bash
# restoretest (minimaal maandelijks): nooit over productie, doeldatabase moet leeg zijn en "test"/"restore" in de naam hebben
RESTORE_DB_DATABASE=trainingsapp_restore_test RESTORE_CREATE_DB=1 RESTORE_DROP_AFTER=1 \
  scripts/restore_verify.sh /pad/secure-backups/daily/trainingsapp_20260817T210000Z_schema-1.0.0.sql.gz
```

Het resultaat wordt ook in `$BACKUP_DIR/restore-tests.log` vastgelegd. Een kopie op een tweede locatie is deployment-configuratie (geen standaard aanwezig).

## Ontwikkelen en testen

```bash
composer install
# Een MariaDB 10.6+ waarop de gebruiker databases mag aanmaken/verwijderen. LET OP: de suite DROPT en herbouwt databases waarvan de naam "test" bevat
# (training_test, training_test_install, training_test_restore); een andere naam wordt geweigerd.
export TEST_DB_HOST=127.0.0.1 TEST_DB_USERNAME=... TEST_DB_PASSWORD=... TEST_DB_DATABASE=training_test
vendor/bin/phpunit                                  # unit + acceptance
vendor/bin/phpunit --log-junit build/junit.xml && php tests/acceptance_report.php build/junit.xml   # per-AT overzicht
```

Voor de backup/restore-tests zijn `mariadb`, `mariadb-dump`, `gzip` en `sha256sum` nodig; ontbreken ze, dan worden die tests overgeslagen (zichtbaar in de uitvoer).
Lokale dev-server: `ENV_FILE=/pad/dev.env php -S 127.0.0.1:8080 -t public public/index.php`.

CI: `.github/workflows/training-api.yml` draait lint en de suite tegen een MariaDB-service (PHP 8.2/MariaDB 10.6 en PHP 8.3/MariaDB 11.4).

## Endpoints

Zie `../docs/spec/openapi.yaml` (23 operaties + `/health`). De test `ContractTest` bewaakt dat routes en spec dezelfde verzameling vormen en
dat elke response in de suite het schema volgt.
