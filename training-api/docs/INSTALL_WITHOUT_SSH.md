# Installeren zonder SSH (FTP / bestandsbeheer + browser)

Voor hosting waar je alleen een controlepaneel (cPanel, Plesk, DirectAdmin, ...) en FTP hebt. Je hebt nodig:

- een domein of subdomein voor de API, met **HTTPS** (bijvoorbeeld `api-train.jouwdomein.nl`);
- een lege **MariaDB-database** (10.6+, utf8mb4) met gebruiker en wachtwoord, aan te maken in het paneel (vaak "MySQL-databases");
- **PHP 8.2 of hoger** voor dat domein (in het paneel meestal "PHP-versie kiezen"), met de extensies `pdo_mysql`, `mbstring`, `openssl` aan.
  `sodium`/Argon2 is aanbevolen; de installer meldt of het beschikbaar is.

> De installer en de API zijn lokaal en in CI getest, maar **niet op een echte shared host**. Loop de controles hieronder dus echt na.

## 1. Bestanden klaarzetten (op je eigen computer)

1. Download de code als ZIP: GitHub, pull request #1 of de branch `claude/trainingsapp-phase-1a-api-se66on`, knop **Code > Download ZIP**.
2. Pak uit. Je hebt alleen de map `training-api/` nodig.
3. Je hoeft `tests/`, `docs/` en `vendor/` niet te uploaden (de API heeft geen composer-packages nodig).

## 2. Uploaden

Upload de map `training-api/` **naast** je publieke map, niet erin. Bijvoorbeeld:

```text
/home/jouwaccount/training-api/        <- hier komt alles
/home/jouwaccount/public_html/         <- bestaande sitemap, laat staan
```

**Zet daarna de document root van het API-(sub)domein op `training-api/public`.**
In cPanel: *Domeinen* of *Subdomeinen* > document root wijzigen naar `training-api/public`.
Alleen de map `public/` mag via het web bereikbaar zijn; daarin staan `index.php` en `install.php`. Alles daarbuiten (`.env`, `storage/`, `database/`) mag nooit publiek zijn.

Kan je de document root niet wijzigen? Zie "Als de document root niet kan" onderaan.

## 3. Rechten

Zet in de bestandsbeheerder de mappen `storage/`, `storage/logs/` en `storage/ratelimit/` op schrijfbaar (meestal 755; als de installer er toch over klaagt: 775).

## 4. Configuratie (`.env`)

1. Maak in `training-api/` een bestand `.env` (kopieer `.env.example` en hernoem het; verborgen bestanden moeten zichtbaar staan in de bestandsbeheerder).
2. Pas minimaal aan:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api-train.jouwdomein.nl
FRONTEND_ORIGINS=https://train.jouwdomein.nl

DB_HOST=localhost
DB_DATABASE=<naam van je database>
DB_USERNAME=<databasegebruiker>
DB_PASSWORD=<wachtwoord>

SESSION_COOKIE_DOMAIN=api-train.jouwdomein.nl
SESSION_COOKIE_SECURE=true
SESSION_COOKIE_SAMESITE=Lax
```

   **Verwijder de regel `LOG_PATH=...` uit het voorbeeldbestand** (of zet er een bestaand pad), want het voorbeeldpad bestaat niet. Zonder die regel logt de API naar `storage/logs/app.log`.
   Verwijder ook de `BACKUP_*`-regels als je de back-up (stap 8) niet via `.env` instelt.
   `DB_HOST` is op de meeste hosts `localhost`; staat er in het paneel een andere hostnaam, gebruik die.
   `FRONTEND_ORIGINS` is het adres van je frontend (nog niet gebouwd; vul in wat je straks gebruikt). Geen `*`, geen pad.
3. Het `.env`-bestand bevat geheimen: nooit committen, nooit publiek zetten.

## 5. Installatiesleutel plaatsen

De installer is standaard **uit**: zonder sleutelbestand geeft `install.php` een gewone 404. Zo kan niemand anders hem openen.

1. Verzin een lange willekeurige tekst (minimaal 32 tekens, bijvoorbeeld uit een wachtwoordmanager).
2. Maak `training-api/storage/install.key` met alleen die tekst als inhoud.

## 6. Installeren in de browser

1. Open `https://api-train.jouwdomein.nl/install.php`.
2. Voer de sleutel uit stap 5 in. Je krijgt het **systeemrapport**; kijk of alles op OK staat (Argon2 mag een waarschuwing zijn; dan wordt bcrypt gebruikt).
3. Vul naam, e-mailadres, wachtwoord (minimaal 12 tekens, twee keer) en tijdzone in en kies **Installeer**.
4. De installer maakt de tabellen aan, laadt de trainingscontent, maakt je gebruiker, controleert alles (schema 1.0.0, dataset 1.0.0, 5 blokken, 30 workouts, 143 oefenregels) en sluit zichzelf:
   - `storage/installed.lock` wordt geschreven;
   - `storage/install.key` wordt verwijderd;
   - `install.php` geeft vanaf nu een 404. Er is geen reset-functie.

