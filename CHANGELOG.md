# Changelog

All notable changes to the **fluxx** workflow bundle are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). See the *Versioning* section
of `README.md` for the definition of the public API.

## [Unreleased]

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
