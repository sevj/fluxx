# Fluxx Backlog

This document tracks the next package-level work items for Fluxx.

## 1. Workflow Relaunch

Goal: make workflow runs restartable in a controlled and observable way.

### Progress

Current baseline implemented in package:

- relaunch service available for full-run relaunches
- relaunch service available from a specific step
- branch-scoped relaunch mode available through the operational relaunch service and CLI
- relaunch strategy is persisted explicitly in run metadata
- relaunch runs and reset step runs now use dedicated `relaunched` states before execution resumes
- relaunch metadata records original run, trigger source, reason, restart step, mode, and operator user
- relaunch actions exposed in the workflow UI for full-run and step-level relaunch
- operational CLI relaunch command added

### To do

- [x] Add a relaunch service for a full workflow run.
- [x] Add a relaunch service starting from a specific step.
- [x] Add a relaunch mode limited to one branch when the workflow graph branches.
- [x] Define the relaunch strategy explicitly:
  - [x] reuse existing payloads
  - [x] regenerate downstream payloads
  - [x] reset downstream step runs
  - [x] preserve execution history
- [x] Add status transitions for relaunched runs and relaunched step runs.
- [x] Record relaunch metadata:
  - [x] original run id
  - [x] relaunch trigger
  - [x] relaunch reason
  - [x] restart step code
  - [x] operator user if triggered from UI
- [x] Expose relaunch actions in the UI.
- [x] Add CLI commands for operational relaunch.

## 3. Errors And Retries

Goal: make failures explicit and operationally manageable.

### Progress

Current baseline implemented in package:

- workflow errors can now be classified as `technical` or `business`
- a package-level `BusinessWorkflowException` is available for host workflows
- normalized error payloads are persisted in run and step metadata
- error category is surfaced in the workflow execution list and step-run detail view
- workflow-level retry policy configuration is available
- step-level retry policy override is available
- automatic retries are scheduled for technical failures with delayed Messenger dispatch
- max retries, delay, and backoff strategy are supported
- retry counters and retry timestamps are persisted on step runs
- `retrying` status is surfaced in workflow and step-run UI

### Remaining work

- [x] Distinguish technical failures from business failures.
- [x] Add retry policy support at workflow level.
- [x] Allow step-level retry policy overrides.
- [x] Support max retries, delay strategy, and backoff strategy.
- [x] Persist retry counters per step run.
- [x] Persist last retry timestamp and next retry timestamp.
- [x] Make failed vs retrying vs permanently failed states visible in the UI.
- [x] Add error classification and normalized error payloads.
- Expose retry actions from the UI and CLI.
- Add runtime visibility for retry queues and retrying messages.

## 4. Concurrency And Idempotence

Goal: avoid conflicting runs and make replay safe.

### Progress

Current baseline implemented in package:

- execution locks persisted and attached to workflow runs
- lock acquisition/release service wired into workflow launch
- stale lock recovery based on worker heartbeat timeout
- optional lock configuration on workflow definitions
- optional idempotence configuration on step definitions
- idempotence keys and deduplication outcomes persisted on step runs
- replay path reuses downstream payloads for deduplicated step executions
- lock state and deduplication state surfaced in workflow, step-run, and runtime UI
- package unit tests added for lock configuration, lock entity lifecycle, step-run deduplication, and UI mapping

### Recommended direction

Use a two-layer model:

1. A workflow-level execution lock to prevent incompatible concurrent runs.
2. Step-level idempotence keys to make write and linker steps replay-safe.

This is the most pragmatic baseline for Fluxx.

### Suggested implementation

- Add a lock service around workflow launch and relaunch.
- Define a lock scope on workflow runs:
  - workflow code only
  - workflow code + source system
  - workflow code + source/target pair
  - workflow code + business partition key
- Persist the chosen lock key on the workflow run.
- Refuse or queue conflicting launches while a lock is active.
- Release the lock when the run reaches a terminal state.
- Add stale lock recovery based on heartbeat and timeout.

### Idempotence strategy

- Introduce an idempotence key contract for steps that touch external systems.
- Start with `write` and `linker` steps.
- Build the key from stable business identifiers, not from transient run ids.
- Persist idempotence keys and target-side result references.
- On replay:
  - skip already applied operations when possible
  - update existing target references when appropriate
  - keep a visible audit trail of deduplicated executions

### Remaining work

- [x] Add a `WorkflowExecutionLock` persistence model.
- [x] Add a lock acquisition/release service.
- [x] Add lock timeout and stale worker detection.
- [x] Add optional lock strategy on workflow definitions.
- [x] Add optional idempotence strategy on step definitions.
- [x] Persist step idempotence keys and deduplication outcomes.
- [x] Surface lock conflicts and dedup hits in the runtime and run detail UI.
- [ ] Add tests for concurrent dispatch, replay, and worker crash recovery.


## 7. Operations UI

Goal: provide an actual back-office surface for operators.

### To do

- [ ] Add a workflow run detail page.
- [ ] Add a global run listing page with filters.
- [ ] Add a dedicated error view.
- [x] Add filtering by:
  - [x] workflow
  - [x] status
  - [x] date range
  - [x] source system
  - [x] target system
  - [x] error presence
- [x] Add pagination and search where needed.
- [x] Add operator confirmation flows for destructive actions.
- [x] Add visible audit information for manual operations.

## 10. Package Quality And Tooling

Goal: make the package robust and maintainable.

### To do

- Add unit tests for workflow runtime services.
- Add integration tests for Doctrine persistence.
- Add integration tests for Messenger async execution.
- Add UI/controller integration tests for package routes.
- Add tests for relaunch, retry, lock, and timeout behavior.
- Add fixture workflows for package-level test coverage.
- Document extension points:
  - custom step types
  - custom workflow definitions
  - runtime integration hooks
- Add operational console commands:
  - list runs
  - retry run
  - retry step
  - cancel run
  - inspect runtime
- Add a package usage guide in the README.
- Add a production-oriented operations section:
  - workers
  - Redis
  - retention
  - retries
  - locks

## Suggested Delivery Order

1. Concurrency and idempotence baseline
2. Errors and retries
3. Workflow relaunch
4. Richer runtime model
5. Operations UI
6. Package quality and tooling
