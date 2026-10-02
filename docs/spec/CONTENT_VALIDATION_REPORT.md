# Trainingsapp - Validatierapport trainingscontent v1.0

## Bron

Bestand: `source/Sporten.html`

SHA-256:

```text
d473f2558df3913611fc6bf60031be6881d4a33074018c131764ddd2173b12de
```

## Gecontroleerde aantallen

- trainingsblokken: 5;
- workouttemplates: 30;
- block-workout koppelingen: 30;
- oefenregels: 143;
- YouTube-links: 30;
- standaard cycli: 14;
- standaard persoonlijke assignments: 84.

## Fidelitycontrole

Automatische controle bevestigt:

- de 30 URLs in `training-program.json` staan in exact dezelfde volgorde en met exact dezelfde waarde als in `Sporten.html`;
- `source_text` van iedere workout is exact gelijk aan de uitgeschreven broninhoud;
- `source_line` blijft per oefening beschikbaar;
- er zijn geen workoutblokken uit de bron weggelaten.

## Structurering die bewust is toegevoegd

De bron bevat veel vrije tekst. Voor de applicatie is daaruit aanvullend afgeleid:

- workouttitel uit de eerste tekst tussen haakjes;
- technische categorie;
- protocoltype;
- timerconfiguratie;
- oefennaam;
- sets/reps waar expliciet in bron aanwezig;
- lijstvolgorde.

Deze structurering vervangt de bron niet. Daarom worden oorspronkelijke labels en teksten mee opgeslagen.

## Bekende bronafwijkingen

### `Abs AMRRAP`

In blok 3 gebruikt de bron het label `Abs AMRRAP`. De beschrijving zegt `timer op 5 minuten en zoveel mogelijk rondes`, wat inhoudelijk overeenkomt met AMRAP.

Datasetbehandeling:

- `source_category_label`: `Abs AMRRAP` behouden;
- technische categorie: `abs`;
- `protocol_type`: `amrap`.

De bronspelling wordt dus niet stilzwijgend verwijderd.

### `Upperbody`

De bron gebruikt `Upperbody`.

Datasetbehandeling:

- `source_category_label`: `Upperbody`;
- technische categorie: `upper_body`;
- presentatielabel: `Upper body`.

### Nederlandse/Engelse oefennamen

Oefennamen worden inhoudelijk niet vertaald of geharmoniseerd. Bijvoorbeeld `reverse lunge`, `achterwaartse lunge` en `zijwaartse lunge` blijven zoals in de betreffende bronregel.

### Tabata nummering

In sommige blokken staan Tabata-oefeningen zonder nummer en in andere als `1 goblet squat`, `2 wisselende snatch`, enzovoort. De voorloopcijfers zijn als lijstvolgorde geïnterpreteerd, niet als reps. De oorspronkelijke regel blijft in `source_line` bewaard.

### One More Rep

`One More Rep` noemt 60 herhalingen per oefening en geen expliciet aantal rondes. Dit is als `circuit` met één beschreven doorloop gestructureerd. De bronnotitie over partner/kids blijft in `instructions`/`source_text` behouden.

## Conclusie

Dataset 1.0 is geschikt als seed/source of truth voor de applicatie. Eventuele toekomstige inhoudelijke correcties moeten bewust als dataset/program version wijziging worden uitgevoerd, niet stil tijdens backend- of frontendbouw.
