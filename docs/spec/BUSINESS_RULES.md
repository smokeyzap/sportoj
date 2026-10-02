# Trainingsapp - Business Rules v1.0

Status: definitief voor bouwfase 1a en 1b.

Dit document is leidend voor de tijdelijke PHP API, de Lovable frontend en de latere Laravel implementatie. Bij strijdigheid tussen implementatie en dit document geldt dit document samen met `openapi.yaml` als norm. De frontend mag deze regels niet zelfstandig opnieuw interpreteren.

## 1. Kernbegrippen

- **Program**: het volledige trainingsprogramma.
- **Training Block**: een blok met zes workouts dat een standaard aantal cycli wordt herhaald.
- **Cycle**: een doorloop van de zes workoutposities maandag tot en met zaterdag.
- **Extra Cycle**: een door de gebruiker toegevoegde cyclus na het standaard aantal cycli van een blok.
- **Workout Template**: de inhoud van een workout.
- **Workout Assignment**: een geplande persoonlijke workout binnen een specifieke blok/cyclus/positie.
- **Workout Session**: een daadwerkelijke uitvoering van een workout.
- **User Program**: de persoonlijke uitvoering van een programmaversie door een gebruiker.

## 2. Programmastructuur

**BR-001** Het programma bestaat in versie 1.0 uit vijf trainingsblokken.

**BR-002** De standaard cycli zijn: blok 1 = 3, blok 2 = 3, blok 3 = 3, blok 4 = 3, blok 5 = 2.

**BR-003** Iedere cyclus bevat exact zes posities in de vaste volgorde maandag, dinsdag, woensdag, donderdag, vrijdag, zaterdag.

**BR-004** De weekdaglabels zijn programmabenamingen en zijn niet gekoppeld aan de werkelijke kalenderdag.

**BR-005** Een gebruiker kan maximaal één actieve of gepauzeerde uitvoering van dezelfde programmaversie tegelijk hebben. Deze regel wordt transactioneel door de backend afgedwongen.

**BR-006** Bij het starten van een User Program worden alle 84 standaard Workout Assignments aangemaakt.

**BR-007** Assignments van toekomstige trainingsblokken bestaan al, maar zijn niet selecteerbaar als reguliere programmaworkout zolang het betreffende blok niet actief is.

## 3. Eerste gebruik en startpositie

**BR-010** Bij de eerste programmastart kiest de gebruiker `beginning` of `position`.

**BR-011** Bij `beginning` wordt blok 1, cyclus 1, positie 1 de eerste aanbevolen workout.

**BR-012** Bij `position` kiest de gebruiker een oorspronkelijke programmaweek 1 tot en met 14 en een positie 1 tot en met 6.

**BR-013** De backend vertaalt oorspronkelijke week en positie naar trainingsblok, cyclus en workoutpositie.

**BR-014** Alle assignments vóór het gekozen startpunt krijgen status `prior_to_start`.

**BR-015** `prior_to_start` geldt voor voortgang als verwerkt, maar wordt nooit als daadwerkelijk uitgevoerde training in de workout-historie getoond.

**BR-016** Volledig gepasseerde trainingsblokken vóór het gekozen startpunt krijgen `user_program_blocks.status = prior_to_start`.

## 4. Assignmentstatussen

Toegestane statussen zijn `pending`, `started`, `completed`, `skipped`, `prior_to_start`.

**BR-020** Nieuwe beschikbare assignments starten als `pending` tenzij zij vóór het gekozen startpunt liggen.

**BR-021** `started` betekent dat een programmaworkout is begonnen maar nog niet is afgerond.

**BR-022** `completed` betekent dat de programmaworkout succesvol is afgerond.

**BR-023** `skipped` betekent dat de gebruiker expliciet heeft gekozen deze programmaworkout over te slaan.

**BR-024** `prior_to_start` mag uitsluitend bij initiële programmastart worden toegekend.

**BR-025** De backend mag nooit stilzwijgend een `pending` assignment overslaan doordat kalenderdagen verstrijken.

## 5. Recommendation engine

**BR-030** Alleen de backend bepaalt de aanbevolen volgende actie.

**BR-031** De Lovable frontend en een toekomstige native client tonen het resultaat van `GET /api/v1/me/today` en berekenen geen eigen volgende workout.

**BR-032** De prioriteit van `me/today` is:
1. onboarding wanneer geen actief programma bestaat;
2. een openstaande continuation decision;
3. een block decision;
4. een reeds gestarte programmaworkout hervatten;
5. de volgende workout op basis van de actieve continuation mode;
6. program completed wanneer het programma formeel is afgerond.

