# Deferred and Out-of-Scope Features

> Status: v1 scope clarification
>
> Supported v1 engines: MySQL, MariaDB, PostgreSQL, SQLite, and SQL Server.
>
> This document records features that are documented as future work or are
> intentionally outside SQLCraft's responsibility. "Deferred" means the feature
> is not part of the v1 contract and has no guaranteed implementation. It does
> not mean the feature can be silently treated as supported.

## 1. JSON/XML export writers

### Planned design

Export is driven through `FormatWriterInterface`:

```text
writeHeader()
writeTableHeader()
writeTableDdl()
writeRows()
writeTableFooter()
writeFooter()
```

A JSON writer would use this lifecycle to produce structured, streaming output
while managing document structure, table metadata, row arrays, separators,
NULL values, binary values, and single-table versus multi-table exports.

An XML writer would use the same lifecycle while managing the XML declaration,
root element, table/column/row elements, escaping, NULL values, binary values,
and well-formed closing tags.

`DumpOptions` describes `json` and `xml` as possible format identifiers, but
those writers were explicitly deferred.

### v1 status

Implemented export formats:

- SQL
- CSV
- TSV
- Semicolon-separated CSV where registered/configured

Not implemented:

```text
JsonFormatWriter
XmlFormatWriter
```

Consumers requiring JSON or XML must provide a custom `FormatWriterInterface`
implementation or wait for a future release. The core exporter must not claim
that `json` or `xml` works when no writer is registered.

This deferral primarily concerns export writers. SQL/CSV/TSV import is in
scope; JSON/XML import readers were not established as a v1 promise either.

### Why deferred?

The hard problem is defining a stable interchange schema, not serializing one
row. A durable JSON/XML format must define behavior for:

- Table metadata and DDL.
- Multiple tables and export scopes.
- Views, triggers, routines, and events.
- Binary values.
- Database-specific types.
- NULL versus empty string.
- Numeric, boolean, date, and JSON-native values.
- Streaming output.
- Round-trip import behavior.

SQL output follows database-native syntax. JSON/XML require SQLCraft to define
and preserve an application-independent interchange schema, so the work was
postponed rather than shipped as an unstable format.

## 2. Deferred DDL features

The DDL subsystem is not generally deferred. Core operations such as create,
alter, drop, copy, indexes, constraints, views, and routines are in scope
where implemented and capability-gated.

The following operations remain deferred.

### 2.1 Rename database

Conceptually:

```sql
ALTER DATABASE old_name RENAME TO new_name;
```

Engine behavior is not uniform:

- PostgreSQL supports database renaming.
- MySQL/MariaDB do not provide one uniform direct database-rename operation;
  dump/create/restore is the practical pattern.
- SQLite databases are files, not server-managed database objects.
- SQL Server has its own database-level locking, connection, and ownership
  semantics.

A portable `RenameDatabaseBuilder` would either expose different semantics per
engine, pretend dump/recreate is an atomic rename, or fail on some engines.
The operation is deferred instead of exposing a misleading cross-platform API.

### 2.2 Move table between databases or schemas

Examples:

```sql
-- MySQL-style cross-database move
RENAME TABLE db1.orders TO db2.orders;

-- PostgreSQL-style same-database cross-schema move
ALTER TABLE schema_a.orders SET SCHEMA schema_b;
```

The operation varies by engine:

- MySQL can move a table between databases with `RENAME TABLE`.
- PostgreSQL can move a table between schemas, but not between databases with
  one ordinary DDL statement.
- SQL Server uses multi-part names and distinct schema/database ownership
  rules.
- SQLite has no equivalent server-side schema/database model.

A correct `MoveTableBuilder` would also need to account for foreign keys,
views, triggers, routines, indexes, permissions, references to the old name,
transactionality, and whether a move is intra-schema, inter-schema, or
inter-database. It is deferred rather than implemented as a partial rename.

### 2.3 Alter trigger

Most engines do not expose a useful, portable `ALTER TRIGGER` operation. The
reliable cross-engine pattern is:

```text
DROP TRIGGER
CREATE TRIGGER
```

That drop/recreate pattern is supported as the explicit approach. A future
higher-level replacement operation could model dependency handling and
transactional behavior honestly, instead of pretending all engines have
identical `ALTER TRIGGER` syntax.

`AlterTriggerBuilder` is therefore deferred.

### 2.4 Scheduled database events

This covers objects such as MySQL/MariaDB scheduler events:

```sql
CREATE EVENT purge_old_rows
ON SCHEDULE EVERY 1 DAY
DO
  DELETE FROM audit_log WHERE created_at < ...;
```

Equivalent behavior is not portable:

- MySQL/MariaDB have native event schedulers.
- PostgreSQL generally relies on external schedulers or extensions.
- SQLite has no server scheduler.
- SQL Server commonly uses SQL Server Agent rather than an equivalent
  database object.

Scheduler state, ownership, permissions, time zones, deployment, and failure
semantics also differ. No v1 builders are shipped for:

```text
CreateEventBuilder
AlterEventBuilder
DropEventBuilder
```

The export option `includeEvents` is therefore not a promise of portable event
export. Selecting it produces an explicit warning when the deferred feature is
requested.

### 2.5 User-defined types

This includes PostgreSQL constructs such as:

