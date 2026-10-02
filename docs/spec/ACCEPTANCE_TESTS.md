# Trainingsapp - Acceptatie- en contracttests v1.0

Doel: dezelfde functionele scenario's moeten slagen tegen de tijdelijke PHP API en later tegen de Laravel API. Waar mogelijk worden deze scenario's als black-box HTTP-tests geautomatiseerd tegen `openapi.yaml`.

## Testdata

Gebruik uitsluitend development/testomgevingen.

- User A: standaard testgebruiker.
- User B: tweede gebruiker voor ownershiptests.
- Programma: dataset 1.0.
- Alle tests starten waar mogelijk vanuit een schone, bekende testdatabase of een transactioneel fixture-scenario.

## A. Installatie en dataset

### AT-001 Schema installeert schoon
**Given** lege MariaDB database  
**When** `schema.sql` wordt uitgevoerd  
**Then** alle tabellen, indexes en constraints bestaan en `app_schema_versions` bevat `1.0.0`.

### AT-002 Seed laadt compleet
**When** `seed.sql` wordt uitgevoerd  
**Then** bestaan exact 1 programma, 5 trainingsblokken, 30 workouttemplates, 30 block_workouts en 143 oefenregels.

### AT-003 Datasetmetadata klopt
`app_meta.dataset_version = 1.0.0` en source SHA-256 komt overeen met `training-program.json`.

### AT-004 Programmastructuur klopt
Default cycli per blok zijn 3,3,3,3,2 en iedere block heeft exact zes block_workouts.

## B. Authenticatie

### AT-010 Geldige login
Geldige credentials retourneren 200, user data, CSRF-token en een Secure/HttpOnly sessiecookie in productie.

### AT-011 Ongeldige login
Ongeldig wachtwoord retourneert 401 `INVALID_CREDENTIALS` zonder details die verraden welk veld onjuist is.

### AT-012 CSRF voor mutatie
Een authenticated muterende request zonder geldig CSRF-token retourneert 419 `CSRF_MISMATCH`.

### AT-013 Logout
Logout invalideert de huidige server-side sessie. Hergebruik van hetzelfde cookie geeft daarna 401.

### AT-014 Expired session
Verlopen sessie retourneert 401 en wordt niet automatisch opnieuw geldig.

### AT-015 Rate limit login
Te veel mislukte loginpogingen binnen de ingestelde limiet retourneert 429.

## C. Programma starten

### AT-020 Start vanaf begin
Nieuwe User A start `beginning`. Verwacht 84 assignments, blok 1 active, andere blocks not_started, eerste recommendation blok1/cyclus1/positie1.

### AT-021 Start week 6 donderdag
Start `position`, `original_week=6`, `day_sequence=4`. Verwacht:
- blok 1 = prior_to_start;
- blok 2 active;
- blok2 cyclus1 en 2 volledig prior_to_start;
- blok2 cyclus3 posities 1-3 prior_to_start;
- blok2 cyclus3 positie4 pending en aanbevolen;
- prior_to_start verschijnt niet als daadwerkelijke workout-historie.

### AT-022 Ongeldige startpositie
Week 0, week 15 of day 7 retourneert 422 `INVALID_START_POSITION`/`VALIDATION_ERROR`.

### AT-023 Geen tweede actieve run
Tweede start van dezelfde programmaversie terwijl een run active/paused is retourneert 409 `ACTIVE_PROGRAM_EXISTS`.

## D. Recommendation in normale volgorde

### AT-030 Kalenderdag negeren
Na alleen maandag completed blijft dinsdag aanbevolen, ook wanneer systeemdatum meerdere dagen later is.

### AT-031 Completed overslaan in recommendation
Na maandag en dinsdag completed wordt woensdag aanbevolen.

### AT-032 Skipped overslaan in recommendation
Maandag completed, dinsdag skipped. Woensdag wordt aanbevolen.

### AT-033 Started heeft prioriteit
Woensdag started maar niet completed. `me/today` retourneert `resume_workout` voor woensdag.

### AT-034 Maximaal één started program assignment
Met woensdag started, start donderdag. Verwacht 409 `ACTIVE_WORKOUT_EXISTS`.

## E. Start en complete

### AT-040 Start pending
Start verandert pending -> started en maakt exact één program session.

### AT-041 Start opnieuw is idempotent
Tweede start op dezelfde started assignment retourneert dezelfde actieve sessie en maakt geen duplicaat.

### AT-042 Complete normaal
Complete verandert assignment naar completed, session naar completed en retourneert volgende action.

### AT-043 Complete direct vanaf pending
Complete zonder expliciete start is toegestaan, maakt één sessie en voltooit correct.

