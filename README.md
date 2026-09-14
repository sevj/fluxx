# Fluxx

Fluxx is a Symfony bundle for orchestrating operational synchronization workflows between systems.

## What It Provides

- workflow definition registry discovered from the host application
- asynchronous step execution through Symfony Messenger
- runtime monitoring for workers, locks, backlog, and in-flight messages
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

Fluxx persists seven tables under the `fluxx_` prefix: `fluxx_user`, `fluxx_setting`,
`fluxx_workflow_run`, `fluxx_workflow_step_run`, `fluxx_workflow_payload`,
`fluxx_workflow_execution_lock`, and `fluxx_runtime_worker_state`.

The bundle does not ship Doctrine migrations to avoid forcing `doctrine/migrations`
as a dependency. Ship your own migrations, or bootstrap from the reference schema:

- PostgreSQL (primary, validated): apply `migrations/schema-postgresql.sql`.
- MySQL or SQLite: run `bin/console doctrine:schema:create --dump-sql` and apply
  the fluxx tables. The mapping supports all three platforms through DBAL.
- As a one-shot dev bootstrap: `bin/console doctrine:schema:update --force` or
  `bin/console doctrine:schema:create` (the latter drops the whole schema).

The Doctrine mapping is auto-registered by the bundle extension, so the host
application does not need to declare the `Fluxx` ORM mapping manually.

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
```

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

## Extension Points

### Custom step types

Implement `Fluxx\StepType\StepTypeProviderInterface` and return one or more `StepTypeDefinition` instances. The provider is auto-tagged as `fluxx.step_type_provider`.

Use this when you need:

- a domain-specific step label in the UI
- a dedicated tone/style for a custom step family
- host-app specific step categories beyond the built-in read/splitter/transform/write/linker set

### Custom workflow definitions

Your host app owns workflow registration. Fluxx does not require a database model for workflow definitions; it reads them from the service container through `SynchronizationRegistry`.

Use this to:

- version workflow graphs in code review
- keep host-specific integration logic out of the bundle
- compose step handlers from regular Symfony services

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
fluxx:step:retry
fluxx:runtime:inspect
```

Examples:

```bash
php bin/console fluxx:workflow:run contacts --trigger=manual --batch-id=nightly-20260616
php bin/console fluxx:workflow:run contacts --parameter offset=100 --parameter limit=25 --parameter filters='{"status":"active"}'
php bin/console fluxx:run:list --workflow=contacts --status=failed --errors=with --page=1 --limit=20
php bin/console fluxx:run:retry 7af0d8c3 --reason="Retry after API incident"
php bin/console fluxx:step:retry 7af0d8c3 write_contacts --reason="Replay write step only"
php bin/console fluxx:workflow:relaunch 7af0d8c3 --force --reason="Worker stuck, manual recovery"
php bin/console fluxx:runtime:inspect
```

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

### Retention

- define a retention policy for workflow runs, payload snapshots, and failed messages
- prune with care if you rely on relaunch from preserved payloads
- align retention with operational audit requirements

### Retries

- prefer technical retries for transient infrastructure failures
- classify business failures explicitly to avoid blind replay loops (see [Error classification](#error-classification))
- keep retry delay/backoff policies conservative for external APIs
- `fluxx.runtime.defaults.max_global_retries` caps retries per step regardless of
  the per-workflow policy, acting as a safety guard against infinite retry loops.
  Set it to `0` to disable retries entirely on a step.

### Locks

- start with workflow/source or workflow/source-target lock scopes
- use business-partition locks only when the partition key is stable and explicit
- keep stale lock timeout aligned with worker heartbeat expectations