```sql
CREATE TYPE order_status AS ENUM ('pending', 'paid', 'cancelled');
```

It can also include domains, composite types, range types, and engine-specific
type aliases.

The engines use materially different concepts:

- PostgreSQL has a rich extensible type system.
- SQL Server has user-defined aliases and other distinct type mechanisms.
- MySQL/MariaDB primarily use enums, sets, and other non-equivalent features.
- SQLite has no comparable server-side user-defined type catalog.

A unified implementation would need models for type dependencies, ownership,
privileges, columns, functions, constraints, defaults, drop ordering, and
engine-specific type behavior. No v1 builders are shipped for:

```text
CreateTypeBuilder
AlterTypeBuilder
DropTypeBuilder
```

The export option `includeUserTypes` therefore produces an explicit warning
rather than silently pretending that all user-defined types were exported.

## 3. Oracle support

Oracle is deferred as an entire platform, not as one isolated missing feature.
The following are not part of v1:

- Oracle driver.
- Oracle PDO/`pdo_oci` integration.
- Oracle connection creation.
- Oracle platform and identifier quoting.
- Oracle type mapping.
- Oracle pagination rendering.
- Oracle metadata and introspection.
- Oracle capability matrix.
- Oracle DDL rendering.
- Oracle query integration.
- Oracle conformance tests.
- Oracle CI/container coverage.
- Oracle-specific import/export behavior.

Empty placeholder directories, if retained, do not represent support.

### Why deferred?

Oracle requires a complete vertical integration:

```text
Driver -> Connection -> Platform -> Capabilities
       -> Metadata -> Query rendering -> DDL -> Export/import -> Tests
```

Adding Oracle names to enums or `match` expressions without implementing that
chain would create false support and runtime failures. Oracle is consequently
excluded from v1 supported-engine lists. Future work must begin with the
driver/connection layer, then platform quoting and pagination, type mapping,
metadata queries, capability definitions, DDL/query renderers, and integration
coverage.

## 4. Optional or deferred helpers

These are convenience abstractions, not core database functionality.

### 4.1 `AbstractDriver`

The driver contract is interface-based. `AbstractDriver` was proposed as an
optional base class for reducing boilerplate in third-party drivers.

Third-party drivers are not required to extend it because that would couple
plugins to SQLCraft's inheritance hierarchy and make base-class changes more
likely to become breaking changes. Built-in drivers implement the interface
directly. A future release may provide the helper without changing the
interface contract.

### 4.2 `QueryLogger`

`QueryLogger` was proposed as an optional PSR-3-compatible query logging hook.
It could expose SQL, redacted bindings, execution duration, affected-row
counts, SQLSTATE/errors, and correlation data.

It is not part of the v1 public contract. Query/execution events can be
consumed by an application and forwarded to PSR-3, metrics, tracing, or audit
infrastructure. This keeps logging policy in the host application, where
production/debug, redaction, sampling, and retention decisions belong.

### 4.3 Pooling and connection decorators

The design leaves extension seams for:

- Connection pools.
- Lazy connections.
- Read-replica routing.
- Retry decorators.
- Fiber-safe or asynchronous connection acquisition.

These are not v1 behavioral guarantees. They require application-specific
policy for pool size, idle timeouts, health checks, transaction pinning,
replica lag, read-after-write consistency, retry safety, and concurrency.
SQLCraft keeps connection behavior explicit rather than silently opening
additional connections or routing reads to replicas.

## 5. Explicitly out of scope

These items are not merely waiting for a future SQLCraft implementation; they
belong to the consuming application or another library.

### Permanent or remembered login

SQLCraft does not own browser sessions, remember-me tokens, credential
persistence, login screens, password-reset flows, or authentication storage.
It accepts credentials through connection inputs/providers.

### HTTP, UI, routing, and rendering

SQLCraft does not provide HTTP controllers, routes, templates, admin pages, HTML
rendering, or PSR-7 response delivery. Export writes to an injected sink; the
consumer decides whether that sink is a file, HTTP response body, object
storage stream, or another destination.

### Persistent query-history storage

SQLCraft may emit query execution events but does not persist query history.
Consumers can store those events in database tables, structured logs,
OpenTelemetry, audit services, or application-specific history stores.

### Tar/ZIP archive assembly

SQLCraft can stream separate table files through a multi-file sink, but the
core exporter does not package them into tar/zip archives. Consumers can use
`ext-phar`, system `tar`, Symfony Filesystem, or another archive library.

## Summary

v1 deliberately does not guarantee:

```text
JSON/XML writers
Database rename
Cross-database/schema table moves
ALTER TRIGGER abstraction
Scheduled database events
User-defined types
Oracle
AbstractDriver convenience base
QueryLogger convenience hook
Pooling/lazy/replica lifecycle policies
```

These decisions keep v1's public contract honest while preserving clear seams
for future extensions.

## Source documents

- `docs/plans/04-feature-inventory.md`
- `docs/plans/07-module-breakdown.md`
- `docs/plans/08-driver-architecture.md`
- `docs/plans/14-import-export.md`
- `docs/plans/23-roadmap.md`
- `docs/plans/gap-analysis/00-README.md`
- `docs/plans/gap-analysis/06-phase-import-export.md`
- `docs/plans/gap-analysis/07-phase-ddl-scope.md`
- `docs/plans/gap-analysis/08-phase-hygiene.md`
