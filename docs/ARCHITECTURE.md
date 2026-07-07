# Architettura di JobPilot

## Stato del documento

Questo documento descrive l'architettura approvata per la Fase 1. Le componenti indicate non sono necessariamente già implementate.

## Obiettivo

JobPilot è un gestionale personale per organizzare candidature lavorative, confrontare in modo verificabile il profilo del candidato con gli annunci e produrre versioni mirate del curriculum senza inventare informazioni.

## Stile architetturale

L'applicazione adotta un **monolite modulare Laravel**.

Il monolite modulare è preferito a microservizi separati perché:

- riduce la complessità operativa iniziale;
- mantiene transazioni e migrazioni semplici;
- consente test end-to-end rapidi;
- permette comunque di isolare chiaramente i domini applicativi;
- evita dipendenze infrastrutturali premature.

## Moduli di dominio previsti

- `Profile`: profilo candidato, esperienze, formazione, competenze, software, lingue, certificazioni e progetti;
- `CvImport`: importazione e normalizzazione controllata di CV PDF/DOCX;
- `JobAd`: acquisizione e approvazione degli annunci;
- `Matching`: confronto deterministico e spiegabile fra profilo e requisiti;
- `CvGeneration`: generazione e versionamento di CV mirati;
- `CoverLetter`: generazione controllata delle lettere di presentazione;
- `Application`: gestione delle candidature e dello storico degli stati;
- `Dashboard`: statistiche e indicatori derivati dai dati applicativi.

## Regole sulle dipendenze

1. I Model Eloquent rappresentano dati e relazioni, non orchestrano processi applicativi.
2. La logica di business risiede in servizi o action dedicate.
3. I moduli non chiamano direttamente provider AI esterni.
4. L'accesso all'AI avviene esclusivamente tramite un contratto applicativo, ad esempio `AiClientContract`, e un gateway sostituibile.
5. Il matching non dipende dall'AI: deve essere riproducibile con PHP e dati persistiti.
6. Quando utile, la comunicazione fra moduli usa eventi applicativi o contratti, evitando accoppiamenti circolari.
7. Nessun modulo può inventare o inferire come certa un'informazione professionale non approvata dall'utente.

## Struttura applicativa prevista

```text
app/
├── Enums/
├── Models/
├── Modules/
│   ├── Profile/
│   ├── CvImport/
│   ├── JobAd/
│   ├── Matching/
│   ├── CvGeneration/
│   ├── CoverLetter/
│   ├── Application/
│   └── Dashboard/
├── Services/
│   ├── AI/
│   ├── Parsing/
│   └── Logging/
├── Support/
│   └── Contracts/
├── Policies/
└── Http/
```

Le cartelle vengono introdotte solo quando contengono classi effettivamente utilizzate. Non verranno creati namespace vuoti per simulare modularità.

## Matching deterministico

Ogni requisito estratto da un annuncio viene valutato separatamente con uno dei seguenti stati:

- `present`;
- `partial`;
- `absent`;
- `unverifiable`.

Ogni valutazione deve includere evidenze tracciabili verso dati approvati del profilo, per esempio una competenza, un software, una lingua o una mansione associata a un'esperienza.

Lo score complessivo è una conseguenza matematica delle valutazioni per requisito. Non costituisce una previsione della probabilità di assunzione.

## Uso dell'AI

L'AI può essere utilizzata per:

- estrazione strutturata da CV e annunci;
- normalizzazione linguistica;
- riscrittura fedele di contenuti approvati;
- generazione di lettere di presentazione;
- spiegazione delle modifiche proposte.

L'AI non può:

- creare esperienze, competenze, risultati, date o certificazioni;
- trasformare requisiti mancanti in requisiti soddisfatti;
- calcolare autonomamente lo score definitivo;
- inviare candidature senza approvazione esplicita dell'utente.

## Audit delle operazioni AI

La tabella operativa conserva metadati come provider, modello, operazione, hash del prompt, token, costo stimato, durata, stato ed errore.

Prompt e risposte completi sono payload opzionali, separati e disattivati per impostazione predefinita, per ridurre rischi di privacy e crescita del database.

## Persistenza

- SQLite in sviluppo;
- compatibilità PostgreSQL per produzione;
- niente tipi o funzioni specifiche MySQL;
- JSON usato per snapshot e dettagli variabili, non per sostituire relazioni di dominio stabili;
- foreign key, indici e vincoli univoci definiti nelle migration;
- soft delete solo quando il recupero del dato è utile e coerente con il dominio.

## Sicurezza

- autenticazione Laravel con email e password;
- ruoli e permessi tramite Spatie Permission;
- autorizzazione tramite Policies;
- `$fillable` esplicito sui Model;
- Form Request per validazione degli input nelle fasi UI/API;
- accesso limitato ai dati del proprietario;
- nessun supporto multi-tenant o team nella prima versione.

## Metodo di sviluppo

Il progetto procede per micro-patch:

1. cambiamento ristretto e motivato;
2. test automatici o verifiche riproducibili;
3. controllo delle migration su database pulito;
4. commit descrittivo;
5. nessuna nuova funzionalità se la patch corrente non è stabile.
