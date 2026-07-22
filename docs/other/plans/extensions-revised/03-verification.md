# Extension System Verification and Release Gates

> **Status:** Normative acceptance plan
> **Date:** 2026-07-22
> **Architecture:** `00-plugin-system-adr.md`
> **Architecture detail:** `01-extension-system-plan.md`
> **Implementation handoff:** `04-implementation-handoff.md`
> **Parity inventory:** `02-adminer-5.5.0-hook-matrix.md`

## 1. Verification Principle

An extension seam is complete only when a consumer can configure it through
`SQLCraftBuilder`, build a factory, create a session, and observe the changed
behavior without editing SQLCraft internals.

Interface existence, low-level constructor injection, or an isolated unit test is
insufficient. Each stable seam needs:

1. Contract test.
2. Adapter unit test.
3. Composition-root integration test.
4. Failure/conflict test.
5. Public example compiled by CI.

## 2. Documentation and Adminer Baseline Gates

### Hook inventory

- Extract the 79 `public function` declarations from
  `adminer/adminer/include/adminer.inc.php`.
- Parse 79 unique hook names from the parity matrix.
- Assert exact set equality.
- Fail on missing, duplicate, stale, or renamed hooks.
- Assert the Adminer version constant remains `5.5.0`; a changed version requires
  a deliberate matrix review.

### Dispatch description

Validate the documented append set against `Adminer\Plugins::$append`:

- `dumpFormat`
- `dumpOutput`
- `editRowPrint`
- `editFunctions`
- `config`

All other methods remain documented as first-non-null origin behavior. This check
does not require SQLCraft to reproduce those semantics.

### Plan consistency

Fail documentation review if any active plan:

- claims Adminer has approximately 60 hooks;
- calls the old 68-row mapping complete;
- treats `selectQuery()` as a pre-execution rewriter;
- proposes UI/session/form hooks in SQLCraft core;
- lists already-existing SQLCraft adapters as missing;
- presents database/table visibility filtering as authorization;
- presents regex SQL rewriting as a security control;
- claims arbitrary PSR-14 listener providers expose registration or priorities.

## 3. Builder and Registry Tests

### Factory snapshots

- Configure builder A, build factory A, mutate builder A, and build factory B.
- Assert factory A retains its original registrations.
- Assert factory B contains the later registrations.
- Assert neither factory permits late mutation.

### Canonical identifiers

Test drivers, aliases, readers, and writers with:

- valid lowercase names;
- mixed-case input according to the chosen normalization rule;
- empty names;
- whitespace-only names;
- invalid alias targets;
- alias targets that name another alias.

Alias chains are not supported. Missing targets and alias-to-alias targets fail at
`build()` time, never at connection time.

### Conflict policy

For each registry kind:

- `register*()` succeeds for a new name.
- `register*()` throws for a duplicate.
- `replace*()` succeeds for an existing name.
- `replace*()` throws for a missing name.
- Error messages name the registry kind and canonical identifier.
- Built-in registrations follow the same rules as third-party registrations.

### Validation failures

`build()` fails before opening a connection when:

- a driver definition is incomplete;
- a materialized driver's name differs from its definition name;
- a required metadata factory is absent;
- an alias target is absent;
- SQLCraft-owned listeners and an external dispatcher are both configured.

Format factory output is validated when resolved because writer factories require
the active connection. A wrong type or name must fail before export/import work
begins. A connected platform name differing from the canonical driver definition
must fail during session creation before session services are exposed.

## 4. Driver and Platform Conformance

Run the same contract suite for MySQL, MariaDB flavor behavior, PostgreSQL,
SQLite, SQL Server, and a fake third-party engine.

### Driver definition

- Registration requires no core switch or source edit.
- Driver lookup and aliases resolve the registered definition.
- Connection creation uses the definition's driver.
- Metadata construction uses the same definition's metadata factory.
- Optional process management comes from the same definition.

### Platform role aggregate

- Every platform exposes all required role objects.
- Each role satisfies its narrow interface.
- Replacing one role leaves the others unchanged.
- Query rendering uses the query-dialect and quoting roles.
- DDL builders/managers use the DDL role.
- Metadata inspectors use the introspection role.
- Capability resolution uses platform identity/version and agrees with available
  roles.

### Capability liveness

For every `Capability` enum case advertised by a built-in platform:

- Locate a public factory/session operation consuming it, or
- classify it as informational and prove why no operation is required.

`Capability::Kill` specifically requires end-to-end process-manager tests for
MySQL/MariaDB, PostgreSQL, and SQL Server. SQLite must not advertise `Kill`.

### Third-party fixture

The fake engine must demonstrate:

1. Builder registration with a driver name absent from `DatabaseDriver`.
2. String driver selection through `ConnectionParameters`.
3. Connection creation through its fake driver.
4. Platform role resolution.
5. Database/table metadata through its inspector set.
6. A query through `DatabaseSession`.
7. Export with a registered writer.
8. Capability checks.
9. No changes to built-in driver, schema-factory, or platform switch statements.

## 5. Metadata Inspector-Set Tests

### Set construction

- A metadata factory creates a fresh immutable set per connection.
- Every required inspector is present.
- Optional privilege behavior is represented explicitly, not through an
  uninitialized property.

### Decoration

Create a server-inspector decorator that changes the database collection:

- `session->schema()` observes the decorated collection.
- Database-scope export observes the same decorated collection.
- Process listing, CSV column lookup, and privilege security use their matching
  inspectors from the same set.
- The undecorated lower-level adapter remains unchanged.
- A second connection receives its own inspector set.

Create a foreign-key decorator:

- Normal FK inspection uses it.
- Referencing-key inspection uses it.
- Export ordering uses the same decorated dependency where relevant.

### Public schema surface

- Every method documented on the session schema object is present in its declared
  return type.
- PHPStan/Psalm can call databases, schemas, tables, FKs, variables, status, and
  process-list operations without concrete-type suppression.
- Inspector replacement does not require implementing the complete schema manager.

## 6. Credential Tests

### Provider contract

- Array provider returns its credential for a known key and `null` for a miss.
- Environment provider returns `null` when neither variable exists.
- Environment provider returns a `Credential` when either supported variable is
  present, preserving nullable fields.
- Callback provider supports `?Credential` results.

### Chain behavior

- First non-`null` provider wins.
- Providers after a successful result are not called.
- `null` falls through to the next provider.
- A provider exception propagates immediately.
- A provider exception does not trigger fallback.
- All-null resolution produces `CredentialNotFoundException` at the factory
  boundary.
- Empty chain construction fails.

### Secret safety

- Credential values do not appear in exception messages.
- Password constructor parameters remain `#[SensitiveParameter]` where applicable.
- Builder/factory debug output does not enumerate secret values.

## 7. Connection Initializer Tests

### Ordering and success

- Initializers run in registration order.
- Each receives the active connection and effective post-credential parameters.
- `ConnectionOpenedEvent` fires after the last initializer.
- `ConnectionManager` receives the connection only after initialization succeeds.

### Failure

When initializer N throws:

- Initializers after N are not called.
- The connection is closed exactly once.
- The failed connection is not registered.
- `ConnectionInitializationFailedEvent` fires with safe connection metadata and
  the original exception.
- `ConnectionOpenedEvent` does not fire.
- `ConnectionInitializationException::getPrevious()` returns the original error.

### Representative behavior

Use fake connections to verify initializers can perform:

- session-variable setup;
- statement-timeout setup;
- application-name setup;
- driver-specific no-op behavior.

No initializer test should require global connection access.

## 8. Query Interceptor Tests

### Deterministic transformation

Register interceptors A, B, and C:

- B receives A's SQL and parameters.
- C receives B's SQL and parameters.
- The connection and `originalSql` provenance remain unchanged.
- The executor runs C's final SQL and parameters.

### Coverage of execution paths

The pipeline must run for:

- `query()`;
- `execute()`;
- DDL execution;
- query-with-timeout;
- statement batches;
- SQL import;
- typed insert/update/delete builders;
- exporter-generated row queries where they use `QueryExecutor`.

If any path intentionally bypasses interception, document and test the reason.

### Cancellation and invalid output

- An interceptor may throw `OperationCancelledException`.
- No database call occurs after cancellation.
- Empty SQL is rejected before execution.
- Invalid parameter shape is rejected before execution.
- Interceptor exceptions preserve their original type unless a documented domain
  wrapper is required.

### Event ordering

For a transformed successful query:

1. Interceptor chain runs.
2. Before event sees final SQL.
3. Database operation runs.
4. Query history records final SQL.
5. Success/slow event sees final SQL.

For failure, `QueryFailedEvent` sees final SQL and parameters. Remove tests and
documentation that depend on `BeforeQueryExecuted::replaceSql()`.

## 9. Format, Reader, and Sink Tests

### Factory lifetime

- The same registered writer factory returns distinct writer instances for two
  exports.
- Connection-bound SQL writers receive the correct active connection.
- Stateful JSON/XML/XLSX/HTML/delimited writers do not leak state between exports.
- Reader factories return fresh readers for independent imports.

### Registration correctness

- Factory output implements the expected interface.
- Factory output format name matches registration name.
- A custom writer appears in a factory-created session's supported formats.
- Explicit replacement changes the writer used by the exporter.
- Duplicate registration without replacement fails.

