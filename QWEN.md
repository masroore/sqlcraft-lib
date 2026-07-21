# SQLCraft — Project Context

SQLCraft is a framework-independent, PDO-based PHP 8.4+ database administration SDK. It exposes typed, capability-aware connection, metadata, DDL, query, import/export, and security services. It is not an ORM, migration framework, web UI, or application container.

**Status:** v1.0 release-candidate hardening. Gap-analysis phases 1–7 are implemented and committed; phase 8 documentation/config cleanup is in progress. Release remains blocked on the phase 9 mutation-testing gate.

**Supported engines:** SQLite, MySQL, MariaDB, PostgreSQL, Microsoft SQL Server. Oracle is deferred and not supported in v1. Empty Oracle placeholder directories may remain for future work.

## Entry point

`SQLCraftFactory` is the composition root. `DatabaseSession` exposes the connection, schema, DDL, query execution, import, export, users, and privileges services. Consumers may also wire contracts directly.

```php
$session = (new SQLCraftFactory())->session($parameters);
$rows = $session->query('SELECT * FROM "users" WHERE id = ?', [1]);
```

## Architecture

```
Consumer application
        │
SQLCraftFactory / DatabaseSession
        │
Schema · Query · DDL · Execution · Import/Export · Security
        │
Contracts (ports)
        │
Connection / Driver / Platform / Metadata adapters
        │
PDO
```

`Contracts` contains ports. Services depend on contracts, value objects, DTOs, exceptions, and events—not concrete adapters. `deptrac.yaml` enforces boundaries.

## Source structure

- `Capabilities/` capability enum and resolver
- `Connection/` PDO connection, credentials, transactions, results
- `Contracts/` public ports
- `DDL/` immutable DDL builders and `DdlManager`
- `Execution/` query executor, batching, splitting, history
- `Export/` exporters, writers, sinks, compression, ordering
- `Import/` SQL/CSV import, readers, sources, upsert mapping
- `Metadata/` engine-specific inspectors and export source
- `Platform/` engine dialects and capability matrices
- `Query/` SELECT/DML builders, pagination, FK navigation, search, BLOB streams
- `Schema/` schema aggregate and metadata caches
- `Security/` privilege guards and managers
- `Events/` PSR-14 event types and dispatchers

## Verification

```sh
rtk composer stan
rtk composer psalm
rtk composer cs
rtk composer test
rtk composer test:golden
```

Integration services and optional PDO drivers are environment-dependent.
