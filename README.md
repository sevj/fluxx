# Fluxx

Fluxx is a Symfony bundle for orchestrating operational synchronization workflows between systems.

## What It Provides

- workflow definition registry discovered from the host application
- asynchronous step execution through Symfony Messenger
- runtime monitoring for workers, locks, backlog, and in-flight messages
- an "active runs" live view of what is executing right now (current step per run, progress, latest activity)
- run relaunch flows from the full run or a specific step
- retry metadata, error classification, and lock/idempotence visibility
- an operator UI for workflows, runtime, users, and step-run details
- operational CLI commands for running, relaunching, listing, retrying, and inspecting runtime

## Installation

1. Require the bundle in your Symfony app.
2. Enable the bundle in `config/bundles.php`.
3. Import Fluxx routes from `config/routes.yaml`.
4. Ensure Doctrine scans the bundle entities and Twig sees the bundle templates.
5. Create the database schema (see [Database schema](#database-schema)).
6. Protect the `/fluxx` area with a security firewall (see [Security](#security)).

The bundle extension already prepends Doctrine mapping, Twig paths, and translations when the corresponding Symfony components are enabled.

## Database schema

Fluxx persists nine tables under the `fluxx_` prefix: `fluxx_user`, `fluxx_setting`,
`fluxx_workflow_run`, `fluxx_workflow_step_run`, `fluxx_workflow_run_report`, `fluxx_workflow_payload`,
`fluxx_workflow_payload_content`, `fluxx_workflow_execution_lock`, and
`fluxx_runtime_worker_state`.

The bundle does not ship Doctrine migrations to avoid forcing `doctrine/migrations`
as a dependency. Ship your own migrations, or bootstrap from the reference schema:

- PostgreSQL (primary, validated): apply `migrations/schema-postgresql.sql`.
- MySQL or SQLite: run `bin/console doctrine:schema:create --dump-sql` and apply
  the fluxx tables. The mapping supports all three platforms through DBAL.
- As a one-shot dev bootstrap: `bin/console doctrine:schema:update --force` or
  `bin/console doctrine:schema:create` (the latter drops the whole schema).

The Doctrine mapping is auto-registered by the bundle extension, so the host
application does not need to declare the `Fluxx` ORM mapping manually.

### Upgrading to 1.16

Add the `active_lock_key` column before deploying, or every query on execution locks fails:

- PostgreSQL: apply `migrations/upgrade-1.16.0-postgresql.sql`.
- MySQL or SQLite: add the column and its unique index from
  `bin/console doctrine:schema:update --dump-sql`, release all but the most recent active lock of
  each `lock_key`, then run
  `UPDATE fluxx_workflow_execution_lock SET active_lock_key = lock_key WHERE released_at IS NULL`.

### Upgrading to 1.19

Stop the workers, then add the run report table and two columns before deploying the code (the
`ALTER TABLE` briefly locks the run tables, and 1.19 maps the new columns):

- PostgreSQL: apply `migrations/upgrade-1.19.0-postgresql.sql` (safe to replay).
- MySQL or SQLite: apply the `fluxx_workflow_run_report` table, `fluxx_workflow_run.warning_count` and
  `fluxx_workflow_step_run.report` from `bin/console doctrine:schema:update --dump-sql`.

## Security

When `fluxx.security.enabled` is `true` (default) the bundle prepends a password
hasher, an entity user provider backed by `Fluxx\Entity\User`, and a
`ROLE_ADMIN -> ROLE_FLUXX_USER` hierarchy. It does **not** register a firewall or
`access_control`: those belong to the host application.

To make the login flow and `ROLE_ADMIN` gates work, declare a firewall for the
`/fluxx` area in `config/packages/security.yaml`:

```yaml
security:
    firewalls:
        fluxx:
            pattern: ^/fluxx
            provider: fluxx_users
            form_login:
                login_path: fluxx_login
                check_path: fluxx_login
                default_target_path: fluxx_workflow_index
            logout:
                path: fluxx_logout

    access_control:
        - { path: ^/fluxx/login, roles: PUBLIC_ACCESS }
        - { path: ^/fluxx, roles: ROLE_FLUXX_USER }
```

Set `fluxx.security.enabled` to `false` and register your own provider/firewall
when the host application already manages authentication. In that case the
`ROLE_ADMIN` guards on relaunch, cancel and user management actions rely on
whatever roles your provider assigns.

## Error classification

When a step handler throws, Fluxx classifies the failure as **technical** (retryable)
or **business** (terminal for that run, never retried). The canonical path is to tag a
thrown exception with `Fluxx\Workflow\Error\WorkflowErrorInterface`; that explicit
category always wins.

Because many step handlers throw generic exceptions (e.g. `InvalidArgumentException`
for an invalid record), untagged exceptions used to default to **technical** and were
retried until the policy was exhausted. To prevent business failures from being
needlessly retried, Fluxx now also classifies a throwable as **business** when it is an
instance of one of the configured business exception classes, even without
`WorkflowErrorInterface`.

Defaults are opt-out:

```yaml
fluxx:
    error_classification:
        enabled: true
        business_exception_classes:
            - 'InvalidArgumentException'
            - 'LogicException'
            - 'DomainException'
            - 'OutOfBoundsException'
```

- `enabled: false` restores the legacy behavior (every untagged throwable is technical).
- Subclasses of a configured class inherit the business classification.
- A configured class that does not exist is ignored (no autoload triggered).
- `WorkflowErrorInterface` always overrides the class-based classification.

Prefer tagging handlers' exceptions with `WorkflowErrorInterface` for explicit,
self-documenting categories; the auto-classification is a safety net for code that
does not.

## Configuration reference

All keys live under the `fluxx` namespace in `config/packages/fluxx.yaml`:

```yaml
fluxx:
    security:
        # When true, Fluxx prepends its own user provider, password hasher and
        # role hierarchy into the security configuration.
        enabled: true

    runtime:
        # Messenger transport targeted by worker heartbeats, runtime introspection
        # and self-heal. Must match a transport under framework.messenger.transports.
        transport_name: fluxx

        # Optional transport for the steps of runs that have already started.
        # Workers read it before transport_name, so started runs finish before new
        # ones begin. Its DSN comes from MESSENGER_TRANSPORT_FLUXX_CONTINUE_DSN.
        # Null (default) keeps a single transport. See "Finishing started runs first".
        continuation_transport_name: ~

        defaults:
            stale_lock_timeout_seconds: 1800    # lock older than this is recoverable
            worker_heartbeat_timeout_seconds: 120  # worker idle/offline threshold
            health_warning_threshold_seconds: 60
            health_critical_threshold_seconds: 300
            max_global_retries: 10              # per-step absolute retry cap (0 disables)

    error_classification:
        # When true, untagged throwables are classified as business when they extend
        # one of the configured business exception classes.
        enabled: true
        business_exception_classes:
            - 'InvalidArgumentException'
            - 'LogicException'
            - 'DomainException'
            - 'OutOfBoundsException'

    observer:
        # Fluxx Observer instance receiving this application's health and run
        # reports. Pushing is disabled while either value is empty.
        url: '%env(FLUXX_OBSERVER_URL)%'
        token: '%env(FLUXX_OBSERVER_TOKEN)%'
        timeout_seconds: 10
```

## Finishing started runs first

With one transport, the queue is processed breadth-first: a step that completes appends its next
step at the end of the queue. Under a burst of new runs (hundreds of webhooks at once), every run
does its first step before any run does its second, so the first run only finishes once the whole
backlog is drained, and until then nothing reaches the target system.

The continuation transport makes the processing depth-first. Messages that start a run (a new run,
a relaunch) keep following the host's routing; every message of a run that has already started (its
next steps, its retries and deferrals, its report) is sent to the continuation transport with a
`TransportNamesStamp`. Workers consume it first, and Messenger's worker goes back to its first
transport after each message, so a started run always goes before a new one. The total throughput
does not change; the number of half-done runs stays bounded by the number of workers. Workflows do
not change.

To enable it in a host:

1. Declare the transport next to `fluxx`, on the same Redis, in its own stream, with the same
   consumer name per worker (a DSN path names the stream and wins over transport options, hence a
   separate DSN):

   ```yaml
   # config/packages/messenger.yaml
   framework:
       messenger:
           transports:
               fluxx_continue:
                   dsn: '%env(MESSENGER_TRANSPORT_FLUXX_CONTINUE_DSN)%'
                   retry_strategy: { max_retries: 3, multiplier: 2 }   # as for fluxx
   ```

   ```dotenv
   MESSENGER_TRANSPORT_FLUXX_CONTINUE_DSN="redis://:${REDIS_PASSWORD}@redis:6379/fluxx_continue/fluxx/worker"
   ```

2. Enable it: `fluxx.runtime.continuation_transport_name: fluxx_continue`.

3. Consume both, continuation first, each worker with its own consumer name on both streams:

   ```sh
   export MESSENGER_TRANSPORT_FLUXX_DSN="${FLUXX_DSN_DEFAULT}/${CONSUMER_NAME}"
   export MESSENGER_TRANSPORT_FLUXX_CONTINUE_DSN="${FLUXX_CONTINUE_DSN_DEFAULT}/${CONSUMER_NAME}"
   exec php bin/console messenger:consume fluxx_continue fluxx
   ```

Deploy the option and the new worker command together: continuations sent to a transport no worker
reads would wait there. The system health reports a queue with messages and no consumer, and
`fluxx:runtime:inspect` lists each queue with its backlog. The runtime tools (`inspect`, the runtime
dashboard, `purge-dead-consumers`, `reclaim-pending`, `self-heal`, `reset`) cover both streams, and a
worker is recorded once, under `transport_name`, whichever queue its message came from.

## Fluxx Observer

Fluxx Observer gathers the health and the runs of every
application running Fluxx on one read-only dashboard. Register the application there (it issues an
ingest token), set `fluxx.observer.url` and `fluxx.observer.token`, then schedule the push, for
instance every 5 minutes:

```cron
*/5 * * * * php /path/to/app/bin/console fluxx:observer:push
```

Each push sends:

- the system health page: overall state, facts, metrics, checks and findings. Workers and locks
  stay on the host;
- a report of every run created, started or finished since the previous push, and of every run still
  active: workflow, systems, trigger, status, batch id, dates, duration, the processed, success and
  error totals of its steps, its retries and warnings, the run it relaunches, and for a failed run
  the category and code of its error.

No record, payload, error message or error context leaves the application. The push resumes from
its last success, so runs are not lost while the observer is unreachable, and runs whose payloads were
pruned are not sent again. `--since="-2 days"` sends the runs changed in a given period again.

## Defining A Workflow

Register workflows by implementing `Fluxx\Workflow\WorkflowInterface`. Services implementing that interface are auto-tagged as `fluxx.workflow`.

Minimal structure:

```php
use Fluxx\Entity\Enum\WorkflowStepType;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowInterface;
use Fluxx\Workflow\WorkflowStepDefinition;

final readonly class ContactsWorkflow implements WorkflowInterface
{
    public function __construct(
        private ContactsReadStep $read,
        private ContactsWriteStep $write,
    ) {
    }

    public function definition(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            code: 'contacts',
            name: 'Contacts',
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            steps: [
                new WorkflowStepDefinition('read', 'Read contacts', WorkflowStepType::Read, $this->read),
                new WorkflowStepDefinition('write', 'Write contacts', WorkflowStepType::Write, $this->write, ['read']),
            ],
        );
    }
}
```

Each step handler implements `ExecutableWorkflowStepInterface`. Fluxx passes:

- `WorkflowContext` for workflow-level metadata and run identity
- `WorkflowStepInput` for upstream payloads
- `WorkflowStepResult` for produced records, metadata, counters, and branch-specific outputs

### Workflow conventions

Whatever happens in between, a workflow graph should have one way in and one way out:

- **Start with a single step.** Every other step depends on it, directly or not, so nothing runs before it.
- **End with a single step.** With parallel branches and asynchronous workers, a run with several terminal steps can stay `running` after all its work is done. Running with `--sync` does not reveal this, so test such graphs asynchronously too.
- **Use join steps where branches converge.** A join is a virtual step that does no work: what follows it starts once all the joined branches have completed. It loads no input and produces no records, so it stays cheap whatever the volumes.

Each step can run more than once, and it is not atomic:

- **Make it safe to replay.** A retry or a relaunch executes the whole handler again, including the calls that succeeded before the failure.
- **Its database writes are atomic by default.** The handler runs in the transaction that saves the outcome of the step, so what it wrote is rolled back when it fails. External calls are not undone.
- **Opt out for long steps.** A transaction stays open as long as the handler runs. Pass `transactional: false` to `WorkflowStepDefinition` when a handler spends minutes on external APIs: what it writes then stays when it fails, so open your own transaction where several writes must succeed together.

```php
steps: [
    new WorkflowStepDefinition('read', 'Read source', WorkflowStepType::Read, $this->read),
    new WorkflowStepDefinition('write_contacts', 'Write contacts', WorkflowStepType::Write, $this->writeContacts, ['read']),
    new WorkflowStepDefinition('write_companies', 'Write companies', WorkflowStepType::Write, $this->writeCompanies, ['read']),
    WorkflowStepDefinition::join('writes_done', 'All writes done', ['write_contacts', 'write_companies']),
],
```

### Reading the output of an ancestor

A step can read the output of any of its ancestors, not only of its direct dependencies. Declare it in `reads`, so that graphs can stay simple with join steps while each data dependency remains explicit:

```php
new WorkflowStepDefinition(
    code: 'link',
    name: 'Link contacts to companies',
    type: WorkflowStepType::Linker,
    handler: $this->link,
    dependsOn: ['writes_done'],
    reads: ['write_contacts', 'write_companies'],
),
```

```php
$contacts = $input->recordsFromAncestor('write_contacts');
```

- A definition whose `reads` names a step that is not an ancestor is refused when it is built, and reading an undeclared step throws.
- Records are loaded on each call and only when asked for. Keep the result instead of calling twice.
- With branch outputs, the step gets the copy sent along its own lineage.
- Relaunching a step keeps the outputs it reads from preserved ancestors.
- Records read this way are not part of `WorkflowStepInput::payloads()`. An idempotence key computed from the direct input does not reflect them.
- The workflow page lists a step's `reads` in the tooltip of its card.

## Run reports

A workflow that declares a report gets one for every run that ends `completed` or
`completed_with_warnings`, shown in a *Report* tab of the run page: objects read, created, updated,
excluded or rejected, step durations and throughput, the critical path, anomalies, checks and
sections of your own. Runs that fail get no report.

```php
new WorkflowDefinition(
    code: 'contacts',
    // ...
    report: new WorkflowReportConfiguration(sections: [ContactsReportSection::class]),
);
```

- A step describes what it did through `WorkflowStepResult::$report`:
  `StepReport::objects('Contacts')->created(20)->updated(29095)->excluded('Ended before 2018', 8)->rejected($rejected)->anomaly('Unresolved owners', ['K_USER 2' => 2781])`.
  Associations are declared with `StepReport::links('Contacts ↔ Companies')->created(120)->removed(3)`:
  they get their own *Links* block and indicator, apart from the objects.
  One report can describe several entries: `StepReport::links('Contacts → Companies')->created(120)->link('Functions → Contacts')->created(80)`;
  counters apply to the entry declared last (`object()` starts another object entry).
  Steps without one are read from the metadata keys `created`, `updated`, `excluded`
  (reason => count) and `rejected_records` (`{key|efficy_key, message}`).
- A section is a service implementing `RunReportSectionInterface` (autoconfigured) that returns a
  `ReportSection`. A section that throws is shown as unavailable; the report is still saved.
- The report is built by a `BuildRunReportMessage` sent once the run is committed. Route it to the
  same transport as `RunWorkflowStepMessage`; an unrouted message is built synchronously by the worker
  that completed the run. A failure to send or build it never changes the run, and
  `fluxx:run:report <run-id>` (or the *Rebuild the report* button) builds it again, also after
  `fluxx:prune:payloads`.

### Check steps

A step of type `WorkflowStepType::Check` implements `CheckStepInterface` and returns a `CheckReport`
of `CheckResult`s: a name, an outcome, the expected and actual values, up to 20 sample keys and,
optionally, a `subject` (the object or area checked, used to group results) and a `hint` saying in one
sentence what a deviation means. Checks must only read.

- A check never fails a run: it runs once the work is done, so failing would protect nothing and hide
  the report. Deviations complete the step and the run as `completed_with_warnings`; downstream steps
  still run, the daily recap lists the deviations and the run keeps a `warning_count`. A condition that
  must stop a run belongs in a regular step that fails.
- A check step that returns no check fails. Check steps cannot be idempotent.
- Report strings are cleaned of invalid UTF-8 before they are stored.

## Versioning

Fluxx follows [Semantic Versioning 2.0.0](https://semver.org/). Given a version
`MAJOR.MINOR.PATCH`:

- **MAJOR** increases for incompatible changes to the public API.
- **MINOR** increases for backward-compatible additions to the public API.
- **PATCH** increases for backward-compatible fixes and internal changes.

Notable changes for each release are recorded in [`CHANGELOG.md`](CHANGELOG.md). Each
version line corresponds to a published git tag.

### Public API

The public API is the contract host applications integrate against. It consists of:

- `Fluxx\Workflow\WorkflowInterface` — implemented by host services to declare a workflow.
- `Fluxx\Workflow\WorkflowStepDefinition` — value object describing a step in a workflow graph.
- The step handler interfaces in the `Fluxx\Workflow\Step` namespace:
  - `WorkflowStepInterface` (base contract)
  - `ExecutableWorkflowStepInterface`
  - `ReadStepInterface`, `WriteStepInterface`, `SplitterStepInterface`,
    `TransformStepInterface`, `LinkerStepInterface`, `IdempotentWorkflowStepInterface`
- The enums `Fluxx\Entity\Enum\WorkflowRunStatus` and `Fluxx\Entity\Enum\WorkflowStepType`.
- Run reports: `Fluxx\Workflow\Report\WorkflowReportConfiguration`, `StepReport` (objects and links), `Severity`,
  `RunReportSectionInterface` and `ReportSection`; checks: `Fluxx\Workflow\Check\CheckStepInterface`,
  `CheckReport`, `CheckResult` and `CheckOutcome`.

Anything not listed above — Doctrine repositories (for example
`WorkflowStepRunRepository`), internal services (for example `FluxxRuntime`,
`FluxxRuntimeSnapshotProvider`, `WorkflowStepRunStatistics`), the operator UI
(`Ui\*`, `Reporting\*`), entity internals beyond the public enums, command output
formats, and Twig templates — is internal. Internal code may change in any release
without a major bump.

### Breaking-change policy

- Adding a new method to a public interface that a host application must implement is
  **breaking** and requires a MAJOR bump, unless it ships with a backward-compatible
  default implementation (then it is a MINOR addition).
- Adding new cases to the public enums, adding new public interfaces, new CLI commands,
  and new optional `fluxx.*` configuration keys are backward-compatible (MINOR).
- Removing or renaming existing public interfaces, methods, enum cases, or existing
  `fluxx.*` configuration keys is breaking (MAJOR).
- Bug fixes, performance work, and refactors that preserve the public API — including
  moving implementation between repositories and reporting services — are PATCH.

## Extension Points

### Custom step types

Implement `Fluxx\StepType\StepTypeProviderInterface` and return one or more `StepTypeDefinition` instances. The provider is auto-tagged as `fluxx.step_type_provider`.

Use this when you need:

- a domain-specific step label in the UI
- a dedicated tone/style for a custom step family
- host-app specific step categories beyond the built-in read/splitter/transform/write/linker/join set

### Custom workflow definitions

Your host app owns workflow registration. Fluxx does not require a database model for workflow definitions; it reads them from the service container through `SynchronizationRegistry`.

Use this to:

- version workflow graphs in code review
- keep host-specific integration logic out of the bundle
- compose step handlers from regular Symfony services

### Live step progress

Inject `Fluxx\Workflow\Progress\StepProgressReporterInterface` in a step handler or in a
service it calls, then call `report($done, $total, $label)` as work advances. The active runs
page shows the latest value for each running step, whichever worker executes it. Writes are
throttled, so calling it for every batch is fine, and calls made outside of a running step
are ignored. No host configuration is needed beyond the Redis fluxx transport.

### Runtime integration hooks

The main runtime extension points are:

- Messenger transport configuration for the `RunWorkflowStepMessage`
- worker heartbeat recording through `RuntimeWorkerStateRecorder`
- lock strategy via `WorkflowExecutionLockConfiguration`
- step idempotence via `WorkflowStepIdempotence`
- retry policy via `WorkflowRetryPolicy`

## CLI Commands

Main operational commands:

```text
fluxx:user:create
fluxx:workflow:run
fluxx:workflow:relaunch
fluxx:run:list
fluxx:run:retry
fluxx:run:resume
fluxx:run:cancel
fluxx:run:report
fluxx:step:retry
fluxx:runtime:inspect
fluxx:observer:push
```

Examples:

```bash
php bin/console fluxx:workflow:run contacts --trigger=manual --batch-id=nightly-20260616
php bin/console fluxx:workflow:run contacts --parameter offset=100 --parameter limit=25 --parameter filters='{"status":"active"}'
php bin/console fluxx:run:list --workflow=contacts --status=failed --errors=with --page=1 --limit=20
php bin/console fluxx:run:retry 7af0d8c3 --reason="Retry after API incident"
php bin/console fluxx:step:retry 7af0d8c3 write_contacts --reason="Replay write step only"
php bin/console fluxx:run:resume 7af0d8c3 --reason="Replay the failed branches only"
php bin/console fluxx:workflow:relaunch 7af0d8c3 --force --reason="Worker stuck, manual recovery"
php bin/console fluxx:run:cancel 7af0d8c3 --reason="Stopped by the operator"
php bin/console fluxx:run:report 7af0d8c3
php bin/console fluxx:runtime:inspect
```

`fluxx:step:retry` keeps the ancestors of the restart step and replays its descendants: a step that also
waits for or reads another branch cannot run in that relaunch. `fluxx:run:resume` restarts every step
that did not complete, from all the failed branches at once, and keeps every completed step whatever its
branch, so joins and steps reading other branches find their inputs.

Relaunch and retry commands refuse to operate on a run that is still in progress
(`pending`, `running`, `retrying`, `relaunched`). Pass `--force` to override the
guard when the original worker is definitively stuck and cannot recover.

## Package Usage Guide

Recommended host-application flow:

1. Define workflow graphs in code with explicit step dependencies.
2. Route `RunWorkflowStepMessage` to an async transport dedicated to Fluxx.
3. Run one or more workers for the Fluxx transport.
4. Protect `/fluxx` behind your app authentication.
5. Use the UI for visibility and the CLI for batch or incident operations.

## Operations Notes

### Workers

- run dedicated Messenger workers for the Fluxx transport
- keep worker names stable enough to correlate with runtime state
- monitor heartbeat freshness to detect stale executions

### Redis

- the runtime dashboard expects a Redis-based Fluxx transport for queue introspection
- `fluxx.runtime.transport_name` names the Messenger transport Fluxx targets for
  worker heartbeats, runtime introspection and self-heal; it must match the
  transport configured under `framework.messenger.transports` (default `fluxx`)
- stream and consumer group names should stay stable across deploys
- size Redis retention according to replay and audit needs

### Rate limiters

- the runtime dashboard lists every Symfony rate limiter (`framework.rate_limiter`) and how much of
  it is used, reading its keyless bucket
- consume a limiter shared by the whole application without a key, `$factory->create()`: a key
  (`$factory->create('hubspot')`) fills another bucket, `<name>-<key>`, and the dashboard shows the
  limiter as unused

### Retention

- define a retention policy for workflow runs, payload snapshots, and failed messages
- prune with care if you rely on relaunch from preserved payloads
- align retention with operational audit requirements

### Retries

- prefer technical retries for transient infrastructure failures
- classify business failures explicitly to avoid blind replay loops (see [Error classification](#error-classification))
- keep retry delay/backoff policies conservative for external APIs
- step retries come from `WorkflowRetryPolicy` only. The `retry_strategy` of the Messenger
  transport does not replay a step recorded as failed: only a relaunch executes it again
- `fluxx.runtime.defaults.max_global_retries` caps retries per step regardless of
  the per-workflow policy, acting as a safety guard against infinite retry loops.
  Set it to `0` to disable retries entirely on a step.

### Locks

- start with workflow/source or workflow/source-target lock scopes
- use business-partition locks only when the partition key is stable and explicit
- keep stale lock timeout aligned with worker heartbeat expectations
- a lock is only recovered from a run that has been inactive for the stale timeout, so keep that
  timeout above the longest step and the longest expected wait in the queue
- after a worker crash, a new launch is refused until then. To go faster, release the lock from the
  runtime page or relaunch the stuck run with `--force`, which gives up its lock