**BR-033** Er mag per User Program maximaal één reguliere Workout Assignment tegelijk status `started` hebben.

**BR-034** Zolang een assignment `started` is, krijgt deze prioriteit als `resume_workout`.

**BR-035** Het starten van een andere reguliere assignment terwijl een andere assignment `started` is, wordt geweigerd met `ACTIVE_WORKOUT_EXISTS`.

## 6. Normale programmavolgorde

**BR-040** De standaard continuation mode is `program_sequence`.

**BR-041** In `program_sequence` is de aanbevolen workout de eerste `pending` assignment binnen het actieve trainingsblok, gesorteerd op cyclusnummer en positie.

**BR-042** `completed`, `skipped` en `prior_to_start` worden bij deze zoekactie gepasseerd.

**BR-043** Kalenderdatum of weekdag heeft geen invloed op deze sortering.

## 7. Een andere training kiezen

**BR-050** De gebruiker mag binnen het actieve trainingsblok een andere `pending` assignment kiezen dan de aanbevolen assignment.

**BR-051** Assignments uit een nog niet actief trainingsblok kunnen niet als reguliere programmaworkout worden gestart.

**BR-052** Een workout uit een toekomstig blok mag wel als vrijwillige extra workout worden uitgevoerd. Dit is dan een `session_type = extra` en beïnvloedt de programmavoortgang niet.

**BR-053** Bij start van een programmaworkout registreert de backend of de assignment op dat moment de aanbevolen assignment was via `started_as_recommended`.

**BR-054** Wanneer een niet-aanbevolen programmaworkout wordt voltooid, ontstaat een verplichte `continuation_decision` voordat de backend een normale volgende programmaworkout aanbeveelt.

**BR-055** De continuation decision biedt exact twee functionele keuzes:
- `program_sequence`: terug naar de normale programmavolgorde;
- `last_workout_sequence`: verder vanaf de zojuist voltooide workout.

**BR-056** Bij `program_sequence` wordt de oudste nog openstaande assignment binnen het actieve blok opnieuw het uitgangspunt.

**BR-057** Bij `last_workout_sequence` wordt de zojuist voltooide afwijkende assignment het continuation anchor.

**BR-058** In `last_workout_sequence` wordt de eerste `pending` assignment ná het anchor in dezelfde actieve blokvolgorde aanbevolen.

**BR-059** Na het voltooien van een workout die door `last_workout_sequence` werd aanbevolen, verschuift het anchor automatisch naar die voltooide assignment. Er wordt niet opnieuw om dezelfde keuze gevraagd.

**BR-060** Als de gebruiker tijdens `last_workout_sequence` opnieuw bewust afwijkt van de actuele aanbeveling, ontstaat opnieuw een `continuation_decision` met de nieuw voltooide workout als bron.

**BR-061** Eerdere nog openstaande assignments vóór het continuation anchor blijven `pending`. Zij worden niet automatisch voltooid of overgeslagen.

**BR-062** Als in `last_workout_sequence` geen latere `pending` assignment meer bestaat terwijl eerdere assignments nog `pending` zijn, valt de backend automatisch terug op `program_sequence`, wist het anchor en adviseert de oudste openstaande assignment.

**BR-063** Een trainingsblok kan nooit worden afgerond zolang eerdere `pending` of `started` assignments bestaan, ongeacht de continuation mode.

## 8. Training starten, hervatten en afronden

**BR-070** `start` op een `pending` assignment zet de assignment op `started` en maakt een Workout Session van type `program`.

**BR-071** `start` op een reeds `started` assignment retourneert de bestaande actieve sessie en maakt geen duplicaat.

**BR-072** Een `complete` verzoek op een `pending` assignment mag de start impliciet uitvoeren en daarna afronden. De backend registreert alsnog correct `started_as_recommended`.

**BR-073** Bij afronden krijgt assignment status `completed`, `completed_at` wordt gevuld en de actieve sessie krijgt status `completed`.

**BR-074** Het complete-endpoint is idempotent. Herhaling van hetzelfde verzoek mag geen tweede reguliere completion of sessie creëren.

**BR-075** Na afronding retourneert de backend direct de volgende actie. Dit kan workout, continuation decision, block decision of program completed zijn.

## 9. Rust, overslaan en heropenen

**BR-080** `Vandaag rust` is een frontendactie zonder wijziging van programma- of assignmentstatus.

**BR-081** In MVP wordt een rustdag niet als afzonderlijke databasegebeurtenis opgeslagen.

**BR-082** `skip` op een `pending` assignment zet de status op `skipped` en vult `skipped_at`.

