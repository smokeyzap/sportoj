# Implementation notes (fase 1a)

Beslissingen, invarianten en beperkingen. Normatief blijven `docs/spec/BUSINESS_RULES.md` en `openapi.yaml`; waar dit document
daarvan afwijkt of iets invult, staat dat hier of in [`docs/OPENAPI_GAPS.md`](docs/OPENAPI_GAPS.md).

## 1. Opbouw

```text
Request -> Kernel (route, auth, rate limit, CSRF, CORS, foutenvelop) -> Controller (validatie, 1 service-aanroep)
        -> Service (domeinregels, transactie) -> Repository (SQL, prepared statements) -> Presenter (JSON-vorm)
```

- `RecommendationEngine` is **puur** (geen database, geen klok): kalenderdagen kunnen de aanbeveling per constructie niet beinvloeden (BR-025/043).
- `ProgressService::reconcile()` leidt na **elke** mutatie de blokstatus (`active` <-> `decision_required`) en de automatische
  fallback naar `program_sequence` (BR-062) af en slaat die op. `GET /me/today` is een pure read.
- `NextActionService` bouwt de `NextAction` in de prioriteitsvolgorde van BR-032: onboarding / program_completed, open continuation decision,
  block decision, `resume_workout`, volgende workout.
- Publieke id's zijn ULID's; interne BIGINT-id's komen in geen enkele response (getest door een recursieve scan, AT-124).
- Tijd: alles UTC (databasesessie op `+00:00`, `Clock` injecteerbaar); responses zonder fractie (`...Z`).

## 2. Staat en invarianten

| Onderdeel | Regel |
|---|---|
| Blokstatus | precies een blok `active` of `decision_required` zolang het programma loopt; vroegere blokken `completed`/`prior_to_start`, latere `not_started` |
| `decision_required` | alleen als **alle** assignments van het blok terminal zijn (`completed`/`skipped`/`prior_to_start`), ongeacht continuation mode (BR-063/110) |
| `started` | hooguit een per run (BR-033); wordt afgedwongen onder de run-lock |
| Continuation | `continuation_mode`, `..._decision_required`, anchor- en source-id staan op `user_programs`. Bij `decision_required` van het blok, `advance`, `extend` en `complete_program` wordt alles gewist |
| Afwijking | een voltooide workout die bij start niet de aanbevolen was (`started_as_recommended=0`) opent een decision (BR-054/060), tenzij er daarna niets meer open staat |
| Anker | voltooien van een door `last_workout_sequence` aanbevolen workout verschuift het anker zonder vraag (BR-059) |

## 3. Gelijktijdigheid en transacties

- Elke mutatie begint een transactie en neemt **eerst** `SELECT ... FOR UPDATE` op de `user_programs`-rij (assignments, continuation, blokken)
  of de `users`-rij (programma starten, extra sessie starten). Pas daarna wordt de toestand opnieuw gelezen. Zo zijn dubbele verzoeken idempotent en
  sluiten gelijktijdige verzoeken elkaar uit: precies een `extend` slaagt (BR-118), een tweede start van een programma geeft `ACTIVE_PROGRAM_EXISTS` (BR-005), een
  tweede gelijktijdige `start` van een andere training geeft `ACTIVE_WORKOUT_EXISTS` (BR-033). Getest met echt parallelle processen (`pcntl_fork`).
- `Db::transaction()` is niet-nesten-gevoelig en herhaalt maximaal 3x bij deadlock (1213) of lock-wait-timeout (1205).
- Foutinjectie via database-triggers bewijst dat een fout midden in `complete`, `extend` of programmastart niets half achterlaat (AT-131/132).
- Leesendpoints (`today`, `program`, `history`) lopen in een leestransactie voor een consistent snapshot.

## 4. Goedgekeurde interpretaties: waar geimplementeerd

| # | Gedrag | Code |
|---|---|---|
| 1 | herhaald `complete`/`skip`/`reopen` naar dezelfde toestand = `200` zonder wijziging; andere overgangen `409 INVALID_ASSIGNMENT_STATE`; herhaald `extend`/`advance`/`complete_program` = `409 BLOCK_NOT_READY_FOR_DECISION` | `AssignmentService`, `BlockDecisionService`, `ProgramService::complete` |
| 2 | tweede start van een extra workout op dezelfde template geeft de bestaande gestarte sessie | `ExtraWorkoutService::start` |
| 3 | niet-aanbevolen starten bij open decision toegestaan; nieuwe afronding vervangt de bron | `AssignmentService::applyContinuationRules` |
| 4 | reopen alleen in `active`/`decision_required`; in `decision_required` wordt het blok weer `active`; anchor/bron -> `program_sequence` | `AssignmentService::reopen` + `ProgressService::reconcile` |
| 5 | rate limiting in `storage/ratelimit/` (bestanden, `flock`), geen schemawijziging; faalt open als de map niet schrijfbaar is (wordt gelogd) | `Support/RateLimiter` |
| 6 | `403 FORBIDDEN` voor assignments en sessies van een ander, `404 BLOCK_NOT_FOUND` voor blokken | `AssignmentService::owned`, `ExtraWorkoutService::complete`, `BlockDecisionService` |
| 7 | installer alleen met `storage/install.key`, CLI-variant, `storage/installed.lock` | `Install/*`, `public/install.php`, `bin/install.php` |
| 8 | `openapi.yaml` onaangeraakt; gaten gelist | `docs/OPENAPI_GAPS.md`, `tests/known_contract_gaps.php` |
| 9 | geen login-CSRF; `SameSite` configureerbaar | `Kernel` (login is publiek), `SessionService::cookieHeader` |

