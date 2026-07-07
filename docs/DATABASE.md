# Modello dati di JobPilot

## Stato del documento

Schema logico approvato per la Fase 1. Le migration verranno introdotte in patch successive e verificate su SQLite con compatibilità PostgreSQL.

## Principi

- i dati professionali approvati costituiscono la fonte di verità;
- CV e output generati sono versioni/snapshot, non sostituiscono il profilo strutturato;
- requisiti e relative valutazioni devono essere ispezionabili;
- alias di skill e software consentono normalizzazione deterministica;
- i payload AI completi sono separati dai log operativi;
- i dati di utenti diversi non vengono condivisi, salvo vocabolari globali controllati.

## Aree del database

### Autenticazione e autorizzazione

- `users`;
- tabelle Laravel per sessioni, cache e code;
- tabelle Spatie Permission per ruoli e permessi.

### Profilo candidato

- `profiles`;
- `sectors`;
- `profile_sector`;
- `work_experiences`;
- `work_experience_tasks`;
- `education`;
- `skills`;
- `skill_aliases`;
- `profile_skill`;
- `software`;
- `software_aliases`;
- `profile_software`;
- `languages`;
- `profile_language`;
- `certifications`;
- `projects`.

### CV

- `cv_versions`.

### Annunci e matching

- `job_ads`;
- `job_ad_requirements`;
- `job_ad_benefits`;
- `job_matches`.

### Candidature

- `applications`;
- `application_status_histories`;
- `cover_letters`.

### Entità trasversali

- `attachments`;
- `notes`;
- `ai_operations`;
- `ai_operation_payloads`;
- `activity_logs`.

## Relazioni principali

```text
User 1 --- 1 Profile
User 1 --- N CvVersion
User 1 --- N JobAd
User 1 --- N Application
User 1 --- N CoverLetter

Profile 1 --- N WorkExperience
WorkExperience 1 --- N WorkExperienceTask
Profile 1 --- N Education
Profile 1 --- N Certification
Profile 1 --- N Project
Profile N --- N Sector
Profile N --- N Skill
Profile N --- N Software
Profile N --- N Language

Skill 1 --- N SkillAlias
Software 1 --- N SoftwareAlias

JobAd 1 --- N JobAdRequirement
JobAd 1 --- N JobAdBenefit
JobAd 1 --- N JobMatch
Profile 1 --- N JobMatch

JobAd 1 --- N Application
Application 1 --- N ApplicationStatusHistory
Application N --- 1 CvVersion (opzionale)
Application N --- 1 CoverLetter (opzionale)

Attachment e Note usano relazioni polimorfiche.
AiOperation può riferirsi in modo polimorfico all'entità coinvolta.
AiOperation 1 --- 0..1 AiOperationPayload
```

## Decisioni sui vocabolari

`skills`, `software`, `languages` e `sectors` sono vocabolari condivisi.

Le nuove voci non devono diventare automaticamente equivalenti a voci esistenti. L'equivalenza viene rappresentata tramite alias approvati:

```text
Skill: Microsoft Excel
Alias: Excel
Alias: MS Excel
```

Gli alias devono essere normalizzati per confronto case-insensitive e protetti da vincoli univoci coerenti.

## Dati del profilo

Le esperienze mantengono sia:

- una `description` completa, utile per il CV;
- mansioni discrete in `work_experience_tasks`, utili come evidenze per il matching.

I pivot di skill e software possono registrare livello, anni di esperienza, fonte e note. Una voce proposta dall'AI non è considerata approvata finché l'utente non la conferma.

## Versionamento CV

`cv_versions` conserva uno snapshot strutturato del contenuto del CV e riferimenti opzionali a:

- versione sorgente;
- candidatura che ha motivato la generazione;
- operazione AI;
- PDF renderizzato.

La generazione di una nuova versione non modifica il profilo né una versione precedente.

## Requisiti e matching

`job_ad_requirements` rappresenta requisiti discreti con:

- tipo;
- etichetta;
- obbligatorietà;
- dettagli strutturati opzionali.

`job_matches.breakdown_json` contiene il risultato per requisito, incluse le evidenze. Questa scelta consente di conservare uno snapshot del calcolo; la logica di calcolo resta in codice PHP testabile.

Struttura indicativa:

```json
{
  "requirements": [
    {
      "requirement_id": 10,
      "status": "present",
      "weight": 1,
      "evidence": [
        {
          "type": "profile_skill",
          "id": 7
        }
      ]
    }
  ],
  "formula_version": "v1"
}
```

## Allegati

`attachments` è generica e polimorfica. Non viene usato un enum rigido per il tipo documentale; un'etichetta libera e metadati tecnici consentono estensioni future.

Il percorso di storage non deve contenere dati sensibili leggibili e l'accesso ai file dovrà passare da autorizzazione applicativa.

## Logging AI

`ai_operations` conserva soltanto metadati operativi e audit essenziali.

`ai_operation_payloads` conserva prompt e risposta completi solo quando la configurazione lo consente. Il comportamento predefinito deve essere disattivato.

## Enum previsti

Gli stati chiusi vengono rappresentati con enum PHP e colonne stringa compatibili fra SQLite e PostgreSQL. Fra gli enum previsti:

- disponibilità;
- preferenza/remotizzazione;
- tipo contratto;
- categoria e livello skill;
- fonte del dato;
- livello linguistico CEFR;
- grado di istruzione;
- sorgente e stato estrazione annuncio;
- tipo requisito;
- stato del match requisito;
- tipo versione CV;
- stato e canale candidatura;
- stato operazione AI.

Non verranno usati enum nativi specifici del database.

## Vincoli di integrità da applicare nelle migration

- foreign key esplicite;
- indici sulle colonne di relazione e sugli stati interrogati frequentemente;
- vincoli univoci sui pivot;
- un solo profilo per utente;
- un solo match corrente per coppia annuncio/profilo, salvo futura introduzione di versionamento del calcolo;
- coerenza fra `is_current` ed `end_date` validata a livello applicativo;
- cancellazione `cascade` solo per dati realmente subordinati;
- `restrict` o `nullOnDelete` per riferimenti storici da preservare.

## Strategia delle migration

Le migration verranno suddivise per dipendenze:

1. lookup e profilo;
2. esperienze/formazione/competenze;
3. CV e operazioni AI;
4. annunci e requisiti;
5. candidature e storico;
6. allegati, note e activity log;
7. foreign key differite dove necessarie per evitare dipendenze circolari.

Ogni gruppo dovrà superare almeno:

```bash
php artisan migrate:fresh
php artisan test
```

prima del gruppo successivo.