**BR-083** Een `started` assignment moet eerst worden heropend/geannuleerd voordat hij kan worden overgeslagen, of de skip-service annuleert de actieve sessie transactioneel. De implementatie kiest één consistente route en documenteert deze. Voor API v1 geldt dat `skip` op `started` is toegestaan en de actieve sessie annuleert.

**BR-084** Een `skipped` assignment blokkeert verdere voortgang niet.

**BR-085** `reopen` is toegestaan voor `completed` en `skipped` assignments.

**BR-086** `reopen` zet de assignment terug op `pending`, wist assignment timestamps die bij de eindstatus horen en markeert de bijbehorende reguliere sessie als `cancelled` wanneer de completion wordt teruggedraaid.

**BR-087** Historische correctie gebeurt via `reopen`, niet via hard delete.

**BR-088** Na `reopen` berekent de backend de recommendation opnieuw. Een opnieuw geopende eerdere assignment kan daardoor weer de eerstvolgende recommendation worden in `program_sequence`.

## 10. Vrijwillig een workout opnieuw doen

**BR-090** Een gebruiker kan een workouttemplate vrijwillig als extra workout uitvoeren.

**BR-091** Een extra workout heeft `workout_assignment_id = NULL` en `session_type = extra`.

**BR-092** Een extra workout verschijnt in de historie.

**BR-093** Een extra workout verandert nooit assignmentstatus, cycle progress, block progress of recommendation state.

## 11. Cyclusafronding

**BR-100** Een cyclus is verwerkt wanneer alle zes assignments status `completed`, `skipped` of `prior_to_start` hebben.

**BR-101** `pending` en `started` betekenen dat de cyclus nog niet is verwerkt.

**BR-102** Als een normale cyclus is verwerkt en er nog een volgende reeds bestaande cyclus binnen `target_cycles` bestaat, gaat het programma zonder extra gebruikersbeslissing verder naar die volgende cyclus.

## 12. Trainingsblok en extra cyclus

**BR-110** Een trainingsblok bereikt `decision_required` wanneer alle assignments tot en met `target_cycles` verwerkt zijn.

**BR-111** Op dat moment retourneert `me/today` een `block_decision` en geen workout recommendation.

**BR-112** De gebruiker kan bij een block decision kiezen voor `extend` of `advance`, behalve bij het laatste blok waar `complete program` eveneens beschikbaar is.

**BR-113** `extend` verhoogt `target_cycles` exact met 1.

**BR-114** `extend` maakt exact zes nieuwe Workout Assignments aan voor de nieuwe cyclus.

**BR-115** Deze assignments krijgen `is_extra_cycle = 1`, status `pending` en posities 1 tot en met 6.

**BR-116** Een extra cyclus krijgt geen oorspronkelijk weeknummer. De UI toont bijvoorbeeld `Cyclus 4 - extra`.

**BR-117** Na iedere extra cyclus verschijnt opnieuw een block decision. Er is geen functioneel maximum aan het aantal extra cycli.

**BR-118** `extend` is idempotent op basis van actuele block state en request handling. Een dubbel request mag niet twee extra cycli creëren.

## 13. Naar volgend trainingsblok

**BR-120** `advance` is alleen toegestaan als het huidige blok `decision_required` is en er een volgend blok bestaat.

**BR-121** `advance` zet het huidige user_program_block op `completed` en het volgende op `active`.

**BR-122** De eerste openstaande assignment van het nieuwe actieve blok wordt daarna aanbevolen.

**BR-123** Continuation mode wordt bij een blokovergang teruggezet naar `program_sequence` en een eventueel anchor wordt gewist.

## 14. Programma-einde en opnieuw starten

**BR-130** Na verwerking van de laatste actieve cyclus van het laatste blok verschijnt een block decision met minimaal `extend` en `complete_program`.

**BR-131** `complete_program` zet het laatste blok op `completed`, het User Program op `completed` en vult `completed_at`.

**BR-132** Na formele afronding retourneert `me/today` `program_completed` totdat een nieuw programma wordt gestart.

**BR-133** `restart` maakt een volledig nieuw User Program inclusief nieuwe standaard assignments.

**BR-134** Herstart verwijdert, reset of overschrijft nooit de oude User Program, Workout Assignments of Workout Sessions.

## 15. Historie en voortgang

**BR-140** Workout-historie toont daadwerkelijke Workout Sessions en expliciete skipped assignments als aparte historische statusitems.

**BR-141** `prior_to_start` wordt niet in de normale workout-historie getoond.

**BR-142** Historie wordt aflopend op gebeurtenistijd gesorteerd en vanaf API v1 gepagineerd.

**BR-143** De primaire voortgangsweergave bestaat uit huidig blok, huidige cyclus en aantallen completed/skipped/pending.

