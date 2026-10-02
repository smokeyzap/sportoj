# openapi.yaml: gaten en keuzes die jouw beslissing vragen

Status: **ter beoordeling**. `docs/spec/openapi.yaml` is in deze build **niet gewijzigd** (goedgekeurde interpretatie 8).
De implementatie houdt zich aan het contract; waar het contract zwijgt of een andere foutcode toont, is hieronder vastgelegd
wat er nu gebeurt. De testsuite faalt zodra een response buiten deze lijst ontstaat (`tests/known_contract_gaps.php`), dus
de lijst is volledig voor alles wat de tests aanraken.

Als je een gat wilt dichten, wijzig dan `openapi.yaml`, `BUSINESS_RULES.md`/tests en de implementatie samen
(README van de specificatie: "Wijzigingsdiscipline") en haal de regel uit `tests/known_contract_gaps.php`.

## A. Dwarsdoorsnijdend (geldt voor (bijna) elk endpoint)

| # | Situatie | Nu | Voorstel voor de spec |
|---|---|---|---|
| A1 | Rate limit op ingelogde endpoints (120 req/min algemeen, 60/min muterend, per sessie; INSTALLATION_REQUIREMENTS §11) | `429 RATE_LIMITED`, header `Retry-After` | `429` toevoegen aan alle beveiligde operaties (nu alleen bij login) |
| A2 | Onverwachte fout | `500 INTERNAL_ERROR`, vaste melding, geen interne details (tenzij `APP_DEBUG=true`) | `500` als standaardresponse |
| A3 | Onbekend pad | `404 NOT_FOUND` | standaardresponse |
| A4 | Bekend pad, andere methode | `405 METHOD_NOT_ALLOWED` + `Allow` | standaardresponse |
| A5 | CORS preflight (`OPTIONS`) | `204`, lege body, CORS-headers alleen voor `FRONTEND_ORIGINS` | in de beschrijving van het contract noemen (`OPTIONS` hoort normaal niet per operatie) |
| A6 | `/health` bij onbereikbare database of ontbrekend schema/dataset | `503 {"status":"error"}` (INSTALLATION_REQUIREMENTS §15 noemt dit als optie) | `503` toevoegen |
| A7 | Body is geen geldig JSON-object | `422 VALIDATION_ERROR` met `details.fields.body` | valt onder B1-B5; geen aparte `400` ingevoerd om het aantal codes klein te houden |

## B. Per endpoint

| # | Endpoint | Situatie | Nu | Voorstel |
|---|---|---|---|---|
| B1 | `POST /auth/login` | ontbrekende/ongeldige velden, wachtwoord > 1024 bytes | `422 VALIDATION_ERROR` | `422` toevoegen |
| B2 | `POST /me/program/start` | `start_mode` ontbreekt/onbekend, `original_week`/`day_sequence` ontbreken of zijn geen integer | `422 VALIDATION_ERROR` (alleen een getal buiten bereik geeft `422 INVALID_START_POSITION`, zoals het contract toont) | bij de `422` beide codes tonen. AT-022 noemt zelf "`INVALID_START_POSITION`/`VALIDATION_ERROR`" |
| B3 | `POST /me/program/restart` | idem als B2; het contract documenteert hier alleen `201/409/401/419` | `422 VALIDATION_ERROR` of `422 INVALID_START_POSITION` | `422` toevoegen |
| B4 | `GET /me/history` | `page`/`per_page` buiten `minimum`/`maximum` of geen integer | `422 VALIDATION_ERROR` (niet stilzwijgend afgekapt) | `422` toevoegen, of kies bewust voor afkappen |
| B5 | `POST /workout-assignments/{id}/complete` en `POST /workout-sessions/{id}/complete` | `notes` is geen string of langer dan 5000 tekens | `422 VALIDATION_ERROR` | `422` toevoegen |
| B6 | `POST /workout-assignments/{id}/start` | assignment is niet `pending` (bv. `completed`, `skipped`, `prior_to_start`) of zit in een nog niet actief blok (BR-051) | `409 INVALID_ASSIGNMENT_STATE` (bij `complete`/`skip`/`reopen` is die code wel gedocumenteerd, bij `start` alleen `ACTIVE_WORKOUT_EXISTS`) | `INVALID_ASSIGNMENT_STATE` bij `start` documenteren |
| B7 | `POST /workout-assignments/{id}/complete` | een andere assignment is al `started` en deze is `pending` (impliciete start, BR-072, mag BR-035 niet omzeilen) | `409 ACTIVE_WORKOUT_EXISTS` (gedocumenteerd is hier alleen `INVALID_ASSIGNMENT_STATE`) | `ACTIVE_WORKOUT_EXISTS` bij `complete` documenteren |
| B8 | `POST /me/program/complete` | programma is al afgerond (herhaald verzoek) | `409 BLOCK_NOT_READY_FOR_DECISION`, **zoals goedgekeurde interpretatie 1 voorschrijft**. Gedocumenteerd is `PROGRAM_NOT_READY_TO_COMPLETE`, die code geven we wel als het laatste blok nog niet op `decision_required` staat of er geen programma is | beslissen welke code bij een herhaald verzoek hoort en die in het contract zetten |

