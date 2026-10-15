# Changelog

All notable changes to the **fluxx** workflow bundle are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). See the *Versioning* section
of `README.md` for the definition of the public API.

## [Unreleased]

## [1.10.1] - 2026-10-15

### Fixed
- Orphan-run self-healing now also covers synchronous (in-process) runs: they
  were previously excluded, yet production data showed they are the primary
  source of eternal runs (CLI/host process killed mid-run). Synchronous runs are
  finalized on step-activity staleness alone, as they have no worker claim to
  check. The live view flags stalled synchronous runs the same way.

## [1.10.0] - 2026-10-15

### Added
- Self-healing for orphaned runs. A run whose worker died (crash, OOM, container
  loss) used to stay `running`/`retrying`/`pending` forever:
  - `Fluxx\Operations\OrphanRunFinisher` finalizes runs with no live claim from a
    processing worker and no recorded activity within the stale-lock window as
    `failed`, marks their unfinished step runs failed with the same message, and
    releases their execution lock. Synchronous (in-process) runs are excluded.
  - `Fluxx\Runtime\WorkerState\OrphanRunWatchdog` listens to worker idle ticks
    (fluxx transport only) and invokes the finisher at most every 60 seconds,
    using the `stale_lock_timeout_seconds` setting as the staleness window.
    Healing is now automatic: any live worker closes orphaned runs within a
    minute, with no operator action.
- `fluxx:runtime:self-heal` gains `--orphan-threshold` (0 = setting value) and
  `--skip-orphans`, and now finishes orphaned runs as its final step.
- Runtime dashboard self-healing panel and `RuntimeSelfHealController` gain a
  "Close orphaned runs" action (admin).
- The "Active runs" live view flags suspected orphaned runs (no worker claim and
  no activity within the staleness window) with an explanatory badge.
- `RuntimeWorkerStateRepository::findProcessingRunIds` distinguishes workers
  claiming a run between two dispatched steps from mid-step processing where no
  heartbeat is emitted.

### Fixed
- `FluxxRuntime` no longer recomputes a run status when it reached a terminal
  state between the step dispatch and the completion check, preventing a
  watchdog-or operator-finalized run from being resurrected (`completed`,
  `running` or `retaining`) by a step that was still executing.

## [1.9.0] - 2026-10-15

### Added
- "Active runs" live view (`/fluxx/runs/active`, nav link "Active runs") focused on what the
  application is executing right now, as an operational complement to the runs catalog
  (history) and the runtime dashboard (queues and workers, admin-only):
  - one card per in-progress run (`pending`, `relaunched`, `retrying`, `running`, oldest first,
    capped at 50) showing source/target systems, trigger, status badge, elapsed time, and the
    workers currently processing it;
  - the steps currently executing per run (`running`/`relaunched` steps without a finish time,
    with elapsed time and step-type tone) and a progress bar with completed/failed steps over
    the workflow's total step count;
  - a "Latest activity" feed listing step runs recorded or updated within the last 15 minutes
    (queued, running, completed, failed, retrying) with links to the run detail;
  - summary counters (active runs, steps executing, busy workers) refreshed every second by a
    fragment polling endpoint (`/fluxx/runs/active/poll`), with the same pause/resume and
    offline/error indicators as the runs catalog.
- `WorkflowRunRepository::findActiveRuns` and
  `WorkflowStepRunRepository::findRecentActivity` repository queries backing the view.
- `Fluxx\Ui\ActiveRunBoard`, `ActiveRunsViewFactory` and their read-only view objects
  (`ActiveRunBoardPage`, `ActiveRunRowView`, `ActiveRunStepView`, `ActiveRunActivityView`).
- Unit tests for the progress/current-step/activity window rules.

## [1.7.2] - 2026-10-12

### Added
- `SynchronousFluxxEngineInterface::runWithResult` now accepts an optional
  `?OutputInterface $output` parameter. When supplied (the
  `fluxx:workflow:run --sync` command now passes its console output through),
  the synchronous engine prints one progress line per executed step during the
  breadth-first traversal:

      Step 1/27: Fetch company list (efficy_company_list_fetch)… 3644 processed, 1.20s
      Step 3/27: Write companies to HubSpot (hubspot_company_write)… 3644 processed, 3 errors, 45.00s
      Step 4/27: Barrier (efficy_base_entities_barrier)… done, 0.00s

  The "name (code)…" prefix is written before the step starts so long-running
  steps are visible immediately; the `+N processed, errorCount, duration`
  suffix is appended once the step returns. When a step throws, the line is
  completed with ` FAILED: <message>` and the exception propagates as before.
  The parameter is optional and defaults to `null`, so existing callers and
  mock consumers are unaffected.

## [1.7.1] - 2026-10-12

### Fixed
- Workflow graph layout now correctly handles DAGs with more than two parallel
  branches joining into a merge step. Previously, `rowCount` was computed from
  terminal lane paths only, causing nodes assigned to lanes beyond that count
  to overflow the grid and edges to be clipped by the SVG viewBox.
- `resolveLaneSpans`: widens root and merge steps to span all occupied lanes
  after `resolveFanInLaneCollisions` assigns single-lane nodes to higher lanes.
  Propagates multi-lane spans to only-child descendants.
- `centerColumnLanes`: vertically centers each column's single-lane nodes so
  every level's centroid aligns with the graph center, regardless of how many
  items share that level.
