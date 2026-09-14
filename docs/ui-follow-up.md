# UI Information Follow-up — `sevj/fluxx`

Tracking document for the interface information improvements identified after
the security/robustness hardening. Each section lists the gap, the data already
available, the files to touch, and an actionable checklist.

Status legend: `[ ]` todo · `[~]` in progress · `[x]` done

---

## 1. Expose the full `errorPayload` (HIGH)

### Gap
`WorkflowErrorPayloadFactory::fromThrowable()` produces 6 fields, but the UI only
surfaces 2 (`category`, `message`). Critical diagnostic data is stored in base
but never shown.

| Field | Stored | Exposed in UI |
|-------|--------|---------------|
| `category` (technical/business) | ✅ | ✅ run_show, step_run_show |
| `message` | ✅ | ✅ everywhere |
| `code` (business code, e.g. `CONTACT_INVALID`) | ✅ | ❌ never |
| `class` (PHP exception class, e.g. `RuntimeException`) | ✅ | ❌ never |
| `context` (record_id, endpoint, …) | ✅ | ❌ never |
| `occurred_at` (precise timestamp) | ✅ | ❌ never |

### Data already available
- `WorkflowRun::errorPayload()` → `metadata['error']` (array).
- `WorkflowStepRun::errorPayload()` → `metadata['error']` (array).
- `WorkflowErrorPayloadFactoryTest` confirms the payload shape.

### Files to touch
- `src/Ui/RunDetailView.php` — add `errorCode`, `errorClass`, `errorContext`, `errorOccurredAt`.
- `src/Ui/RunDetails.php` — read payload keys into the view model.
- `src/Ui/StepRunDetailView.php` — add the same four fields.
- `src/Ui/StepRunDetails.php` — read payload keys.
- `templates/workflow/run_show.html.twig` — render error block with class/code/context/occurred_at.
- `templates/workflow/step_run_show.html.twig` — same.
- `translations/fluxx.en.yaml` — keys `run_show.error_class`, `run_show.error_code`, `run_show.error_context`, `run_show.error_occurred_at`, `step_run_show.*` equivalents.

### Checklist
- [x] Extend `RunDetailView` + `StepRunDetailView` with the four error fields.
- [x] Wire payload keys from `RunDetails` / `StepRunDetails`.
- [x] Render error detail block in both templates (code + class badges, context as `code-block`, occurred_at next to category).
- [x] Add translation keys.
- [ ] Add/extend tests (`RunDetailsTest`, `StepRunDetailsTest`) asserting payload propagation.

---

## 2. Relaunch & cancellation traceability (HIGH)

### Gap
`relaunchMetadata()` stores `mode`, `original_run_id`, `trigger`, `reason`,
`operator_user`, `restart_step_code`, `target_step_codes`, `strategy`. UI exposes
only `mode` + `original_run_id` + `restart_step_code`. `cancellationMetadata()`
(`trigger`, `reason`, `operator_user`, `cancelled_at`) is exposed **nowhere**.

Missing in UI: **why** the run was relaunched/cancelled, **who** did it,
**when**. Audit-data blind spot for ops.

### Data already available
- `WorkflowRun::relaunchMetadata()` → `metadata['relaunch']`.
- `WorkflowRun::cancellationMetadata()` → `metadata['cancellation']`.
- Note: relaunch has no timestamp, but the relaunched run's `createdAt` doubles as relaunch time.

### Files to touch
- `src/Ui/RunDetailView.php` — add `relaunchReason`, `relaunchOperator`, `relaunchTrigger`, `cancelReason`, `cancelOperator`, `cancelTrigger`, `cancelledAt`.
- `src/Ui/RunDetails.php` — read relaunch + cancellation metadata.
- `templates/workflow/run_show.html.twig` — render a "Relaunch" group (when relaunch metadata present) and a "Cancellation" group (when cancellation metadata present) in the execution block.
- `templates/workflow/run_show.html.twig` — link `original_run_id` to its run_show page.
- `translations/fluxx.en.yaml` — `run_show.relaunch_reason`, `run_show.relaunch_operator`, `run_show.relaunch_trigger`, `run_show.cancel_reason`, `run_show.cancel_operator`, `run_show.cancel_trigger`, `run_show.cancelled_at`.

### Checklist
- [x] Extend `RunDetailView` (2 new groups of fields).
- [x] Wire `RunDetails` reading both metadata blobs.
- [x] Render relaunch + cancellation groups conditionally in `run_show`.
- [x] Deep-link the original run id.
- [x] Add translation keys.
- [ ] Add test asserting the traceability fields flow through.

---

## 3. Enrich the `/fluxx/runs` index page (MEDIUM)

