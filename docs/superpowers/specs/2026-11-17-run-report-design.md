# Rapport de run et steps de contrôle — design

Date : 17/11/2026. Projet : bundle `sevj/fluxx` (1.18.0 → 1.19.0), premier consommateur : `connector-sipperec` (`efficy_sync`).
Maquette de la vue validée : https://claude.ai/artifact/SLVqyXSMpvnRoNHS6qUvmF (illustrée avec le run 364 d'`efficy_sync`).

## Intention

Aujourd'hui, comprendre ce qu'un run a fait demande du SQL sur `fluxx_workflow_step_run` (métadonnées libres, durées recalculées à la main) et des scripts de vérification lancés hors de Fluxx. On veut, pour chaque run **terminé**, un **rapport enregistré** consultable dans le back-office : ce que le workflow a lu, transformé, écrit, exclu ou refusé, comment il a performé, quelles anomalies sont apparues, et ce que les contrôles d'invariants ont constaté. Un écart grave doit faire échouer le run (signal), un écart toléré doit rester visible sans bloquer.

## Objectifs

1. Un rapport enregistré par run terminé, pour les workflows qui le déclarent.
2. Une vue dédiée dans l'UI Fluxx (cf. maquette) : synthèse, objets, performances, anomalies, sections applicatives.
3. Un vocabulaire typé pour qu'un step déclare ses métriques d'objets et ses anomalies.
4. Un type de step **Check** : contrôles d'invariants en lecture seule, chaque contrôle avec une sévérité.
5. Un statut « terminé avec avertissements » pour les steps et les runs.
6. Côté connecteur : un premier step `efficy_sync_verify` et l'adoption du vocabulaire dans les steps d'`efficy_sync`.

## Non-objectifs

- Comparaison avec le run précédent (écartée : pas pertinente).
- Rapport pour les runs en échec, partiellement en échec ou annulés (décision : runs terminés uniquement).
- Correction automatique d'un écart par un Check : jamais.
- Contrôles liés à des tickets ponctuels : hors process ; les Checks ne portent que des invariants durables.
- Export PDF/CSV du rapport (le bouton « Exporter » de la maquette est hors périmètre de cette version).

## Anti-objectifs (ce qui rendrait la fonctionnalité ratée même si elle « marche »)

- Un rapport qui retarde la fin du run ou change son statut en cas d'échec de construction.
- Un Check qui écrit dans un système externe.
- Des rapports produits pour chaque petit run de webhook alors que personne ne les lit.
- Des avertissements que personne ne voit (visibles seulement en ouvrant le rapport).

## Contraintes

- Bundle réutilisable : rien de spécifique à HubSpot/Efficy dans Fluxx.
- Pas de migrations Doctrine : schéma dans `migrations/schema-postgresql.sql`, montée de version par `migrations/upgrade-1.19.0-postgresql.sql`.
- Textes de l'UI via traductions (`translations/fluxx.{en,fr}.yaml`), code en anglais (AGENTS.md du bundle).
- Rétrocompatibilité : un step qui ne déclare rien et un workflow sans rapport fonctionnent comme en 1.18.0.
- Côté connecteur : pas de commentaires dans le code, Check 100 % lecture seule, appels HubSpot via `src/Client/`.

## Approches considérées

| Approche | Pour | Contre |
|---|---|---|
| A. Step « Report » placé en fin de graphe | explicite, visible dans le graphe | à ajouter à chaque workflow, couplé à la topologie (jonction finale) |
| B. Rapport automatique pour tout run terminé | aucun changement de workflow | un rapport par petit run de webhook ; l'opt-in finit caché en configuration |
| **C. Rapport = propriété du workflow (retenue)** | opt-in visible dans la définition, graphe intact, indépendant de la topologie | un message asynchrone de plus à gérer |

Choix de l'utilisateur : **C**. Les Checks, eux, restent de vrais steps (ils travaillent, prennent du temps, peuvent échouer).

## Ce qui est décidé

### 1. Déclaration

`WorkflowDefinition` gagne un paramètre optionnel `report: ?WorkflowReportConfiguration = null`.