Een **niet-lege database wordt geweigerd** zonder iets te wijzigen. Gebruik dus een nieuwe, lege database.

## 7. Controleren

1. `https://api-train.jouwdomein.nl/health` moet tonen:
   `{"status":"ok","schema_version":"1.0.0","dataset_version":"1.0.0"}`
2. Deze adressen moeten een **404** (of "niet beschikbaar") geven, nooit inhoud:
   `/.env`, `/storage/logs/app.log`, `/install.php`
   Zie je daar wel inhoud, dan staat je document root verkeerd: haal de bestanden direct weg en los dat eerst op.
3. Inloggen testen vanaf je eigen computer (Windows 10+ en macOS hebben `curl`; vervang adres en gegevens):
   ```bash
   curl -i -X POST https://api-train.jouwdomein.nl/api/v1/auth/login \
     -H "Content-Type: application/json" \
     -d '{"email":"jij@example.nl","password":"jouw-wachtwoord"}'
   ```
   Je moet `200` zien met een `Set-Cookie: training_session=...; HttpOnly; ... Secure`.

## 8. Dagelijks onderhoud zonder SSH

Maak in het paneel (**Cron Jobs**) twee taken. Pas de paden aan; gebruik het PHP-pad dat je host aanbeveelt (vaak `/usr/local/bin/php` of `php`).

| Wat | Tijd | Opdracht |
|---|---|---|
| Opschonen verlopen sessies | dagelijks 03:45 | `php /home/jouwaccount/training-api/bin/cleanup.php` |
| Database-back-up | dagelijks 03:15 | `BACKUP_DIR=/home/jouwaccount/secure-backups/trainingsapp /bin/bash /home/jouwaccount/training-api/scripts/backup_database.sh` |

Voor de back-up moet `mariadb-dump` of `mysqldump` op de host staan (vraag het je host als het script dat meldt) en `BACKUP_DIR` moet **buiten** de publieke map liggen.
Een back-up telt pas echt zodra er ook een kopie op een **andere plek** staat en je een restore hebt getest (zie README, "Backup en restore"). Alleen een export via phpMyAdmin is niet genoeg.

**Extra gebruiker aanmaken** (er is geen registratie in de API): maak tijdelijk een eenmalige cron-taak:
`INSTALL_ADMIN_PASSWORD='wachtwoord-van-minimaal-12-tekens' php /home/jouwaccount/training-api/bin/create-user.php --name="Naam" --email=naam@example.nl`
Verwijder die taak daarna weer: het wachtwoord staat in de opdracht zichtbaar in het paneel.

## Problemen

| Melding | Oorzaak en oplossing |
|---|---|
| `install.php` geeft 404 | `storage/install.key` ontbreekt, of de installer is al afgerond (`storage/installed.lock` bestaat), of de document root staat niet op `public/` |
| Systeemrapport: HTTPS **FOUT** | De server meldt PHP niet dat de verbinding HTTPS is, bijvoorbeeld achter een proxy. Zet alleen als je zeker weet dat een eigen proxy `X-Forwarded-Proto` correct zet `TRUST_PROXY_HEADERS=true` in `.env` |
| Systeemrapport: PHP/extensie **FOUT** | Kies PHP 8.2+ en zet de ontbrekende extensie aan in het paneel |
| Systeemrapport: schrijfbaar **FOUT** | Rechten van `storage/`, `storage/logs/`, `storage/ratelimit/` aanpassen (stap 3) |
| Databaseverbinding mislukt | `DB_*` in `.env` controleren; de gebruiker moet aan de database gekoppeld zijn met alle rechten |
| "De database bevat al tabellen" | Gebruik een nieuwe, lege database; de installer overschrijft nooit iets |
| `/health` geeft `503` | Database onbereikbaar of niet geïnstalleerd; zie `storage/logs/app.log` |
| Elke API-aanroep geeft `500` | `.env` ontbreekt of is niet leesbaar, of er zit een fout in. De oorzaak staat in `storage/logs/app.log` (of in het PHP-foutenlog van je host) |

## Als de document root niet kan

Kan je host de document root niet naar `training-api/public` laten wijzen, dan is dit een noodoplossing (**niet getest**):

1. Upload `training-api/` in je webroot, zodat `public_html/training-api/` bestaat.
2. Zet in `public_html/training-api/.htaccess`:
   ```apache
   RewriteEngine On
   RewriteCond %{REQUEST_URI} !^/public/
   RewriteRule ^(.*)$ public/$1 [L]
   Options -Indexes
   ```
3. Controleer **extra streng** stap 7 punt 2: `/.env`, `/storage/...`, `/database/schema.sql`, `/app/Application.php` mogen nooit iets tonen.
4. Werkt dat niet betrouwbaar, kies dan een host waar je de document root kunt instellen. Dit is het veiligste.