### Sink ownership

- A caller can use resource, string-buffer, compression, PSR-7, and multi-file
  sinks without global registration.
- Closing/flushing behavior remains operation-owned.
- Output filename/path remains caller-owned.

## 10. Event Mode Tests

### SQLCraft-owned mode

- Core listeners run before SQLCraft-owned user listeners.
- Builder listeners fire through their separate simple dispatcher.
- Higher priority runs before lower priority.
- Equal priority preserves registration sequence.
- Stoppable events stop later SQLCraft-owned listeners but cannot suppress core
  invariant listeners.
- Listener exceptions propagate for normal event dispatch. A listener error while
  reporting connection-initialization failure is retained separately and does not
  replace the initializer error as `previous`.

### Consumer-owned mode

- SQLCraft core listeners run before the supplied external dispatcher.
- The supplied external dispatcher receives all documented events.
- SQLCraft never attempts to retrieve or mutate its listener provider.
- Core cache invalidation remains live in external mode.
- No external listener priority or registration guarantees are asserted by
  SQLCraft.

### Mutual exclusion

- Configuring both modes fails at `build()`.
- Configuring neither mode uses the documented no-op/default event behavior.

## 11. Query History and Cache Liveness

### Query history

- A builder-supplied history adapter receives successful and failed statements
  from a factory-created session.
- It records final intercepted SQL.
- `null` history performs no recording.
- History is not created silently when disabled.

### Metadata cache

- Builder-supplied cache is used by session schema calls.
- DDL/schema-change events invalidate the same cache instance.
- Decorated metadata inspectors still pass through cache behavior.
- Null cache executes loaders directly.

## 12. Stability and Architecture Gates

### Stable-surface manifest

Maintain a machine-readable or reflection-derived allowlist. CI fails when:

- a stable class/interface disappears;
- a stable method is removed or made less accessible;
- a stable parameter or return type changes incompatibly;
- a stable enum case disappears;
- an internal class is accidentally added to a stable public signature.

Adding a method to an implementer-facing stable interface is a breaking change.
Adding caller-only methods to a final stable class follows the documented SemVer
policy.

### Dependency direction

Deptrac/static checks must enforce:

- Contracts and public value types do not depend on concrete engine adapters.
- Builder/factory may name concrete defaults as the composition root.
- Metadata set contracts do not depend on `SchemaManager` implementation.
- Driver definitions depend on contracts, not built-in driver classes.
- Extension examples import no `@internal` types.

### Runtime Composer dependencies

- Core event classes load in a production install because
  `psr/event-dispatcher` is required at runtime.
- Optional PSR cache/log adapters fail with clear installation guidance only when
  selected.
- No core class unconditionally requires an undeclared suggested package.

## 13. Documentation Example Gates

Compile and smoke-test examples for:

1. Custom credential provider and fallback chain.
2. Connection initializer.
3. Ordered query interceptor.
4. Custom format writer factory.
5. Metadata inspector decorator.
6. Third-party fake driver definition.
7. SQLCraft-owned event listeners.
8. External framework dispatcher wiring.

Each example must:

- use `SQLCraftBuilder`;
- avoid internal classes;
- show duplicate/replacement behavior where relevant;
- state adapter lifetime;
- document failure semantics;
- contain no regex security claims.

## 14. Release Gates

Do not tag v1 until all are true:

- Adminer 5.5.0 matrix validation passes with exactly 79 hooks.
- No applicable matrix row remains “interface exists but not wired.”
- Third-party engine fixture passes end to end.
- All builder conflict and snapshot tests pass.
- Credential, initializer, interceptor, metadata, format, event, history, cache,
  and process-control liveness tests pass.
- Stable-surface compatibility baseline is committed.
- Composer runtime dependencies are correct.
- Changelog and actual Git tag state agree.
- Extension and migration examples compile.
- PHPUnit unit/integration/contract/golden suites pass.
- PHPStan, Psalm, Deptrac, formatter check, and Rector dry-run pass.
- Mutation threshold remains a project release gate if the project continues to
  require it; it is not waived specifically for extension work.

## 15. Non-Goals Verified by Absence

The completed extension plan and source must contain no core implementation of:

- Adminer-style plugin base classes or magic dispatch;
- plugin-directory scanning;
- core service-provider/bundle contracts;
- UI/menu/form/session/authentication hooks;
- schema visibility filters;
- regex read-only enforcement;
- automatic tenant SQL injection;
- shared mutable writer singletons;
- silent duplicate replacement;
- catch-all credential fallback;
- portable priority promises for external PSR-14 dispatchers.
