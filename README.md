# JobPilot

JobPilot è un gestionale Laravel per organizzare le candidature lavorative, confrontare il profilo del candidato con gli annunci e generare versioni mirate del curriculum senza inventare competenze o esperienze.

## Obiettivi

- profilo professionale strutturato;
- importazione e versionamento dei CV;
- acquisizione degli annunci di lavoro;
- matching deterministico e spiegabile;
- generazione controllata di CV e lettere di presentazione;
- tracciamento delle candidature e dei relativi stati;
- registrazione delle operazioni AI senza salvare i payload completi per impostazione predefinita.

## Stack iniziale

- PHP 8.3+
- Laravel 13
- SQLite in sviluppo
- PostgreSQL compatibile per la produzione
- Livewire, Alpine.js e Tailwind CSS nelle fasi successive

## Stato del progetto

Il repository contiene attualmente l'installazione Laravel di base. Lo sviluppo procederà per micro-patch verificabili.

## Installazione locale

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

Su Windows, se il comando `cp` non è disponibile:

```bat
copy .env.example .env
```

## Avvio in sviluppo

```bash
composer run dev
```

## Principi del progetto

- nessuna informazione professionale può essere inventata;
- il matching deve essere deterministico, riproducibile e spiegabile;
- i modelli Eloquent devono restare leggeri;
- la logica applicativa deve risiedere in servizi dedicati;
- le modifiche vengono introdotte attraverso micro-patch e test automatici.