### Gap
`RunRowView` carries `lockKey`, `lockScope`, `errorMessage`, `startedAt`, but
`_run_catalog_content.html.twig` displays none of them. The page is less
informative than the per-workflow executions tab, and offers no workflow filter.

### Data already available
- `RunRowView` already exposes the fields (no model change needed for display).
- `WorkflowRunFilterFactory` accepts array filters; a `workflow_code` filter hook may need a repo change unless a query-string filter is already honored.

### Improvements
- Surface `errorMessage` (truncated, with title tooltip) on failed/partially_failed rows.
- Surface `startedAt` alongside `createdAt` so pending (never-started) runs are distinguishable.
- Badge/indicator when `lockKey` is set and status is non-terminal (locked, possibly stale).
- Add a **workflow** filter (`<select>` of registered workflows) in addition to free-text search.
- Make `createdAt` column header a clickable sort toggle (asc/desc via query param).

### Files to touch
- `templates/runs/_run_catalog_content.html.twig` — add columns/indicators.
- `templates/runs/index.html.twig` — add workflow filter `<select>` in the filter form.
- `src/Operations/WorkflowRunFilterFactory.php` — accept `workflow_code` if not already.
- `src/Repository/WorkflowRunRepository.php` — apply workflow filter + optional sort.
- `src/Ui/RunCatalog.php` — pass registered workflows to the page for the filter dropdown.
- `translations/fluxx.en.yaml` — `run_index.workflow_filter`, `run_index.locked`, `run_index.started_at`, `run_index.error_preview`.

### Checklist
- [x] Add workflow filter select (populated from `SynchronizationRegistry` via `WorkflowChoiceView`).
- [x] Support `workflow_code` filtering (already in `WorkflowRunFilterFactory`).
- [ ] Support sorting by query params in `RunCatalog`/repo.
- [x] Add `startedAt` cell.
- [x] Add `errorMessage` truncated cell (failed runs only).
- [x] Add locked indicator (lock badge + icon when lockKey set and non-terminal).
- [x] Add translation keys.
- [x] `RunCatalogPollController` preserves filters via `app.request.query.all`.
- [ ] Test the poll endpoint preserves filters.

---

## 4. Expose payload metadata on `step_run_show` (MEDIUM)

### Gap
`WorkflowPayloadView` exposes `rawSize`, `storedSize`, `storageMode`,
`contentHash`, `createdAt`, `metadata` but the payload card only shows `id`,
`sequence`, `recordCount`, `format`, `compression`.

### Data already available
- `WorkflowPayloadView` already exposes all fields.

### Improvements
- Show `rawSize` → `storedSize` with the compression ratio (e.g. `4.2 MB → 0.8 MB`, ratio 81%).
- Show `storageMode` chip (`database` vs `redis`).
- Show `contentHash` truncated with copy affordance (cross-run integrity comparison).
- Show `createdAt`.
- Show `metadata` as a small `code-block` (source, exported_at, …).

### Files to touch
- `templates/workflow/step_run_show.html.twig` — extend the `.payload-meta` chips + add `.payload-details`.
- `src/Resources/public/styles/workflow-index.css` — styles for `payload-details`, chips for new infos.
- `translations/fluxx.en.yaml` — `step_run_show.payload_raw_size`, `step_run_show.payload_stored_size`, `step_run_show.payload_storage_mode`, `step_run_show.payload_content_hash`, `step_run_show.payload_created_at`, `step_run_show.payload_metadata`.

### Checklist
- [x] Extend payload card template with the five fields.
- [x] Compute and show compression ratio.
- [x] Style new chips/details.
- [x] Add translation keys.
- [x] Removed `.snapshot()` from `WorkflowPayloadView` (no longer decoded on list render — gzip decompression skipped).
- [ ] Add/extend `WorkflowPayloadView` test if a renderer-level assertion is feasible.

---

## Cross-cutting

- Keep all new text in `fluxx.en.yaml`, stable keys grouped by screen (per `AGENTS.md`).
- No business logic in templates: shape data in the PHP view models (`RunDetails`, `StepRunDetails`, `RunCatalog`).
- Add tests for each view-model wiring change (unit tests, no DB).
- Bump asset/stylesheet version strings when templates/CSS change to avoid stale browser caches (see the `v:` query params on `<script>`/`<link>` tags).

---

## Suggested order of execution

1. **Track 1** (errorPayload) — unlocks the most diagnostic value; pure propagation.
2. **Track 2** (relaunch/cancel traceability) — pairs naturally with track 1 in the same templates.
3. **Track 4** (payload metadata) — localized to `step_run_show` only.
4. **Track 3** (runs/index enrichment) — touches the polling partial + filter plumbing; do last since it interacts with the new `RunCatalogPollController`.
