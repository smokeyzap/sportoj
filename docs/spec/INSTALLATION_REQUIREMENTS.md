# Trainingsapp - Installatie- en hostingvereisten v1.0

## 1. Doelomgeving fase 1a

De tijdelijke PHP API en MariaDB draaien vanaf de eerste echte gebruiksdag op eigen hosting. Lovable gebruikt deze API op afstand.

Aanbevolen logische domeinen:

- frontend: `train.example.nl`
- API: `api-train.example.nl`

Vervang deze placeholders tijdens deployment. Houd de namen daarna stabiel, ook wanneer hostingtechniek verandert.

## 2. Minimale serververeisten

### PHP

- PHP 8.2 of hoger.
- 64-bit build aanbevolen.
- `display_errors=Off` in productie.
- `log_errors=On`.

Vereiste extensies:

- `pdo`;
- `pdo_mysql`;
- `json`;
- `mbstring`;
- `openssl`;
- `filter`;
- `session` is niet vereist voor PHP-native sessies, maar mag aanwezig zijn. De API gebruikt eigen database-backed opaque sessions;
- Argon2 support sterk aanbevolen voor `PASSWORD_ARGON2ID`.

Controleer in installatie met `defined('PASSWORD_ARGON2ID')` en een testhash.

### MariaDB

- MariaDB 10.6 of hoger.
- InnoDB.
- `utf8mb4`.
- Gebruiker met rechten op de applicatiedatabase voor SELECT, INSERT, UPDATE, DELETE en tijdens installatie CREATE/ALTER/INDEX/REFERENCES.

### Composer

- Composer 2 aanbevolen.
- Composer hoeft niet per se op productiehosting beschikbaar te zijn als `vendor/` tijdens deployment wordt meegeleverd.
- Houd dependencies minimaal. Een lichte router/PSR-stack is toegestaan, maar businesslogica blijft eigen, testbare services.

### Webserver

Apache of Nginx met:

- HTTPS;
- front-controller routing naar `public/index.php`;
- request headers beschikbaar;
- CORS response headers configureerbaar;
- toegang tot environment variables of een `.env` buiten de publieke webroot.

## 3. HTTPS

Productie mag niet zonder HTTPS gebruikt worden.

Vereisten:

- geldig TLS-certificaat;
- HTTP -> HTTPS redirect;
- session cookie `Secure`;
- HSTS mag na stabiele productie worden ingeschakeld.

## 4. Directorystructuur tijdelijke PHP API

Aanbevolen:

```text
training-api/
  app/
    Controllers/
    Services/
    Repositories/
    Auth/
    Validation/
    Http/
  bootstrap/
  config/
  database/
    schema.sql
    seed.sql
  public/
    index.php
    install.php
  storage/
    logs/
    installed.lock
  tests/
  vendor/
  .env
  composer.json
```

Alleen `public/` hoort rechtstreeks via de webserver bereikbaar te zijn.

## 5. Environment configuratie

Minimaal:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api-train.example.nl
FRONTEND_ORIGINS=https://train.example.nl

DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=trainingsapp
DB_USERNAME=...
DB_PASSWORD=...

SESSION_COOKIE_NAME=training_session
SESSION_TTL_DAYS=30
SESSION_COOKIE_DOMAIN=api-train.example.nl
SESSION_COOKIE_SAMESITE=Lax
SESSION_COOKIE_SECURE=true

