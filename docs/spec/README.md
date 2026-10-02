# Trainingsapp build package v1.0

Dit pakket bevat de uitgewerkte bouwspecificatie voor de trainingsapp.

## Doelvolgorde

### Fase 0 - specificatie

Dit pakket is de source of truth.

### Fase 1a - backend

Gebruik `PROMPT_PHP_API.md` met de overige bestanden als context voor Claude Code, ChatGPT Work of een andere coding agent.

Resultaat: tijdelijke PHP REST API op eigen hosting, gekoppeld aan de definitieve MariaDB database.

### Fase 1b - frontend

Wanneer de API draait, gebruik `PROMPT_LOVABLE.md` plus `openapi.yaml` en de functionele documenten in Lovable.

Resultaat: mobile-first React/Vite frontend die uitsluitend via de API communiceert.

### Fase 2 - Laravel

Laat een coding agent later een Laravel drop-in replacement bouwen boven dezelfde database en hetzelfde `/api/v1` contract. Gebruik opnieuw BUSINESS_RULES, DATABASE_MODEL, OpenAPI en ACCEPTANCE_TESTS als bindende basis.

### Fase 3 - self-host frontend

Exporteer/build de React/Vite frontend en host deze zelf. De API base URL blijft environmentconfiguratie.

## Bestanden

| Bestand | Doel |
|---|---|
| `FUNCTIONAL_TECHNICAL_DESIGN.md` | gezamenlijke functionele/technische architectuur |
| `BUSINESS_RULES.md` | exacte business rules met BR-nummers |
| `DATABASE_MODEL.md` | menselijk leesbaar datamodel |
| `schema.sql` | uitvoerbare MariaDB baseline 1.0.0 |
| `seed.sql` | vaste trainingscontent dataset 1.0.0 |
| `training-program.json` | machineleesbare source of truth van trainingscontent |
| `openapi.yaml` | bindend API v1 contract |
| `ACCEPTANCE_TESTS.md` | black-box en functionele testscenario's |
| `INSTALLATION_REQUIREMENTS.md` | hosting, security, installer en deploymentvereisten |
| `BACKUP_RESTORE.md` | backup-, restore- en cutoverbeleid |
| `PROMPT_PHP_API.md` | bouwprompt fase 1a |
| `PROMPT_LOVABLE.md` | bouwprompt fase 1b |
| `LARAVEL_CUTOVER.md` | cutover- en rollbackplan |
| `PROMPT_LARAVEL.md` | bouwprompt voor fase 2 Laravel |
| `CHECKLIST_STATUS.md` | status ten opzichte van oorspronkelijke voorbereidingschecklist |
| `API_EXAMPLES.md` | leesbare voorbeeldflows bovenop OpenAPI |
| `CONTENT_VALIDATION_REPORT.md` | bronfidelity en bewuste structurering |
| `.env.example` | deploymentconfiguratie zonder secrets |
| `source/Sporten.html` | oorspronkelijke bron |
| `scripts/build_training_data.py` | reproduceert training-program.json uit bron + mappingregels |
| `scripts/build_seed_sql.py` | reproduceert seed.sql uit training-program.json |
| `scripts/build_openapi.py` | reproduceert openapi.yaml |
| `scripts/backup_database.sh` | dagelijkse/wekelijkse/maandelijkse backupretentie |
| `scripts/restore_verify.sh` | veilige restoretest naar lege testdatabase |

## Belangrijkste vastgelegde besluiten

- PHP/MariaDB API eerst, Lovable frontend daarna.
- MariaDB staat vanaf fase 1a op eigen hosting en blijft bestaan bij Laravel-cutover.
- Laravel wordt later een drop-in replacement van `/api/v1`.
- 5 trainingsblokken, 14 standaardcycli, 84 standaard assignments per run.
- Extra cyclus voegt exact zes assignments toe en kan onbeperkt worden herhaald.
- Kalenderdagen bepalen nooit de recommendation.
- Een bewust afwijkende workout leidt tot keuze tussen programmavolgorde en verder vanaf die workout.
- Wachtwoorden: Argon2id, bcrypt alleen fallback.
- Webauth: opaque HttpOnly session cookie + CSRF.
- Publieke API IDs: ULID.
- Backup dagelijks, externe kopie, periodieke restore-test.

## Externe deploymentwaarden die nog moeten worden ingevuld

Dit zijn geen productontwerpvragen meer:

- daadwerkelijke frontend domeinnaam;
- daadwerkelijke API domeinnaam;
- hostingprovider/serverpaths;
- databasecredentials;
- externe backuplocatie;
- toegestane CORS origins;
- eventueel operationeel e-mailadres voor foutmeldingen.

## Wijzigingsdiscipline

Verander niet alleen één bestand wanneer een besluit meerdere contracten raakt.

Voorbeelden:

- nieuwe status -> BUSINESS_RULES + DATABASE_MODEL + schema + OpenAPI + tests;
- nieuw endpoint -> OpenAPI + tests + beide relevante prompts;
- wijziging trainingscontent -> training-program.json/data version + seed;
- breaking API-wijziging -> nieuwe API-versie of expliciete backward compatibility.
