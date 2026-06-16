# Fluxx Architecture Notes

## Workflow Model

The core concept of the package is the `Workflow`.

A workflow is responsible for synchronizing data between two systems through several explicit layers:

1. Data read from the source system
2. Optional data split into branch-specific payloads
3. Data processing and transformation
4. Data write into the target system
5. Optional data linking between already-written target entities

Each workflow should make these layers visible in its design so that responsibilities stay clear and each step can evolve independently.

The current target model is:

- `read`: load the raw dataset from the source system
- `splitter`: separate one read result into one or more downstream payloads
- `transform`: map each downstream payload into the target shape
- `write`: persist the transformed payload into the target system
- `linker`: create relations between entities once multiple writes are completed

This keeps the branching concern explicit and avoids overloading `read` or `transform` with orchestration logic.

## Execution Pipeline

Workflow execution is asynchronous.

Each layer of the workflow pipeline must run through an async process managed with Redis.

The system must support:

- queue-based execution
- multiple workers running in parallel
- decoupled processing between read, splitter, transform, write, and linker
- scaling workers independently depending on load or bottlenecks

## Step Naming

Use explicit step names matching the main workflow phases:

1. `ReadStep`
2. `SplitterStep`
3. `TransformStep`
4. `WriteStep`
5. `LinkerStep`

These names are preferred because they are short, precise, and aligned with the actual workflow intent:

- `ReadStep`: reads data from the source system
- `SplitterStep`: separates one input dataset into multiple downstream branch payloads
- `TransformStep`: normalizes, maps, enriches, filters, or reshapes the source payload
- `WriteStep`: writes data to the target system
- `LinkerStep`: resolves and persists relations between entities produced by several upstream writes

## Step Contracts

Each step type should have its own interface.

Suggested contracts:

- `ReadStepInterface`
- `SplitterStepInterface`
- `TransformStepInterface`
- `WriteStepInterface`
- `LinkerStepInterface`

An optional shared marker can also exist:

- `WorkflowStepInterface`
- `ExecutableWorkflowStepInterface`

`WorkflowStepInterface` should stay minimal and only expose what is common to all step types, such as step identity.

`ExecutableWorkflowStepInterface` should define the runtime execution contract shared by all executable steps.

## Interface Direction

Suggested responsibilities:

### `WorkflowStepInterface`

- identifies a workflow step
- exposes a stable technical name

### `ReadStepInterface`

- reads data from the source system
- returns a step result with raw source records

### `SplitterStepInterface`

- receives the output of one upstream step
- separates the payload into one or more branch-specific outputs
- returns a step result with named downstream outputs

### `TransformStepInterface`

- receives one branch payload
- applies business transformation rules
- returns transformed records ready for write

### `WriteStepInterface`

- receives transformed records from one upstream branch
- writes data into the target system
- returns a step result with counts, errors, or status details

### `LinkerStepInterface`

- receives payloads from several upstream steps
- links entities that now exist on the target side
- returns a step result with counts, errors, or link metadata

## Result Objects

Avoid passing raw arrays between runtime layers forever.

Prefer dedicated transport objects such as:

- `WorkflowStepInput`
- `WorkflowStepInputPayload`
- `WorkflowStepResult`
- `WorkflowStepOutput`

This keeps contracts explicit and gives room for metadata such as:

- cursor or pagination data
- branch identifiers
- batch identifiers
- counts
- warnings
- retry hints
- error details

## Engine Role

`FluxxEngine` is the central orchestration service.

Its role is to:

- load a workflow definition
- dispatch the appropriate async messages for each step
- pass step outputs to the next step
- dispatch downstream branches independently when a splitter produces several outputs
- manage workflow execution flow
- centralize retry, concurrency, and observability behavior

## Recommended Direction

At this stage, the most coherent baseline is:

- workflow definition in PHP
- one interface per step type
- one shared runtime execution contract
- one input/output model for step payload propagation
- `FluxxEngine` as the only orchestration entry point
- async execution via Messenger with Redis transport

## Design Intent

- Source extraction should be isolated from target persistence concerns.
- Payload branching should be isolated from source reading and target mapping.
- Processing should be a first-class step, not an implicit part of extraction or persistence.
- Each workflow step should be observable and retryable.
- The architecture should allow future extensions such as batching, partial retries, dead-letter handling, and per-workflow concurrency tuning.

## Likely Symfony Mapping

These directions suggest the following implementation path in Symfony:

- workflows modeled in the package domain
- step orchestration handled by services
- async dispatch handled through Symfony Messenger
- Redis used as the transport layer for async execution
- multiple worker processes consuming messages in parallel
- one Fluxx Messenger transport shared by workflow steps, scaled with multiple worker processes
