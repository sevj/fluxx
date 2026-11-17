# Rapport de run et steps Check (Fluxx 1.19.0) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fluxx construit et enregistre un rapport pour chaque run terminé d'un workflow qui le déclare, affiché dans un onglet de la page du run, avec des steps Check à sévérités et un statut « terminé avec avertissements ».

**Architecture:** Le rapport est une propriété du workflow (`WorkflowReportConfiguration`), construit de façon asynchrone (`BuildRunReportMessage`) quand le run passe `completed` / `completed_with_warnings`, à partir des step runs (durées, compteurs, mémoire), des `StepReport` / `CheckReport` enregistrés sur chaque step run (colonne `report`), et des sections applicatives. Les Checks sont de vrais steps dont Fluxx déduit le statut depuis leur `CheckReport`.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine ORM (schéma SQL versionné, pas de migrations Doctrine), Messenger (transport `fluxx`), Twig, PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-11-17-run-report-design.md` (lire §1 à §9 avant chaque tâche). Maquette de la vue : https://claude.ai/artifact/SLVqyXSMpvnRoNHS6qUvmF.

## Global Constraints

- Bundle réutilisable : rien de spécifique à HubSpot ou Efficy dans `src/`.
- Code, noms et commentaires en anglais ; tout texte visible via `translations/fluxx.{en,fr}.yaml` (AGENTS.md du bundle).
- Schéma : `migrations/schema-postgresql.sql` à jour + `migrations/upgrade-1.19.0-postgresql.sql` (idempotent : `IF NOT EXISTS`).
- Rétrocompatibilité : un workflow sans `report`, un step sans `report`, un run en 1.18.0 se comportent comme avant.
- Valeurs fixées par la spec : statut `completed_with_warnings` (step et run) ; sévérités `blocking` / `warning` ; statut de contrôle `ok` / `deviation` ; ≤ 20 échantillons par contrôle ; table `fluxx_workflow_run_report` (`id`, `workflow_run_id` FK unique `ON DELETE CASCADE`, `format_version` int = 1, `content` json, `built_at`) ; colonne `report` (json, nullable) sur `fluxx_workflow_step_run` ; colonne `warning_count` (int, défaut 0) sur `fluxx_workflow_run` ; commande `fluxx:run:report <run-id>` ; clés de métadonnées lues en transition : `created`, `updated`, `excluded` (raison → nombre), `rejected_records` (`{key|efficy_key, message}`).
- Un écart bloquant est une erreur métier (pas de retry) ; une exception du handler d'un Check reste une erreur technique (retry).
- Version : 1.19.0, entrée CHANGELOG datée à la publication.
- Tests : `docker run --rm --network adimeo_local_proxy -v /home/sevj/Projects/fluxx/fluxx-report:/app -w /app -e TZ=UTC -e FLUXX_TEST_DATABASE_URL='postgresql://app:ppa@connector-sipperec-bdd:5432/fluxx_test?serverVersion=13&charset=utf8' --entrypoint php connector-sipperec-server -d date.timezone=UTC vendor/bin/phpunit` (base `fluxx_test` dédiée ; ne jamais viser `bdd` ni `sipperec`).

## Review Focus

- Run `completed` d'un workflow SANS `report` : aucun message dispatché, aucune ligne de rapport. Test : Task 4 `no_report_without_configuration`.
- Construction du rapport en échec (section applicative qui lève, contenu inattendu) : le statut du run ne change jamais ; une section en échec devient « indisponible ». Test : Task 4 `failing_section_is_marked_unavailable_and_report_saved`.
- Run purgé (`payloads_pruned`) puis `fluxx:run:report` : rapport juste (les `report` des step runs survivent à la purge) et l'info avertissement conservée par `warning_count`. Test : Task 4 `rebuild_after_payload_prune`, Task 1 `prune_keeps_warning_count`.
- Step `completed_with_warnings` suivi d'autres steps : l'aval s'exécute, le run finit `completed_with_warnings`, relançable, verrou libéré. Test : Task 1 `downstream_runs_after_warning_step`.
- Step complété depuis le cache d'idempotence : son `report` est repris du résultat en cache. Test : Task 2 `idempotence_hit_keeps_report`.

---

## File Structure

| Fichier | Rôle |
|---|---|
| `src/Entity/Enum/WorkflowStepRunStatus.php`, `WorkflowRunStatus.php` | cas `CompletedWithWarnings` |
| `src/Entity/WorkflowRun.php`, `WorkflowStepRun.php` | `warningCount`, `report`, transitions |
| `src/Workflow/Runtime/WorkflowRunCompletionDecider.php`, `FluxxRuntime.php` | statut avec avertissements, persistance des reports, dispatch du rapport |
| `src/Workflow/Report/StepReport.php`, `ObjectStats.php`, `Anomaly.php` (créer) | vocabulaire déclaratif des steps |
| `src/Workflow/Check/*` (créer) | `CheckStepInterface`, `CheckReport`, `CheckResult`, `Severity`, `CheckOutcome` |
| `src/Workflow/Report/WorkflowReportConfiguration.php`, `RunReportSectionInterface.php`, `ReportSection.php`, `RunReportBuilder.php`, `CriticalPath.php`, `MetadataReportFallback.php` (créer) | construction du rapport |
| `src/Entity/WorkflowRunReport.php`, `src/Repository/WorkflowRunReportRepository.php` (créer) | stockage |
| `src/Workflow/Message/BuildRunReportMessage.php`, `MessageHandler/BuildRunReportHandler.php`, `src/Command/BuildRunReportCommand.php`, `src/Controller/WorkflowRunReportRebuildController.php` (créer) | déclenchement |
| `src/Ui/RunReportView.php` (créer), `templates/workflow/_run_report.html.twig` (créer), `run_show.html.twig`, `step_run_show.html.twig` | vue |
| `migrations/*`, `translations/*`, `README.md`, `CHANGELOG.md` | schéma, textes, doc |

---

### Task 1: Statut « terminé avec avertissements »

**Files:**
- Modify: `src/Entity/Enum/WorkflowStepRunStatus.php`, `src/Entity/Enum/WorkflowRunStatus.php` (+ `isRelaunchable()`), `src/Entity/WorkflowStepRun.php` (`markCompletedWithWarnings(...)` mêmes arguments que `markCompleted`), `src/Entity/WorkflowRun.php` (`markCompletedWithWarnings()`, `warningCount` + `incrementWarningCount(int)`), `src/Workflow/Runtime/WorkflowRunCompletionDecider.php`, `src/Workflow/Runtime/FluxxRuntime.php` (finalisation l.~680 : verrou libéré comme `completed`), les 13 fichiers qui traitent `Completed` comme terminal (`grep -rln "WorkflowRunStatus::Completed\|WorkflowStepRunStatus::Completed" src`), `src/Reporting/DailyWorkflowRecapBuilder.php` (section des runs avec avertissements), UI : libellé + classe CSS orange, `translations/*`, `migrations/schema-postgresql.sql`, create `migrations/upgrade-1.19.0-postgresql.sql` (`warning_count`).
- Test: `tests/Workflow/Runtime/WorkflowRunCompletionDeciderTest.php`, `tests/Workflow/Runtime/FluxxRuntimeTest.php`, `tests/Integration/*` (prune), `tests/Reporting/*`.

**Interfaces:**
- Produces: `WorkflowStepRunStatus::CompletedWithWarnings` / `WorkflowRunStatus::CompletedWithWarnings` (`'completed_with_warnings'`) ; `WorkflowStepRun::markCompletedWithWarnings(...)` ; `WorkflowRun::markCompletedWithWarnings(?DateTimeImmutable $finishedAt = null)`, `warningCount(): int`, `incrementWarningCount(int $by = 1)`.

- [ ] **Step 1: Failing tests** — `decider_treats_warning_step_as_done` (tous les steps `completed` sauf un `completed_with_warnings` → `WorkflowRunStatus::CompletedWithWarnings`) ; `downstream_runs_after_warning_step` (un step suivant d'un step `completed_with_warnings` est exécuté, run final `completed_with_warnings`, verrou libéré, `isRelaunchable()` vrai) ; `prune_keeps_warning_count` (après `markRunsAsPayloadsPruned`, `warning_count` inchangé) ; `recap_lists_runs_with_warnings`.
- [ ] **Step 2: Run** la suite (commande des Global Constraints) — FAIL.
- [ ] **Step 3: Implement.** Traiter `CompletedWithWarnings` partout où `Completed` est terminal / réussi (relaunchable, verrous, statistiques, filtres, timeline, active runs, relaunch, cancellation, prune).
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `Add the completed with warnings status`.

### Task 2: Déclaration des steps (`StepReport`) et colonne `report`

**Files:**
- Create: `src/Workflow/Report/StepReport.php`, `ObjectStats.php`, `Anomaly.php`, `src/Workflow/Report/Severity.php` (enum `Blocking = 'blocking'`, `Warning = 'warning'`, partagé avec les Checks)
- Modify: `src/Workflow/Result/WorkflowStepResult.php` (param `?StepReport $report = null`, getter), `src/Entity/WorkflowStepRun.php` (colonne `report` json nullable, `replaceReport(?array)`, `report(): ?array`), `src/Workflow/Runtime/FluxxRuntime.php` (l.~134 à côté de `replaceMetadata` ; `completeFromIdempotenceHit` l.~281 reprend le report), schéma + upgrade.
- Test: `tests/Workflow/Report/StepReportTest.php`, `tests/Workflow/Runtime/FluxxRuntimeTest.php`.

**Interfaces:**
- Produces: `StepReport::objects(string $label): self` puis `read(int)`, `created(int)`, `updated(int)`, `excluded(string $reason, int $count)`, `rejected(list<array{key: string, message: string}>)`, `anomaly(string $title, array<string, int|string> $lines, Severity $severity = Severity::Warning)` (fluides, immuables) ; `StepReport::toArray(): array` / `StepReport::fromArray(array): self` (format stocké, versionné par la clé `v: 1`).

- [ ] **Step 1: Failing tests** — `step_report_round_trips` ; `excluded_reasons_add_up` (deux `excluded('SIFUREP', 3)` → 6) ; `report_is_stored_on_step_run` ; `idempotence_hit_keeps_report`.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `Let steps declare object metrics and anomalies`.

### Task 3: Steps Check

**Files:**
- Create: `src/Workflow/Check/CheckStepInterface.php` (extends `ExecutableWorkflowStepInterface`), `CheckResult.php` (`name`, `Severity $severity`, `CheckOutcome $outcome` enum `Ok = 'ok'` / `Deviation = 'deviation'`, `?string $expected`, `?string $actual`, `list<string> $samples` tronqué à 20), `CheckReport.php` (liste de `CheckResult`, `toArray`/`fromArray`)
- Modify: `src/Entity/Enum/WorkflowStepType.php` (`Check = 'check'`), `src/Workflow/Result/WorkflowStepResult.php` (`?CheckReport $checkReport = null`), `src/Workflow/Runtime/FluxxRuntime.php` (après exécution d'un step dont le résultat porte un `CheckReport` : stocker dans `report` ; ≥ 1 écart bloquant → échec métier sans retry avec message listant les contrôles bloquants en écart ; sinon ≥ 1 écart avertissement → `markCompletedWithWarnings` + `incrementWarningCount` sur le run), libellé/couleur du type dans l'UI, `templates/workflow/step_run_show.html.twig` (tableau des contrôles), `translations/*`.
- Test: `tests/Workflow/Check/CheckReportTest.php`, `tests/Workflow/Runtime/FluxxRuntimeTest.php`.

**Interfaces:**
- Consumes: Task 1 statuts, Task 2 `Severity` et colonne `report`.
- Produces: `CheckStepInterface`, `CheckReport::add(CheckResult): self`, `CheckReport::blockingDeviations(): list<CheckResult>`, `warningDeviations(): list<CheckResult>`.

- [ ] **Step 1: Failing tests** — `blocking_deviation_fails_step_without_retry` ; `warning_deviation_completes_with_warnings` ; `all_ok_completes` ; `handler_exception_is_technical_and_retried` ; `samples_truncated_to_20`.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `Add check steps with blocking and warning severities`.

### Task 4: Construction et stockage du rapport

**Files:**
- Create: `src/Workflow/Report/WorkflowReportConfiguration.php` (`list<class-string<RunReportSectionInterface>> $sections = []`), `RunReportSectionInterface.php` (`build(WorkflowRun $run): ReportSection`), `ReportSection.php` (`title`, `list<array{label: string, detail: ?string, status: ?string}> $lines`, `unavailable(string $title, string $message): self`), `MetadataReportFallback.php`, `CriticalPath.php`, `RunReportBuilder.php`, `src/Entity/WorkflowRunReport.php`, `src/Repository/WorkflowRunReportRepository.php` (`findOneByRun`, `save` remplace), `src/Workflow/Message/BuildRunReportMessage.php`, `src/Workflow/MessageHandler/BuildRunReportHandler.php`, `src/Command/BuildRunReportCommand.php` (`fluxx:run:report <run-id>`)
- Modify: `src/Workflow/WorkflowDefinition.php` (param `?WorkflowReportConfiguration $report = null`), `src/Workflow/Runtime/FluxxRuntime.php` (dispatch quand le run devient `completed` / `completed_with_warnings` et que la définition a un `report`), routage Messenger du message sur le transport `fluxx` (config du bundle), schéma + upgrade (table `fluxx_workflow_run_report`).
- Test: `tests/Workflow/Report/RunReportBuilderTest.php`, `tests/Workflow/Report/CriticalPathTest.php`, `tests/Integration/RunReportDatabaseTest.php`.

**Interfaces:**
- Consumes: Tasks 1-3.
- Produces: `RunReportBuilder::build(WorkflowRun $run): array` (contenu `format_version` 1 : `summary`, `objects`, `steps` (code, type, statut, début, fin, durée, compteurs, mémoire, débit), `critical_path` (codes), `anomalies`, `checks`, `sections`) ; `CriticalPath::compute(WorkflowDefinition, array $stepRunsByCode): list<string>` (chaîne de dépendances dont le dernier step a la fin la plus tardive, en remontant vers le prédécesseur qui a fini le plus tard).

- [ ] **Step 1: Failing tests** — `no_report_without_configuration` ; `report_built_for_completed_and_warning_runs_only` ; `objects_merge_step_reports_and_metadata_fallback` (un step avec `StepReport`, un step avec seulement `created`/`excluded`/`rejected_records` en métadonnées → mêmes champs) ; `critical_path_follows_latest_finishing_dependency` ; `failing_section_is_marked_unavailable_and_report_saved` ; `rebuild_after_payload_prune` ; `rebuild_replaces_existing_report`.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `Build and store a report for completed runs`.

### Task 5: Vue du rapport

**Files:**
- Create: `src/Ui/RunReportView.php` (formatage pour Twig : durées via `DurationFormatter`, « — » pour absent, steps < 60 s regroupés), `templates/workflow/_run_report.html.twig`, `src/Controller/WorkflowRunReportRebuildController.php` (POST, CSRF, `ROLE_ADMIN` comme les relances)
- Modify: `templates/workflow/run_show.html.twig` (onglet « Rapport » quand un rapport existe ; bouton « Reconstruire le rapport »), `src/Ui/RunDetails.php` (charge le rapport), `translations/*`.
- Test: `tests/Ui/RunReportViewTest.php`, `tests/Http/*` (onglet visible / absent, rebuild protégé).

- [ ] **Step 1: Failing tests** — `view_formats_summary_objects_and_anomalies` ; `short_steps_are_grouped` ; `report_tab_hidden_without_report` ; `rebuild_requires_admin`.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement** selon la maquette (en-tête, 5 indicateurs, tableau des objets, frise via `RunTimeline` + chemin critique, 3 encarts, anomalies dépliables, contrôles, sections). **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `Show the run report in the back office`.

### Task 7: Finaliser un run qu'une branche en échec bloque

**Contexte (preuve réelle) :** runs 363 et 367 d'`efficy_sync` (base `sipperec` du connecteur, lecture seule) : `hubspot_contact_write` `failed` à 17:19:13, `hubspot_fonction_write` `completed` à 17:20:15 ; le run est resté `running` sans plus aucun step actif jusqu'à `fluxx:runtime:self-heal`. `WorkflowRunCompletionDecider::decide()` renvoie `null` tant qu'un step est `running`/`runnable`/`waiting` ; un step dont une dépendance a échoué est `blocked`.

**Files:** à déterminer par l'enquête — `src/Workflow/Runtime/WorkflowRunCompletionDecider.php`, `src/Workflow/Runtime/FluxxRuntime.php` (finalisation, `completionStepCodes`), tests `tests/Workflow/Runtime/*`.

- [ ] **Step 1: Reproduire.** Charger en lecture seule les step runs du run 367 (`docker compose exec -T bdd psql -U app -d sipperec` depuis `/home/sevj/Projects/connector-sipperec`) et la définition `efficy_sync` (`src/Service/Workflow/Efficy/EfficySyncWorkflow.php` du connecteur) ; identifier quel step le décideur considère `running`/`runnable`/`waiting` et pourquoi (step jamais dispatché ? `completionStepCodes` ? ordre des finalisations entre deux workers ?). Écrire le test qui reproduit la topologie et l'enchaînement (une branche échoue pendant qu'une autre tourne encore, puis l'autre termine) — FAIL.
- [ ] **Step 2: Corriger** pour que le run passe `partially_failed` (ou `failed`) dès qu'aucun step ne peut plus s'exécuter, quel que soit l'ordre d'arrivée des fins de steps entre workers. **Step 3:** suite complète verte. **Step 4: Commit** `Finalize runs blocked by a failed branch`.

### Task 8: Commande d'annulation

**Files:** Create `src/Command/CancelWorkflowRunCommand.php` (`fluxx:run:cancel <run-id> [--reason=] [--operator=]`, réutilise `WorkflowCancellationService::cancel($runId, 'cli', $reason, $operator)`, comme `WorkflowRunCancelController`), tests `tests/Command/*`, README (commandes).

- [ ] **Step 1: Failing tests** — `cancels_an_active_run` (statut `cancelled`, verrou libéré) ; `refuses_a_finished_run` (code retour échec, message clair). **Step 2: Implement.** **Step 3:** suite verte. **Step 4: Commit** `Add a command to cancel a run`.

### Task 6: Documentation et version

**Files:** `README.md` (`fluxx:run:cancel`, finalisation des runs bloqués, déclarer un rapport, `StepReport`, Checks, statut avec avertissements, `fluxx:run:report`, montée de version SQL), `CHANGELOG.md` (`[1.19.0]`), `migrations/upgrade-1.19.0-postgresql.sql` relu en entier.

- [ ] **Step 1:** Rédiger. **Step 2:** Suite complète verte. **Step 3: Commit** `Document run reports and check steps`.