```php
new WorkflowReportConfiguration(
    sections: [EfficySyncReportSection::class], // services applicatifs, optionnels
)
```

Sans `report`, aucun rapport n'est construit (comportement 1.18.0).

### 2. Déclenchement

Quand le runtime marque un run `completed` ou `completed_with_warnings` (`FluxxRuntime`, finalisation du run, l. ~680) et que la définition a un `report`, il dispatche un `BuildRunReportMessage(runId)` sur le transport `fluxx`. Un handler construit le rapport et l'enregistre. Échec de construction : retry Messenger standard ; le statut du run n'est jamais modifié. Commande `fluxx:run:report <run-id>` et bouton « Reconstruire le rapport » sur la page du run pour (re)construire à la demande (runs antérieurs, échec).

### 3. Sources du rapport

1. **Fluxx (automatique)** : pour chaque step run du run (dernier step run par code) : type, statut, début/fin, durée, `processed/success/error_count`, pic mémoire. Dérivés : chemin critique (chaîne de dépendances dont la somme des durées + attentes donne la fin du run), débit = `processed_count / durée`.
2. **Steps (déclaratif)** : `WorkflowStepResult` gagne un paramètre optionnel `report: ?StepReport = null`. `StepReport` est un value object construit par une API fluide :
   - `StepReport::objects(string $label)` puis `->read(int)`, `->created(int)`, `->updated(int)`, `->excluded(string $reason, int $count)`, `->rejected(list<array{key: string, message: string}>)` ;
   - `->anomaly(string $title, array<string, int|string> $lines, Severity $severity = Severity::Warning)`.
   Plusieurs steps peuvent contribuer au même objet (même `label`) : le rapport additionne.
   **Transition** : si un step ne fournit pas de `report`, Fluxx lit dans ses métadonnées les clés conventionnelles `created`, `updated`, `excluded` (tableau raison → nombre), `rejected_records` (liste `{efficy_key|key, message}`), et utilise le code du step comme label.
3. **Checks** : résultats des steps de type Check (section 5).
4. **Sections applicatives** : services implémentant `RunReportSectionInterface { public function build(WorkflowRun $run): ReportSection; }`, déclarés dans `WorkflowReportConfiguration::$sections`. Une section = titre + lignes (libellé, détail, statut optionnel). Une section qui lève une exception est remplacée par une section « indisponible » avec le message ; le rapport est quand même enregistré.

### 4. Stockage

Table `fluxx_workflow_run_report` (plus colonne `report` sur `fluxx_workflow_step_run` et `warning_count` sur `fluxx_workflow_run`, cf. §9) : `id`, `workflow_run_id` (FK unique, `ON DELETE CASCADE`), `format_version` (int, 1), `content` (JSON), `built_at`. Un seul rapport par run (reconstruire = remplacer). Le rapport survit à la purge des payloads (`fluxx:prune:payloads`) et suit la purge des runs (`fluxx:prune:runs`, cascade).

### 5. Steps Check