**BR-144** Bij een extra cyclus groeit het totaal aantal assignments van het blok. Bijvoorbeeld 18/18 verwerkt wordt na `extend` 18/24.

**BR-145** `cancelled` sessions blijven intern bewaard voor correctietraceerbaarheid maar worden standaard niet als voltooide workout in de normale historie getoond.

## 16. Timers en trainingscontent

**BR-150** De backend levert protocol- en timerconfiguratie als workoutdata.

**BR-151** De daadwerkelijke timer loopt lokaal in de frontend.

**BR-152** De backend bewaart in MVP geen seconde-tot-seconde timerstand.

**BR-153** Als een pagina tijdens een timer wordt gesloten, blijft de workout `started`, maar de timer mag bij hervatten opnieuw worden gestart.

**BR-154** De broninhoud uit `Sporten.html` wordt in `training-program.json` bewaard met oorspronkelijke source_text/source_line naast de gestructureerde interpretatie.

**BR-155** Substantiële toekomstige wijzigingen aan trainingsinhoud leiden bij voorkeur tot een nieuwe programmaversie of nieuwe workouttemplate, zodat historische betekenis intact blijft.

## 17. Multi-user en autorisatie

**BR-160** Alle persoonlijke gegevens zijn aan een user gekoppeld.

**BR-161** Iedere API-call op persoonlijke resources controleert ownership server-side.

**BR-162** Publieke ULIDs zijn geen vervanging voor autorisatie.

**BR-163** Gebruiker A kan resources van gebruiker B nooit lezen, starten, afronden, overslaan, heropenen of wijzigen.

## 18. Authenticatie en wachtwoorden

**BR-170** De webapp gebruikt een server-side sessie met een opaque willekeurig token in een Secure, HttpOnly cookie.

**BR-171** Alleen een SHA-256 hash van het sessietoken wordt in `auth_sessions` opgeslagen.

**BR-172** Muterende cookie-authenticated requests zijn beschermd met CSRF-tokenvalidatie.

**BR-173** Wachtwoorden worden uitsluitend gehasht. Er is geen reversible encryptie van wachtwoorden.

**BR-174** De tijdelijke PHP implementatie gebruikt `password_hash()` met `PASSWORD_ARGON2ID` wanneer beschikbaar. Bcrypt is uitsluitend fallback wanneer de hosting Argon2id niet ondersteunt.

**BR-175** Login gebruikt `password_verify()` en controleert na succesvolle authenticatie met `password_needs_rehash()` of een veiligere/current hash nodig is.

**BR-176** De databasekolom voor wachtwoordhashes is `VARCHAR(255)`.

## 19. Tijd, identifiers en data-integriteit

**BR-180** Persistente timestamps worden in UTC opgeslagen.

**BR-181** De frontend presenteert tijden in de timezone van de gebruiker.

**BR-182** Interne database-identifiers zijn BIGINT auto-increment.

**BR-183** Externe API-identifiers zijn ULIDs in `public_id` en de API exposeert geen interne database-ID's.

**BR-184** Kritieke mutaties worden transactioneel uitgevoerd.

**BR-185** Hard delete van workout-historie en User Programs is geen normale MVP-functie.

## 20. PHP naar Laravel cutover

**BR-190** MariaDB is vanaf fase 1a de productiedatabase en blijft bij de Laravel-cutover bestaan.

**BR-191** `schema.sql` versie 1.0.0 is de officiële databasebaseline.

**BR-192** Laravel mag bij cutover geen database reset, destructieve herimport of verlies van progressie vereisen.

**BR-193** `openapi.yaml` is het API-contract. Laravel moet voor API v1 een drop-in replacement van de tijdelijke PHP API zijn.

**BR-194** Endpointpaden, requestvelden, responsevormen, foutcodes en kernsemantiek blijven bij de eerste Laravel-cutover gelijk.

**BR-195** Een eenmalige nieuwe login na backend-cutover is toegestaan als technisch nodig. Trainingsdata en progressie mogen daardoor niet veranderen.

## 21. Backup en herstel

**BR-200** Zodra echte progressie wordt opgeslagen, wordt dagelijks een consistente MariaDB dump gemaakt.

**BR-201** Backups staan buiten de publieke webroot.

**BR-202** Er wordt minimaal één tweede kopie op een andere storage/server bewaard.

**BR-203** Richtretentie is 14 dagelijkse, 8 wekelijkse en 6 maandelijkse herstelpunten.

**BR-204** Minimaal periodiek, bij voorkeur maandelijks, wordt een restore naar een tijdelijke database getest.

**BR-205** Direct vóór de Laravel-cutover wordt een afzonderlijke volledige backup gemaakt en gecontroleerd.
