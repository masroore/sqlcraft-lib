# ADR: SQLCraft Extension Model

> **Status:** Proposed — revised after source audit
> **Date:** 2026-07-22
> **Adminer baseline:** 5.5.0, local commit `190a70d`
> **Scope:** Extension architecture and Adminer capability-parity policy
> **Supersedes on adoption:** `docs/other/plans/17-plugin-system.md`

## Context

Adminer exposes customization methods on an `Adminer\Adminer` object. Its optional
`Adminer\Plugins` aggregator composes multiple plugin objects by method name:

- The default `Adminer` object is appended after configured plugins.
- Most hooks return the first non-`null` result.
- Void hooks naturally continue through all plugins because they return `null`.
- Five hooks merge returned arrays: `dumpFormat`, `dumpOutput`, `editRowPrint`,
  `editFunctions`, and `config`.

Adminer 5.5.0 currently exposes 79 overridable methods. SQLCraft is a UI-less SDK,
so reproducing Adminer's inheritance, magic dispatch, HTML hooks, sessions, and
form-processing behavior would create unusable surface area. The useful target is
capability parity for database logic that belongs inside a library.

The first SQLCraft extension plan correctly rejected magic dispatch, but then
introduced a generic `ServiceProviderInterface`/`ExtensionBundle`. That bundle
could register only drivers, formats, and SQLCraft-owned listeners. It could not
register credentials, query history, metadata cache, inspectors, connection
initializers, query interceptors, or connection-scoped formats. It also assumed
that arbitrary PSR-14 listener providers support registration, which PSR-14 does
not standardize.

The source audit found additional blockers:

- `SQLCraftFactory` hardcodes formats and metadata construction per session.
- Existing query-history adapters are not wired into factory-created sessions.
- Third-party drivers cannot supply metadata factories without editing a core
  platform-name switch.
- `ConnectionOpenedEvent` cannot perform Adminer's `afterConnect` role because it
  does not contain the connection.
- Event-based SQL replacement has undefined composition when multiple listeners
  rewrite the statement.
- `PlatformInterface` transitively requires 85 methods, making a public abstract
  decorator shallow and expensive to evolve.
- Registry duplicates silently overwrite previous registrations.
- The proposed credential chain cannot distinguish a miss from a provider failure.

## Decision

### 1. Parity target

SQLCraft targets **Adminer capability parity**, not Adminer API or dispatch parity.

Every Adminer 5.5.0 hook must appear in the parity matrix and receive exactly one
of these dispositions:

1. SQLCraft extension seam or adapter.
2. Direct configuration or ordinary core operation.
3. UI/HTTP/form/presentation behavior intentionally excluded.
4. Application authentication/session behavior intentionally excluded.
5. Package metadata rather than runtime extension behavior.

A plan cannot claim parity while any Adminer hook is missing, stale, or
unclassified.

### 2. Explicit composition root

SQLCraft will expose a mutable bootstrap `SQLCraftBuilder`. `build()` freezes its
configuration into an immutable `SQLCraftFactory`; each factory creates immutable
connection-scoped `DatabaseSession` aggregates.

Third-party packages provide normal PHP configuration functions or classes that
call builder methods. Core defines no generic plugin, bundle, service-provider,
auto-discovery, or directory-scanning abstraction.

### 3. Extension mechanisms

Use distinct mechanisms with explicit semantics:

| Need | Mechanism |
|---|---|
| Observe lifecycle activity | PSR-14 event |
| Cancel an operation | Stoppable pre-operation event |
| Transform SQL/parameters | Ordered query-interceptor chain |
| Initialize a new connection | Ordered connection-initializer chain |
| Supply credentials | `CredentialProviderInterface` and nullable-miss chain |
| Add an engine | Atomic driver definition containing driver and engine adapters |
| Replace engine behavior | Composed platform role adapters |
| Replace metadata behavior | Per-connection metadata-inspector set |
| Add import/export formats | Named factories creating fresh adapter instances |
| Replace caching/history | Explicit builder dependency |

Events are not used as middleware. Listener registration order is portable only
when SQLCraft owns its simple dispatcher.

### 4. Stable engine seam

A third-party engine is a supported v1 extension. Registration must be atomic:
the engine definition includes its driver, metadata-inspector factory, composed
platform roles, and optional administration adapters. Registering a driver must
not require editing a platform-name `match` statement in core.

`PlatformInterface` becomes a small aggregate of role interfaces rather than an
85-method inheritance aggregate. DDL, introspection, query dialect, quoting, and
type mapping remain independently replaceable roles.

### 5. Security boundary

The following are not security features and will not ship as reference
extensions:

- Regex-based read-only SQL classification.
- Regex/string-based tenant predicate injection.
- Database/table visibility filters presented as access control.

Database authorization remains the responsibility of database privileges and
explicit SQLCraft security modules. Consumer UI filtering remains consumer code.

### 6. Compatibility

The repository has no `v1.0.0` tag as of 2026-07-22, despite a changelog section
dated 2026-07-21. Extension-seam corrections may therefore break the current
untagged API. Release metadata must be reconciled before the first real v1 tag.

Only an explicit allowlist of intentional caller and implementer interfaces is
SemVer-stable. Public source location alone does not imply extension stability.

## Rejected Alternatives

- Adminer-compatible hook names or plugin classes.
- Magic `__call` dispatch.
- First-non-null or append semantics in SQLCraft.
- Plugin directory scanning or Composer auto-discovery.
- `ServiceProviderInterface` and `ExtensionBundle` in core.
- A portable `ListenableProviderInterface` claim over arbitrary PSR-14 providers.
- `AbstractPlatformDecorator` or `AbstractDriverDecorator` forwarding every method.
- Shared mutable format-writer instances.
- Silent registry replacement.
- Catch-all credential fallback.
- Event listener ordering as a SQL transformation pipeline.
- Blanket `@api` annotation of every contract, DTO, value object, and event.
- A global static-analysis ban on `method_exists()`.

## Consequences

### Positive

- Extension packages use typed, discoverable, deterministic seams.
- Driver packages become genuinely usable through the public composition root.
- Stateful adapters receive correct connection/operation lifetimes.
- Conflicts fail during bootstrap instead of silently changing runtime behavior.
- Adminer parity becomes auditable against an exact upstream baseline.
- Public compatibility promises are narrow enough to keep.

### Costs

- Factory, platform, metadata, and event contracts require breaking pre-v1 work.
- Existing examples and tests must migrate to the builder.
- Engine adapters need a broader conformance suite.
- The hook matrix must be updated whenever the vendored Adminer baseline changes.