- Nouveau type `WorkflowStepType::Check`. Le handler implémente `CheckStepInterface` (extension d'`ExecutableWorkflowStepInterface`) et renvoie un `WorkflowStepResult` portant une `CheckReport` : liste de contrôles `{name, severity: Blocking|Warning, status: Ok|Deviation, expected, actual, samples: list<string> (≤ 20)}`.
- Statut du step Check calculé par Fluxx : au moins un écart **bloquant** → step `failed` (message : liste des contrôles bloquants en écart) ; sinon au moins un écart **avertissement** → step `completed_with_warnings` ; sinon `completed`. Une exception du handler (panne technique) → échec normal avec retry.
- Lecture seule : contrat documenté du type ; Fluxx ne peut pas l'imposer techniquement.
- Placement dans le graphe : libre (en parallèle des derniers steps d'écriture ou après la jonction finale), au choix du workflow.

### 6. Statut « terminé avec avertissements »

- Nouveaux cas `WorkflowStepRunStatus::CompletedWithWarnings` et `WorkflowRunStatus::CompletedWithWarnings` (`completed_with_warnings`).
- Run : `completed_with_warnings` si tous les steps sont terminés et au moins un est `completed_with_warnings`. Les steps aval d'un step `completed_with_warnings` s'exécutent normalement (ce n'est pas un échec).
- Partout où `Completed` est traité comme terminal/réussi (relaunchable, verrous, statistiques, filtres de listes, recap), `CompletedWithWarnings` l'est aussi ; libellé et couleur dédiés (orange) dans l'UI ; le récapitulatif quotidien (`fluxx:recap:daily`) liste les runs avec avertissements et leurs contrôles en écart.

### 7. Vue

Onglet « Rapport » sur la page du run (`run_show.html.twig`) quand un rapport existe, conforme à la maquette : en-tête, 5 indicateurs (durée, objets écrits, liens si un objet est déclaré comme lien, refusés, exclus), tableau des objets, frise des steps (réutilise `RunTimeline`) avec chemin critique, 3 encarts (chemin critique, step le plus long, pic mémoire), anomalies dépliables, contrôles, sections applicatives. Une donnée absente s'affiche « — ». Steps < 1 min regroupés sous la frise.

### 8. Premier consommateur : `efficy_sync` (connector-sipperec)

- Adoption de `StepReport` dans les steps d'écriture et de transformation (`created`, `updated`, exclusions par règle, `rejected_records`, `unique_conflicts` en anomalie, propriétaires non résolus en anomalie).
- Steps Check `efficy_sync_verify_objects` et `efficy_sync_verify_links` (cf. §9), paramètre de run `verify=off|sample|full` (défaut `sample`). Invariants : nombre de fiches HubSpot `system_source=efficy` = nombre retenu par le run, par objet (bloquant) ; aucun doublon de `system_source_id` (bloquant) ; aucune adhésion sans pipeline (bloquant) ; « Principal » posé sur chaque contact à fonction principale en cours (avertissement) ; propriétés clés recopiées sur un échantillon (avertissement).
- `WorkflowReportConfiguration` sur `efficy_sync`, sans section applicative au départ.

### 9. Précisions après relecture (décisions de l'agent, à valider à la lecture)

- **Check bloquant et rapport** (conséquence acceptée : rapport pour les runs terminés uniquement) : un run en échec à cause d'un Check bloquant n'a pas de rapport ; les écarts (attendu, constaté, échantillons) sont enregistrés sur le step run du Check et affichés sur sa page (`step_run_show`). Un écart bloquant est une erreur **métier** : pas de retry (`BusinessWorkflowException`), contrairement à une panne technique.
- **Stockage intermédiaire** : `StepReport` et `CheckReport` sont enregistrés dans une nouvelle colonne JSON `report` de `fluxx_workflow_step_run`, que `fluxx:prune:payloads` ne touche pas ; un step terminé depuis le cache d'idempotence (`completeFromIdempotenceHit`) reprend le `report` du résultat en cache. Ainsi `fluxx:run:report` reconstruit un rapport juste même après purge des payloads.
- **Avertissements après purge** : `markRunsAsPayloadsPruned` remplace le statut du run ; une colonne `warning_count` (int, 0 par défaut) sur `fluxx_workflow_run` garde l'information pour les listes, filtres, statistiques et le récapitulatif.
- **`efficy_sync_verify` sur un run partiel** (`k_companies` renseigné) : les contrôles portent sur les clés que le run a écrites (toutes présentes dans HubSpot, aucun doublon, aucune adhésion de ce périmètre sans pipeline) ; la comparaison du nombre total de fiches `system_source=efficy` n'est faite qu'en import complet.
- **Placement des contrôles** : deux steps Check dans `efficy_sync` — `efficy_sync_verify_objects` après `efficy_writes_done`, en parallèle des associations (présence, doublons, pipelines, propriétés échantillonnées) ; `efficy_sync_verify_links` après la jonction finale (organisation principale, notes) pour ne pas lire un état intermédiaire.

## Laissé au constructeur

- Noms exacts des classes UI/handlers et découpage des templates.
- Algorithme du chemin critique (à documenter dans le code de test).
- Taille de l'échantillon en mode `sample` et liste exacte des propriétés vérifiées dans `efficy_sync_verify`.
- Format JSON interne du rapport (versionné par `format_version`).
