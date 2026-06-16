# Fluxx Backlog

This document tracks the next operational features to implement in Fluxx.

## 1. Run Operations

Goal: give operators full control over in-flight and failed executions.

### To do

- [x] Add run cancellation from the UI.
- [x] Add a dedicated `cancelled` workflow run state.
- [x] Prevent non-started downstream steps from executing after cancellation.
- [x] Release execution locks when a run is cancelled.
- [x] Record operator audit metadata for cancellation:
  - [x] operator user
  - [x] reason
  - [x] trigger source
  - [x] cancelled at
- [ ] Show cancellation state and audit details in workflow execution views.
- [x] Add retry actions from the UI:
  - [x] retry full run
  - [x] retry from step
  - [x] confirmation flow
  - [ ] operator reason
  - [x] visible success/error feedback

## 2. System Health

Goal: expose a dedicated operational health surface for the runtime.

### To do

- [x] Add a dedicated system health page.
- [x] Show runtime health summary:
  - [ ] Redis availability
  - [ ] Fluxx transport availability
  - [x] worker count
  - [x] backlog size
  - [x] in-flight count
  - [x] active lock count
- [x] Add health states and thresholds:
  - [x] healthy
  - [x] warning
  - [x] critical
- [x] Detect operational anomalies:
  - [x] stale workers
  - [x] old pending messages
  - [x] locks held too long
  - [ ] retry backlog growth
  - [x] runtime snapshot failure
- [x] Surface actionable diagnostics on the page.
- [ ] Add links from health findings to runtime/workflow screens when relevant.

## 3. Workflow Metrics And Statistics

Goal: provide finer-grained insight for each workflow.

### To do

- [x] Expand per-workflow statistics beyond volume and errors.
- [x] Add execution performance metrics:
  - [x] average duration
  - [x] p95 duration
  - [ ] slowest runs
  - [ ] slowest steps
- [x] Add reliability metrics:
  - [x] failure rate
  - [x] partial failure rate
  - [x] retry rate
  - [x] relaunch rate
- [x] Add throughput metrics:
  - [x] processed record volume
  - [x] success volume
  - [x] error volume
- [x] Add step-level statistics inside workflow detail:
  - [x] average duration by step
  - [x] failure count by step
  - [x] retry count by step
  - [x] idempotence hit count by step
- [ ] Add date-range filtering for advanced metrics views.
- [x] Add charts/tables that remain readable for operators.
- [ ] Highlight regressions and outliers in workflow performance.
