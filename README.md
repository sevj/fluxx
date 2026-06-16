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

The bundle extension already prepends Doctrine mapping, Twig paths, and translations when the corresponding Symfony components are enabled.

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
php bin/console fluxx:run:list --workflow=contacts --status=failed --errors=with --page=1 --limit=20
php bin/console fluxx:run:retry 7af0d8c3 --reason="Retry after API incident"
php bin/console fluxx:step:retry 7af0d8c3 write_contacts --reason="Replay write step only"
php bin/console fluxx:runtime:inspect
```

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
- stream and consumer group names should stay stable across deploys
- size Redis retention according to replay and audit needs

### Retention

- define a retention policy for workflow runs, payload snapshots, and failed messages
- prune with care if you rely on relaunch from preserved payloads
- align retention with operational audit requirements

### Retries

- prefer technical retries for transient infrastructure failures
- classify business failures explicitly to avoid blind replay loops
- keep retry delay/backoff policies conservative for external APIs

### Locks

- start with workflow/source or workflow/source-target lock scopes
- use business-partition locks only when the partition key is stable and explicit
- keep stale lock timeout aligned with worker heartbeat expectations