### AT-044 Dubbel complete
Tweemaal complete op dezelfde assignment veroorzaakt geen tweede program session of dubbele historie-entry.

### AT-045 Notitie
Notitie in complete-request wordt bij de session opgeslagen en via historie geretourneerd.

## F. Afwijkende training en continuation decision

### AT-050 Afwijkende workout starten
Normale recommendation = dinsdag. User start donderdag. Sessie registreert `started_as_recommended = 0`.

### AT-051 Afwijkende workout complete
Na complete donderdag retourneert volgende action `continuation_decision`. Dinsdag en woensdag blijven pending.

### AT-052 Keuze program_sequence
Na AT-051 kiest user `program_sequence`. Volgende recommendation is dinsdag.

### AT-053 Keuze last_workout_sequence
Na AT-051 kiest user `last_workout_sequence`. Volgende recommendation is vrijdag.

### AT-054 Anchor schuift mee
In last_workout_sequence wordt vrijdag aanbevolen en completed. Volgende recommendation wordt zaterdag zonder nieuw continuation decision.

### AT-055 Eerdere gaten blijven bestaan
Na donderdag, vrijdag, zaterdag completed in last_workout_sequence blijven dinsdag en woensdag pending.

### AT-056 Automatische fallback
Wanneer na zaterdag geen latere pending assignment in de actieve lijn bestaat maar dinsdag/woensdag nog pending zijn, schakelt recommendation terug naar program_sequence en adviseert dinsdag.

### AT-057 Opnieuw afwijken
In last_workout_sequence is vrijdag aanbevolen, user voltooit een andere pending assignment. Verwacht opnieuw continuation_decision met die workout als source.

## G. Skip en reopen

### AT-060 Skip pending
Pending -> skipped, `skipped_at` gevuld, volgende openstaande recommendation volgt.

### AT-061 Skip started
Started assignment skip annuleert actieve sessie, assignment -> skipped en er blijft geen started session achter.

### AT-062 Reopen skipped
Skipped -> pending, skipped timestamp gewist, recommendation opnieuw berekend.

### AT-063 Reopen completed
Completed -> pending. Bijbehorende reguliere completed session wordt als cancelled/correctie gemarkeerd, verschijnt niet langer als normale completed historie-entry en er ontstaat geen hard delete.

### AT-064 Reopen kan recommendation terugtrekken
Een eerdere completed assignment wordt reopened terwijl program_sequence actief is. Die assignment kan opnieuw de oudste openstaande recommendation worden.

## H. Vrijwillige extra workout

### AT-070 Extra workout starten
Start via workouttemplate creëert `session_type=extra`, zonder assignment.

### AT-071 Extra workout complete
Extra session verschijnt in historie met `extra_completed`.

### AT-072 Extra workout beïnvloedt programma niet
Voor en na extra workout zijn recommendation, assignmentstatussen en block progress gelijk.

### AT-073 Future block workout als extra
Workouttemplate uit toekomstig blok mag als extra session, maar de future assignment blijft locked/niet selecteerbaar als reguliere workout.

## I. Cycli

### AT-080 Cyclus 1 voltooid
Na alle zes terminal statuses in cyclus1 en target_cycles>1 gaat recommendation automatisch naar cyclus2 positie1.

### AT-081 Geen voortgang bij pending
Als één assignment in cyclus pending blijft, begint de volgende cyclus niet automatisch als recommendation zolang program_sequence de openstaande positie nog moet verwerken.

### AT-082 prior_to_start telt als verwerkt
Bij middenstart kan een cyclus met prior_to_start posities correct als verwerkt worden beschouwd.

## J. Block decision en extra cyclus

### AT-090 Laatste standaardcyclus verwerkt
Na alle assignments van target_cycles terminal wordt user_program_block `decision_required` en `me/today` = block_decision.

### AT-091 Extend maakt exact zes
Extend verhoogt target_cycles met 1 en maakt exact zes assignments met nieuw cycle_number en `is_extra_cycle=1`.

### AT-092 Extend eerste recommendation
Na extend wordt positie1 van de extra cyclus aanbevolen.

### AT-093 Dubbel extend
Dubbele of gelijktijdige extend-request mag niet twee cycli toevoegen. Slechts één extra cycle ontstaat.

### AT-094 Extra cyclus opnieuw afgerond
Na afronding extra cyclus ontstaat opnieuw block_decision. Nogmaals extend is toegestaan.

### AT-095 Advance
Advance op decision_required activeert volgende block en adviseert daarvan cyclus1 positie1.

### AT-096 Advance te vroeg
Advance voordat block decision bestaat retourneert 409.

### AT-097 Continuation reset bij block advance
Na block advance staat continuation mode op program_sequence en anchor/source zijn leeg.