- `computeMaxLane`: extracted helper to determine the true row count after
  collision resolution.

## [1.7.0] - 2026-10-09

### Added
- `SynchronousFluxxEngineInterface` so callers can type-hint the synchronous engine
  without depending on the concrete `SynchronousFluxxEngine` class. Both classes
  keep implementing `FluxxEngineInterface`.
- `RunWorkflowCommand::--sync` option: when set, the command executes the workflow
  synchronously (`SynchronousFluxxEngine::runWithResult`) and prints the resulting
  records. Default behavior (async dispatch) is unchanged.
- `RunWorkflowCommand` test covering synchronous execution output.

## [1.6.1] - 2026-09-30

### Changed
- Some ui fixes

## [1.6.0] - 2026-09-30

### Added
- Swimlane timeline on the run detail page, plotted above the per-step table.
- `RunTimeline` service + `RunTimelineView`/`RunTimelineStepView` view models computing
  proportional step windows in PHP; `RunTimelineTest` covers the cases.
- `run_show.timeline_*` translations (en/fr).

### Changed
- `RunDetailView` carries a precomputed `timeline`; `RunDetails` builds it via `RunTimeline`.
- `run_show` stylesheet cache-bust bumped to `20260930-timeline`.

### Removed
- Orphan `SettingsController` + `templates/settings/`; `GET /fluxx/settings` permanently
  redirects to `/fluxx/configuration?tab=daily_recap`. Orphan `settings.title/heading/subtitle`
  translation keys dropped.
- Internal `docs/` working notes and screenshots.

## [1.5.1] - 2026-09-28

### Added
- Persist and surface the synchronous execution mode on `WorkflowRun`: a
  `synchronous` flag set by `SynchronousFluxxEngine`, and a badge shown in the
  runs list, executions tab, and run detail.

## [1.5.0] - 2026-09-28

### Added
- `SynchronousFluxxEngine`, a synchronous variant of `FluxxEngine` that executes the
  entire step graph in the calling process (no Messenger). Implements
  `FluxxEngineInterface` (`run()` returns the `runId`) and exposes `runWithResult()`
  which returns a `SynchronousWorkflowResult` carrying both the `runId` and the
  records produced by the terminal (leaf) step.
- `SynchronousWorkflowResult`, a value object holding `runId` + `records`.
- `FluxxRuntime::lastResult(): ?WorkflowStepResult` exposing the `WorkflowStepResult`
  of the last step executed on the happy path.
- Documentation of the synchronous variant in `docs/fluxx.md` (section 4.1).
- Unit tests `SynchronousFluxxEngineTest` covering single/multi-step execution,
  leaf record capture (list and associative dict), `runId` return, and failure
  handling (run marked `Failed`, lock released, exception rethrown).

### Changed
- `FluxxRuntime` is no longer `readonly` (added a mutable `lastResult` property).
  No public signature change; existing properties remain `private` with no setters.

## [1.4.0] - 2026-09-21

### Added
- `CHANGELOG.md` tracking notable changes for each release.
- Versioning & breaking-change policy documented in `README.md`, including the explicit definition of
  the public API (`WorkflowInterface`, `WorkflowStepDefinition`, the `Workflow\Step` interfaces, and the
  `WorkflowRunStatus` / `WorkflowStepType` enums).
- Internal `Fluxx\Reporting\WorkflowStepRunStatistics` service exposing the step-run statistics and
  reporting read models previously owned by `WorkflowStepRunRepository`.

### Changed
- Extracted step-run statistics and reporting read models out of `WorkflowStepRunRepository` into the
  dedicated `Fluxx\Reporting\WorkflowStepRunStatistics` service. `WorkflowStepRunRepository` now only
  exposes entity-lookup queries, and `GlobalStatistics`, `WorkflowDetails`, `DailyWorkflowRecapBuilder`
  and `FluxxRuntimeSnapshotProvider` depend on the new reporting service instead of the repository.

  Internal refactor: no public API change. Method signatures that fed the views (for example
  `aggregateLatestStepStatisticsSinceAll()`, `aggregateLatestStepStatisticsByWorkflowNameSince()`,
  `summarizeByWorkflowRuns()`, `findLatestByWorkflowRunsAndStepNamesIndexed()` and
  `findErroredStepRowsByWorkflowRunsGrouped()`) keep the same behaviour; they moved from the repository
  to `WorkflowStepRunStatistics`.

### Fixed
- Test suite no longer emits PHPUnit 13 deprecations or "no expectations configured" notices.
  Mocks that only stub collaborators now use `createStub()` (or `#[AllowMockObjectsWithoutExpectations]`
  on test classes that share mocked collaborators across assertions), while mocks that verify calls keep
  `createMock()` + `expects()`.

## [1.3.2] - 2026-09-21

### Added
- Step-run pruning commands and a `prune` capability for the runtime.
- French translations.

### Changed
- Runtime views styling/CSS polish.

## [1.3.1] - 2026-09-21

### Added
- Chunked processing of workflow steps to bound memory usage on large runs.

### Changed
- Runtime view updates.

## [1.2.2] - 2026-09-17

### Fixed
- Missing repository method that prevented the troubleshooting view from loading.

## [1.2.1] - 2026-09-14

### Added
- First unit tests covering repositories and runtime views (`tests/` namespace bootstrapped).</content>
</invoke>
