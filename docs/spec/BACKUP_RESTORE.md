# Trainingsapp - Backup- en restorebeleid v1.0

## 1. Doel

Trainingsprogressie staat vanaf fase 1a in de eigen MariaDB-database. Backups worden daarom vanaf het eerste echte gebruik automatisch georganiseerd. Een backup telt pas als bruikbaar wanneer een restore aantoonbaar werkt.

## 2. Strategie

Gebruik voor deze kleine InnoDB-database een dagelijkse logische dump:

```bash
mariadb-dump \
  --single-transaction \
  --quick \
  --triggers \
  --default-character-set=utf8mb4 \
  --host="$DB_HOST" \
  --port="$DB_PORT" \
  --user="$DB_USERNAME" \
  --password="$DB_PASSWORD" \
  "$DB_DATABASE"
```

De dump wordt direct gecomprimeerd met gzip.

Databasecredentials horen bij voorkeur in een afgeschermd clientconfigbestand of secrets/environment van de cronjob. Vermijd een wachtwoord zichtbaar in process lists wanneer de hosting een veiliger mechanisme biedt.

## 3. Opslaglocaties

### Lokale kopie

Buiten `public_html`, bijvoorbeeld:

```text
/home/account/secure-backups/trainingsapp/
```

### Externe kopie

Minimaal één andere storage of server. Voorbeelden van geschikte mechanismen:

- SFTP naar een andere server;
- object storage via een daarvoor geschikte CLI;
- rclone remote;
- backupmogelijkheid van hostingprovider, mits werkelijk onafhankelijk herstel mogelijk is.

De exacte provider wordt deploymentconfiguratie.

## 4. Bestandsnaam

Gebruik UTC timestamp:

```text
trainingsapp_20260817T210000Z_schema-1.0.0.sql.gz
```

## 5. Retentie

Richtlijn:

- 14 dagelijkse herstelpunten;
- 8 wekelijkse herstelpunten;
- 6 maandelijkse herstelpunten.

Een eenvoudige implementatie mag dagelijks backups maken en een rotatiescript laten bepalen welke bestanden als week/maandpunt behouden blijven.

## 6. Integriteitscontrole na backup

Na iedere dump:

1. exitcode van `mariadb-dump` controleren;
2. gzip exitcode controleren;
3. bestand moet bestaan en groter zijn dan een configureerbare minimumgrootte;
4. `gzip -t <bestand>` moet slagen;
5. SHA-256 checksum opslaan naast het bestand;
6. pas daarna oude backups verwijderen volgens retentie;
7. fouten naar serverlog schrijven en indien mogelijk operationele melding sturen.

## 7. Voorbeeld backupscript

Meegeleverd als `scripts/backup_database.sh`.

Het script:

- leest configuratie uit environment;
- maakt directory buiten webroot;
- maakt consistente dump;
- gzip-comprimeert;
- test gzip;
- schrijft SHA-256;
- bewaart exact de nieuwste 14 dagelijkse snapshots;
- maakt op zondag een weekly snapshot en bewaart de nieuwste 8;
- maakt op de eerste dag van de maand een monthly snapshot en bewaart de nieuwste 6;
- kan optioneel iedere tier extern kopiëren via `BACKUP_REMOTE_COMMAND`.

De aantallen zijn configureerbaar via `BACKUP_KEEP_DAILY`, `BACKUP_KEEP_WEEKLY` en `BACKUP_KEEP_MONTHLY`.

## 8. Restore naar tijdelijke database

Meegeleverd script: `scripts/restore_verify.sh`. Het script weigert te herstellen in een niet-lege testdatabase, controleert gzip/checksum, importeert de dump en valideert schema/dataset/contentaantallen.


Nooit een restoretest rechtstreeks over productie uitvoeren.

Maak een lege tijdelijke database, bijvoorbeeld:

```text
trainingsapp_restore_test
```

Restore:

```bash
gunzip -c backup.sql.gz | mariadb \
  --host="$DB_HOST" \
  --port="$DB_PORT" \
  --user="$RESTORE_DB_USERNAME" \
  --password="$RESTORE_DB_PASSWORD" \
  trainingsapp_restore_test
```

Controleer daarna minimaal:

```sql
SELECT version FROM app_schema_versions;
SELECT meta_value FROM app_meta WHERE meta_key='dataset_version';
SELECT COUNT(*) FROM programs;
SELECT COUNT(*) FROM training_blocks;
SELECT COUNT(*) FROM workout_templates;
SELECT COUNT(*) FROM users;
SELECT COUNT(*) FROM user_programs;
SELECT COUNT(*) FROM workout_sessions;
```

Voor dataset 1.0 verwacht contentcontrole minimaal 1 programma, 5 blocks, 30 workouttemplates.

## 9. Restore-testfrequentie

Aanbevolen maandelijks en verplicht:

- vóór belangrijke backendmigratie;
- vóór Laravel-cutover;
- na wijziging backupmechanisme;
- na verhuizing hosting/database.

Registreer datum, backupbestand en resultaat in een eenvoudig operationeel log.

## 10. Productierestore bij incident

1. Zet muterende API-acties tijdelijk uit of zet applicatie in maintenance mode.
2. Bepaal welk herstelpunt nodig is.
3. Maak indien mogelijk eerst nog een dump van de beschadigde actuele database voor forensische/vergelijkingsdoeleinden.
4. Maak nieuwe lege herstel-database of herstel naar gecontroleerde kloon.
5. Importeer backup.
6. Draai inhoudscontroles.
7. Test login, `me/today`, program state en history.
8. Schakel applicatie om naar herstelde database.
9. Bewaar incidentinformatie en gekozen herstelpunt.

## 11. Laravel-cutover

Direct vóór productiecutover:

1. Zet writes tijdelijk uit.
2. Wacht op lopende muterende requests.
3. Maak `pre-laravel-cutover` backup.
4. Test gzip/checksum.
5. Maak bij voorkeur databasekloon en draai Laravel smoke/contracttests daarop.
6. Zet Laravel live achter dezelfde API URL.
7. Test read-only endpoints.
8. Test één gecontroleerde mutatie.
9. Geef writes volledig vrij.

Bij mislukking:

- zet oude PHP API terug;
- herstel zo nodig de pre-cutover backup;
- laat frontend/API URL ongewijzigd.

## 12. Wat niet voldoende is

Niet als volwaardig backupbeleid beschouwen:

- alleen handmatig af en toe phpMyAdmin exporteren;
- alleen backups op dezelfde webserver bewaren;
- alleen vertrouwen op een provider zonder ooit restore te testen;
- een bestand bewaren zonder integriteitscontrole;
- Laravel-cutover uitvoeren zonder apart herstelpunt.