## 5. Keuzes die de spec openliet

Zie `docs/OPENAPI_GAPS.md` sectie C voor de volledige lijst. De belangrijkste:
`{block_id}` is de id van het programmablok; `complete` op een `pending` assignment terwijl een andere `started` is geeft `ACTIVE_WORKOUT_EXISTS` (anders zou BR-072 BR-035 omzeilen);
`reopen` op `started` is `409`; open decision gaat boven `resume_workout` (BR-032 letterlijk); ongeldige paginatie is `422` (niet stil afgekapt).
Overige keuzes: wachtwoord minimaal 12 tekens (installer; configureerbaar met `PASSWORD_MIN_LENGTH`), login-limiet ook per IP (`RATE_LOGIN_IP_MAX`, standaard 30) tegen password spraying.

## 6. Afwijking van de specificatie: schema

`docs/spec/schema.sql` kan op MariaDB niet worden uitgevoerd (fout 1901: CHECK op een kolom met foreign key `ON UPDATE CASCADE`). `database/schema.sql` is
identiek op een regel na (`ON UPDATE RESTRICT` voor `fk_workout_sessions_assignment`) plus een kopcommentaar. `InstallTest` bewaakt dat dit de **enige** afwijking is en
dat `seed.sql` byte-identiek aan de spec is. De spec zou bijgewerkt moeten worden voordat Laravel dezelfde baseline gebruikt (BR-191).

## 7. Beveiliging

Gedaan: sessietokens 32 bytes uit `random_bytes`, alleen SHA-256 opgeslagen; `hash_equals` voor CSRF; Argon2id + rehash; gelijke respons en dummy-hash bij onbekend account
(geen enumeratie); rate limits; ownership op elke persoonlijke resource; uitsluitend prepared statements; geen interne details in 5xx; geen geheimen in logs
(e-mail wordt als hash-prefix gelogd, wachtwoorden/tokens nooit); CORS alleen expliciete origins, nooit `*`; `Cache-Control: no-store`; installer sluit zichzelf, is
sleutel-beveiligd, rate limited, escapet uitvoer en heeft een strikte CSP; wachtwoordlengte begrensd (1024 bytes) zodat een grote body geen onnodige hash-kosten veroorzaakt.

Niet gedaan / aandachtspunten: er is **geen onafhankelijke** handmatige security-review geweest (de Definition of Done vraagt die); sessies hebben een vaste (niet-schuivende) looptijd;
`FORBIDDEN` bij andermans assignment bevestigt dat een geldige ULID bestaat (bewuste keuze, interpretatie 6); er is geen account-lockout, alleen rate limiting; HSTS en TLS
horen bij de webserver; `TRUST_PROXY_HEADERS` mag alleen achter een eigen proxy.

## 8. Teststrategie en wat niet getest is

Test-suite: 260 tests. Elk antwoord in de suite wordt automatisch gevalideerd tegen `openapi.yaml` (JSON Schema 2020-12 via `opis/json-schema`); alleen responses in
`tests/known_contract_gaps.php` mogen buiten het contract vallen. `tests/acceptance_report.php` zet het JUnit-log om naar een tabel per AT.

**Niet of beperkt getest:**
- CI is gedraaid op GitHub (run op commit f7da969): lint, shellcheck en de volledige suite slaagden op PHP 8.2 + MariaDB 10.6 en PHP 8.3 + MariaDB 11.4; het per-AT-rapport slaagde ook. Niet nagekeken is welke tests daar eventueel zijn overgeslagen (`--display-skipped` staat in het log). Lokaal draaide PHP 8.3.6 met MariaDB 10.11.14.
- Echte Apache/nginx/php-fpm-deployments en TLS (alleen PHP's ingebouwde server over HTTP; `Secure`-cookie is als header gecontroleerd).
- Bcrypt-fallback op een host zonder Argon2 (alleen het rehash-pad van een bcrypt-hash naar Argon2id is getest).
- Externe backup-bestemming, cron en e-mail/alarmhooks (alleen met lokale testcommando's).
- Prestaties en echte load; de file-gebaseerde rate limiter is alleen met korte tests en `flock` onderzocht.
- AT-160..164 (Laravel-cutover) zijn buiten fase 1a.
- De specificatiebestanden `FUNCTIONAL_TECHNICAL_DESIGN.md`, `DATABASE_MODEL.md`, `PROMPT_PHP_API.md`, `scripts/*`, `.env.example` en `source/Sporten.html` uit de README van de spec stonden niet in `docs/spec/`; ze zijn niet gelezen.