## C. Semantiek die het contract of de business rules open laten

Geen foutcode-gat, wel gedrag dat een Laravel-drop-in (BR-194) identiek moet overnemen.

| # | Onderwerp | Gekozen gedrag |
|---|---|---|
| C1 | `{block_id}` / `BlockSummary.id` | de publieke id van het **programmablok** (`training_blocks.public_id`, zoals in API_EXAMPLES), niet van `user_program_blocks`. Elke gebruiker deelt dus dezelfde blok-id's; eigenaarschap volgt uit het eigen programma van de aanroeper |
| C2 | `POST /workouts/{id}/sessions` bij al gestarte extra sessie (interpretatie 2) | bestaande sessie, status `201` (het contract kent alleen `201`) hoewel er niets nieuws is aangemaakt |
| C3 | Prioriteit `me/today` bij open continuation decision én gestarte workout (interpretatie 3) | de decision gaat voor (BR-032 letterlijk); na het beantwoorden verschijnt `resume_workout` |
| C4 | `extend` | zet continuation terug naar `program_sequence` en wist anchor/source. BR-123 noemt dit alleen voor `advance`; bij `extend` is het niet te onderscheiden (de nieuwe cyclus begint op positie 1) maar de staat wordt schoon gehouden |
| C5 | `restart` zonder eerdere afgeronde run | werkt als `start` (het contract zegt "na een afgeronde run" maar definieert geen fout) |
| C6 | `reopen` op een `started` assignment | `409 INVALID_ASSIGNMENT_STATE` (BR-085 noemt alleen `completed`/`skipped`; BR-083 spreekt van "heropend/geannuleerd" voor started, maar legt de route vast op `skip`) |
| C7 | `paused` programma | er is geen endpoint dat pauzeert; mocht de status in de database staan, dan behandelt de engine hem als `active` |
| C8 | Herhaalde `complete` met andere `notes` | genegeerd; het eerste resultaat blijft staan |
| C9 | `GET /workouts/{id}` | alleen actieve templates (`is_active=1`); voor elke ingelogde gebruiker leesbaar (globale content) |
| C10 | Eigenaarschap (interpretatie 6) | `403 FORBIDDEN` voor assignments en sessies van een ander (bestaan van een geldige ULID lekt dus), `404 BLOCK_NOT_FOUND` voor blokken. Een geldige maar onbekende of misvormde id geeft altijd `404` |
| C11 | Tijdstempels in responses | ISO-8601 UTC zonder fractie (`2026-08-17T19:30:00Z`, zoals de voorbeelden); de database bewaart microseconden |

## D. Defect in een ander specificatiebestand (geen openapi)

`docs/spec/schema.sql` (baseline 1.0.0) laadt **niet** op MariaDB 10.6/10.11: fout 1901 op
`chk_workout_sessions_assignment_type`, omdat een CHECK geen kolom mag bevatten die in een foreign key met
`ON UPDATE CASCADE` zit (`workout_sessions.workout_assignment_id`).

Minimale oplossing in `training-api/database/schema.sql`: alleen `fk_workout_sessions_assignment` krijgt `ON UPDATE RESTRICT`.
Interne BIGINT-id's worden nooit gewijzigd, dus het gedrag is identiek. Een test bewaakt dat het gebundelde bestand exact
daarin afwijkt, en een andere test bewijst dat het origineel faalt (en meldt zich zodra een MariaDB-versie het wel accepteert).
**Dit raakt BR-191 ("schema.sql 1.0.0 is de officiële baseline") en de Laravel-cutover**: de spec zou bijgewerkt moeten worden.
