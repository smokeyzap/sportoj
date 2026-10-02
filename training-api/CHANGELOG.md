# Changelog

Alle noemenswaardige wijzigingen aan `training-api`. Formaat: [Keep a Changelog](https://keepachangelog.com/), versies volgen
de API (`/api/v1`) en de databasebaseline (schema 1.0.0, dataset 1.0.0).

## [Unreleased]

## [0.1.0] - fase 1a, eerste volledige oplevering

### Toegevoegd
- REST API `/api/v1` volgens `docs/spec/openapi.yaml` (alle 23 operaties) met PHP 8.2+, PDO en MariaDB 10.6+; runtime zonder
  externe packages (composer alleen voor dev/test).
- Authenticatie met database-gebackte opaque sessies (alleen SHA-256 van token en CSRF-token opgeslagen), Argon2id met
  bcrypt-fallback, rehash bij login, CSRF-header voor muterende requests, `SameSite` configureerbaar, credentialed CORS
  uitsluitend voor `FRONTEND_ORIGINS`.
- Rate limiting op bestanden onder `storage/ratelimit/` (login per IP+e-mail en per IP; 120/min per sessie; 60/min muterend).
- Programma starten vanaf begin of positie (84 assignments, `prior_to_start`), recommendation engine,
  workout-lifecycle (start/complete/skip/reopen, idempotent), continuation decision, cycli, block decisions
  (`extend`, `advance`, `complete_program`), restart, extra workouts, gepagineerde historie.
- Per-gebruiker serialisatie via rij-locks (`users`, `user_programs`) en transacties met deadlock-retry.
- Installer: `public/install.php` (alleen met `storage/install.key`), CLI `bin/install.php`, `bin/create-user.php`,
  `bin/cleanup.php`, `storage/installed.lock`; weigert niet-lege of onbekende databases en overschrijft niets.
- `scripts/backup_database.sh` (14 dagelijks / 8 wekelijks / 6 maandelijks, checksum, `gzip -t`, optioneel extern kopieerhook en
  alarmhook) en `scripts/restore_verify.sh` (alleen in lege testdatabase, nooit over productie).
- Testsuite (PHPUnit): 260 tests; elke response wordt gevalideerd tegen `openapi.yaml`; echte parallelle (fork) tests voor
  locking; fault-injection met database-triggers voor transactie-rollback; backup/restore-scripts worden echt uitgevoerd.
- GitHub Actions: lint + tests tegen een MariaDB-service (PHP 8.2/MariaDB 10.6 en PHP 8.3/MariaDB 11.4) met rapport per acceptatietest.
- Documentatie: `README.md`, `IMPLEMENTATION_NOTES.md`, `docs/OPENAPI_GAPS.md`, `docs/ACCEPTANCE_RESULTS.md`.

### Gewijzigd t.o.v. de specificatie
- `database/schema.sql` wijkt op één punt af van `docs/spec/schema.sql`: `fk_workout_sessions_assignment` gebruikt
  `ON UPDATE RESTRICT` in plaats van `CASCADE`, omdat MariaDB het origineel weigert (fout 1901). Zie `docs/OPENAPI_GAPS.md` sectie D.
- `docs/spec/openapi.yaml` is **niet** gewijzigd. Afwijkende/ontbrekende foutcodes staan in `docs/OPENAPI_GAPS.md` ter beoordeling.