LOG_LEVEL=warning
LOG_PATH=/absolute/path/outside/public/storage/logs/app.log
```

Secrets worden nooit in Git opgenomen.

## 6. Lovable-hosting en cookies

De veiligste webauthenticatie gebruikt een HttpOnly cookie. Browsercookies zijn het betrouwbaarst wanneer frontend en API onder hetzelfde registrable domain draaien, bijvoorbeeld `train.example.nl` en `api-train.example.nl`.

Daarom geldt voor echte productieprogressie:

**Gebruik bij voorkeur al in fase 1b een eigen frontenddomein dat naar de Lovable-hosted frontend verwijst.**

Als de Lovable frontend tijdelijk vanaf een volledig ander top-level domein draait, zijn cross-site cookiebeperkingen van browsers een risico. `SameSite=None; Secure` kan technisch nodig zijn, maar third-party cookiebeleid kan alsnog beperkend zijn. Gebruik zo'n preview daarom primair voor ontwikkeling, niet als gewenste permanente loginarchitectuur.

## 7. CORS

Productie:

- alleen origins uit `FRONTEND_ORIGINS`;
- `Access-Control-Allow-Credentials: true`;
- nooit `Access-Control-Allow-Origin: *` in combinatie met credentials;
- methods beperken tot werkelijk ondersteunde methods;
- headers minimaal `Content-Type`, `X-CSRF-Token`;
- `Vary: Origin` wanneer dynamische origin-whitelist wordt gebruikt.

## 8. Sessies

Bij login:

1. Genereer minimaal 32 cryptografisch willekeurige bytes via `random_bytes()`.
2. Encodeer als URL-safe opaque token.
3. Stuur raw token alleen in Secure/HttpOnly cookie.
4. Sla uitsluitend `hash('sha256', $token)` op in `auth_sessions.token_hash`.
5. Genereer een apart CSRF-token en bewaar alleen de SHA-256 hash daarvan.
6. Standaard expiry: 30 dagen.
7. Logout verwijdert server-side sessierecord en expireert cookie.

Geen sessietokens in URL, logs of localStorage.

## 9. CSRF-flow

### Login

`POST /api/v1/auth/login` maakt sessie en retourneert naast user een CSRF-token in JSON.

### Pagina refresh met bestaande login

Frontend roept `GET /api/v1/auth/csrf` aan. De backend roteert het CSRF-token en retourneert het nieuwe raw token.

### Mutaties

Frontend stuurt token in `X-CSRF-Token`.

Backend vergelijkt SHA-256 met `auth_sessions.csrf_token_hash` via timing-safe vergelijking.

## 10. Wachtwoorden

Voorkeur:

```php
password_hash($password, PASSWORD_ARGON2ID)
```

Fallback als Argon2id aantoonbaar niet beschikbaar is:

```php
password_hash($password, PASSWORD_BCRYPT)
```

Login:

```php
password_verify($password, $storedHash)
```

Na succesvolle login controleren met `password_needs_rehash()`.

Geen eigen cryptografische algoritmen ontwerpen.

## 11. Rate limits

Startwaarden, configureerbaar:

- login: maximaal 5 mislukte pogingen per minuut per combinatie van IP-signaal en genormaliseerd e-mailadres;
- algemene authenticated API: 120 requests/minuut per sessie;
- muterende workoutacties: 60 requests/minuut per sessie.

Een succesvolle login mag de failure counter voor die gebruiker/IP-combinatie resetten.

## 12. Installatiewizard

`public/install.php` mag alleen functioneren wanneer `storage/installed.lock` ontbreekt.

### Stap 1 - system check

Controleer:

- PHP-versie;
- vereiste extensies;
- Argon2id beschikbaarheid;
- writable storage;
- HTTPS in productie;
- databaseconnectie;
- database charset/version informatie.

### Stap 2 - database baseline

- Als `app_schema_versions` niet bestaat: voer `schema.sql` uit.
- Als schema 1.0.0 al bestaat: nooit tabellen droppen. Controleer of database consistent is.

### Stap 3 - dataset

- Als `app_meta.dataset_version` ontbreekt en contenttabellen leeg zijn: voer `seed.sql` uit.
- Als dataset al 1.0.0 is: niet opnieuw seeden.
- Bij conflicterende bestaande content: stop met duidelijke foutmelding, niet automatisch overschrijven.

### Stap 4 - eerste gebruiker

Vraag:

- naam;
- e-mailadres;
- wachtwoord + bevestiging;
- timezone, default in UI `Europe/Amsterdam`.

Maak public ULID en veilige wachtwoordhash.

### Stap 5 - verificatie

Controleer minimaal:

- schema version 1.0.0;
- dataset version 1.0.0;
- 5 blocks;
- 30 workouttemplates;
- 143 exercise regels;
- minimaal één user;
- health endpoint.

### Stap 6 - installer sluiten

- Schrijf `storage/installed.lock` met timestamp en schema/datasetversion.
- Vanaf dat moment retourneert `/install.php` 404 of een neutrale `already installed` pagina zonder secrets.
- Installer mag nooit een resetfunctie bieden.

## 13. Cronjobs

Aanbevolen minimaal:

- dagelijks databasebackup;
- dagelijks opschonen verlopen `auth_sessions`;
- periodiek backupretentie toepassen.

Voorbeeldtijden kunnen op hosting worden gekozen. De applicatielogica mag niet afhangen van exact tijdstip.

## 14. Logging

Log minimaal:

- onverwachte exceptions;
- databaseconnectiefouten;
- invalid schema/dataset situaties;
- login failures zonder wachtwoord;
- block extend/advance failures;
- backup failures.

Nooit loggen:

- wachtwoorden;
- raw session tokens;
- raw CSRF-tokens;
- Authorization secrets;
- databasepassword.

## 15. API health check

`GET /health` is publiek en retourneert geen gevoelige informatie.

Minimaal:

```json
{
  "status": "ok",
  "schema_version": "1.0.0",
  "dataset_version": "1.0.0"
}
```

Optioneel kan een niet-ok status/503 worden gegeven als database onbereikbaar is.

## 16. Lovable configuratie

Frontend gebruikt environment variable:

```dotenv
VITE_API_BASE_URL=https://api-train.example.nl/api/v1
```

Geen endpoint-URL's verspreid hardcoden. Gebruik één API-clientlaag.

Alle fetches met sessiecookie gebruiken `credentials: 'include'`.

## 17. Voor start bouw nog extern in te vullen

Deze waarden zijn bewust niet door het ontwerp verzonnen:

- daadwerkelijke frontend domeinnaam;
- daadwerkelijke API domeinnaam;
- hostingprovider;
- databasehost/credentials;
- absolute storage/log/backup paths;
- externe backuplocatie;
- mailadres voor eventuele technische foutmeldingen.

Ze veranderen het database- of API-contract niet.
