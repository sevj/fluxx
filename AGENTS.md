# Fluxx Package Guidelines

This document defines the engineering rules for the `fluxx` Symfony package.

## Scope

- Apply these rules to all code inside this package.
- Keep the package reusable from a host Symfony application.
- Avoid coupling package code to application-specific infrastructure unless it is explicitly exposed as an extension point.

## Architecture

- Use a clear `Entity` / `Repository` / `Service` architecture.
- Keep domain state in entities or value objects.
- Keep persistence concerns in repositories.
- Keep orchestration, business rules, and cross-system synchronization logic in services.
- Controllers must stay thin and delegate work to services.
- Do not place business logic in Twig templates, controllers, or Doctrine repositories.
- Prefer constructor injection everywhere.
- Register services through Symfony autowiring/autoconfiguration unless explicit service definitions are required.

## Symfony Conventions

- Follow standard Symfony directory conventions.
- Use Symfony bundles, configuration, events, Messenger, console commands, and forms only when they are justified by the use case.
- Prefer framework-native patterns over custom infrastructure.
- Keep public extension points explicit and documented.

## Frontend

- Use Symfony UI conventions and Twig for the frontend layer.
- Prefer Twig templates, Twig components, and Symfony form rendering over custom frontend stacks unless there is a strong reason otherwise.
- Keep UI structure simple, maintainable, and server-rendered by default.
- Do not hardcode user-facing text in templates, controllers, or PHP classes.
- All visible text must go through the translation system.

## Language

- Write all code in English.
- Use English for class names, method names, property names, variables, database fields, config keys, commit-facing comments, and code comments.
- Translation files may contain end-user text in the target locales, but translation keys should remain English and domain-oriented.

## Translations

- Put user-facing text in translation files.
- Use meaningful translation keys grouped by feature or screen.
- Prefer stable keys such as `workflow.list.title` over sentence-based keys.
- Do not build translation strings dynamically when a fixed key structure is possible.

## Persistence

- Use Doctrine entities for persisted domain objects.
- Keep entity behavior coherent and focused on domain consistency.
- Repositories should expose query intent, not generic data-access helpers.
- Avoid leaking query-builder details outside repositories.

## Services

- Services should have one clear responsibility.
- Extract a service when logic is reused, state transitions are non-trivial, or orchestration crosses multiple collaborators.
- Favor small composable services over large manager classes.
- Name services after business intent.

## Twig

- Keep Twig templates focused on presentation.
- Avoid complex conditionals and data shaping in templates.
- Prepare view models or template data in PHP before rendering.
- Reuse partials or components when markup patterns repeat.

## Quality

- Add tests for non-trivial behavior.
- Prefer unit tests for business services and focused integration tests for Symfony wiring, Doctrine integration, and bundle behavior.
- Keep static analysis and coding style compatible with the package standards.
- Avoid dead code, commented-out code, and speculative abstractions.

## Package Discipline

- The package must stay installable in a host Symfony application.
- Keep dependencies minimal and justified.
- Document any required host application configuration.
- When adding UI, routes, config, migrations, or assets, ensure the integration contract is clear from the package side.