## K. Laatste blok en programma einde

### AT-100 Laatste block decision
Na laatste actieve cyclus van blok5 bevat decision minimaal extend en complete_program, niet advance.

### AT-101 Program complete
Complete_program zet laatste block en user_program op completed en `me/today` wordt program_completed.

### AT-102 Program restart
Restart maakt nieuwe user_program met nieuwe assignments. Oude run en historie blijven intact.

### AT-103 Oude historie na restart
Historie kan workouts uit vorige en huidige run correct blijven tonen zonder IDs te verwarren.

## L. Historie en pagination

### AT-110 Historie volgorde
Historie staat nieuwste gebeurtenis eerst.

### AT-111 prior_to_start verborgen
Prior-to-start assignments verschijnen niet in normale historie.

### AT-112 Skipped zichtbaar
Skipped assignment verschijnt als skipped history item.

### AT-113 Pagination
`per_page=25` geeft maximaal 25 items met correcte page/total/last_page metadata.

## M. Multi-user en security

### AT-120 Assignment van andere user lezen
User A vraagt assignment public_id van User B. Verwacht 403 of privacy-veilige 404 volgens implementatiebeleid, consistent over alle endpoints.

### AT-121 Assignment van andere user wijzigen
Start/complete/skip/reopen op User B resource is onmogelijk.

### AT-122 Session van andere user complete
User A kan extra session van User B niet voltooien.

### AT-123 Public ULID is geen autorisatie
Kennis van een geldige ULID geeft zonder ownership nooit toegang.

### AT-124 Database-ID's niet exponeren
API-responses en routes bevatten geen interne BIGINT IDs.

## N. Data-integriteit

### AT-130 Unieke assignment
Er kan niet tweemaal dezelfde combinatie user_program_block + cycle + block_workout worden aangemaakt.

### AT-131 Transactionele complete
Een geforceerde fout midden in complete laat assignment/session niet half aangepast achter.

### AT-132 Transactionele extend
Een fout midden in extend laat niet 1-5 van 6 assignments achter.

### AT-133 UTC
Nieuwe persistente timestamps zijn semantisch UTC; frontend kan ze naar Europe/Amsterdam presenteren.

## O. API-contract

### AT-140 OpenAPI validatie
Iedere geïmplementeerde route/method bestaat in `openapi.yaml` en responsevormen voldoen aan het schema.

### AT-141 Error envelope
Business/validation errors gebruiken `{ "error": { "code": ..., "message": ... } }`.

### AT-142 API versie
Applicatie gebruikt uitsluitend `/api/v1` voor v1-functionaliteit.

### AT-143 CORS credentials
Alleen expliciet toegestane frontend origins krijgen credentialed CORS. Geen wildcard met credentials.

## P. Backup en restore

### AT-150 Dagelijkse backup produceert geldig bestand
Backupbestand is niet leeg, gzip kan worden getest en dump bevat schema/data.

### AT-151 Restore test
Een recente backup kan in een lege tijdelijke database worden gerestored.

### AT-152 Restore inhoud
Na restore zijn program content, users, user_programs en workout_sessions aanwezig met verwachte aantallen.

## Q. Laravel cutover contract

### AT-160 Zelfde testset PHP en Laravel
AT-010 t/m AT-143 moet op een representatieve set identiek slagen tegen PHP en Laravel.

### AT-161 Geen data reset
Laravel start op een kloon van de bestaande productiedatabase zonder schema reset of seed-herimport van gebruikersdata.

### AT-162 API client ongewijzigd
De Lovable/React frontend hoeft voor de eerste cutover geen endpointpaden of response parsing te wijzigen.

### AT-163 Progressie identiek
Voor cutover en na cutover geeft dezelfde database voor User A dezelfde huidige programma-state en recommendation.

### AT-164 Rollback
Na een mislukte smoke test kan de oude PHP API weer worden geactiveerd tegen de pre-cutover databasebackup.

## Definition of done fase 1a

- AT-001 t/m AT-152 relevant voor de PHP API slagen.
- Geen critical/high security findings in handmatige review.
- `openapi.yaml`, implementatie en foutcodes zijn synchroon.
- Productie-installatie kan schema, seed en eerste gebruiker veilig aanmaken.

## Definition of done fase 1b

- Belangrijkste flows op mobiel zijn bruikbaar zonder directe databasekennis in frontend.
- Geen progressie wordt als source of truth in localStorage opgeslagen.
- Login, onboarding, Vandaag, Training, Schema, Historie en decisions werken tegen de echte API.
- Loading/error/offline/unauthorized states zijn zichtbaar en herstelbaar.
