# 03 — Connection Layer Audit

> **Status:** Final
> **Audit date:** 2026-07-21
> **Baseline:** `master @ 6d50506`
> **Plans reviewed:** `10-connection-layer.md` (plus the `00-overview.md` architecture diagram)
> **Implementation reviewed:** `src/Connection/`, `src/Contracts/Connection/`, `src/Driver/`, `src/ValueObjects/ConnectionParameters.php`, `tests/Unit/Connection/`, `tests/Integration/`

---

## 1. Gaps

- **CRITICAL — Pool / Lazy / ReadReplica decorators missing.** The `00-overview.md` architecture diagram explicitly promises `ConnectionPool / LazyConnection / ReadReplicaConnection` in the connection layer. None exist anywhere (grep across all `*.php` returns zero matches). Plan 10 §13's `ConnectionPoolInterface` seam (`acquire`/`release`/`getStats`) — explicitly scoped as "interface seam only in v1" — is also absent. This is the headline gap of the connection layer.

- **CRITICAL — `ConnectionManager` missing (plan 10 §5.2).** No named-connection registry (`add()`/`get()`/`getNames()`/`closeAll()`). The plan's central multi-connection capability ("Adminer's global driver is a single active connection; SQLCraft supports N concurrent") is unimplemented. `ConnectionFactory` (`src/Connection/ConnectionFactory.php`) only wraps a single `DriverInterface`.

- **CRITICAL — `CredentialProvider` boundary missing (plan 10 §4).** No `CredentialProviderInterface`, no `Credential` VO, no `Array/Env/CallbackCredentialProvider`. Credentials are embedded directly as `username`/`password` on `ConnectionParameters` (`src/ValueObjects/ConnectionParameters.php`). The plan's "library never holds secrets; consumer injects a provider" architecture is not honored. (Cross-ref: [07](07-security-events-plugins-api.md).)

- **MODERATE — SSL/TLS not implemented (plan 10 §3 `SslOptions`, §12 PDO mapping).** No `SslOptions` VO; `ssl` is an untyped `array<string, scalar|null>` on `ConnectionParameters` and is **never consumed** — `PdoConnectionFactory::connect()` sets only `ERRMODE`/`EMULATE_PREPARES`/`STRINGIFY_FETCHES` (plus one sqlserver attribute). No `PDO::MYSQL_ATTR_SSL_*` mapping exists anywhere in `src/`.

- **MODERATE — Isolation level is a no-op.** `beginTransaction(string $isolationLevel)` (`src/Connection/PdoConnection.php:182`) stores the level on `Transaction` and emits it in events, but never executes `SET [SESSION] TRANSACTION ISOLATION LEVEL`. Grep confirms no isolation SQL anywhere in `src/`. The parameter has no effect on the actual transaction.

- **MODERATE — Lazy connect not implemented (plan 10 §6.1).** Plan: "PdoConnection defers PDO instantiation until the first execute()/query()." Reality: `PdoConnectionFactory::connect()` constructs `new PDO(...)` eagerly and passes a live PDO into `PdoConnection`'s constructor. There is no deferred-instantiation path.

- **MINOR — `Dsn` VO absent (plan 10 §3).** `DriverInterface::buildDsn()` returns a plain `string`, not the planned `Dsn` readonly VO.

- **MINOR — `ConnectionParameters` restructured vs plan.** Missing planned fields `driver`, `pdoOptions`, `driverOptions` (replaced by a generic `extras` bag); validation differs from the plan (host/socket required-rule not enforced).

## 2. Drift

- **MODERATE — `PreparedStatement` is not a real PDOStatement wrapper (plan 10 §11).** Plan: "PdoPreparedStatement wrapping PDOStatement … never exposed." Reality (`src/Connection/PdoPreparedStatement.php`): it stores the SQL string and delegates to `PdoConnection::executePrepared()`/`queryPrepared()`, which **re-prepare on every call**. No statement reuse — the batch/bulk-INSERT performance benefit the plan cites is lost. (Cross-ref: [08](08-testing-performance-roadmap.md) performance §5.)

- **MINOR — Factory topology differs from plan 10 §5.1.** The plan's `ConnectionFactory` takes `DriverRegistry + CredentialProvider + EventDispatcher` and calls `driver->connect()`. Implementation splits into a thin `ConnectionFactory` (single driver) + `PdoConnectionFactory` (builds PDO, options, events) + a separate `DriverRegistry` (`src/Driver/DriverRegistry.php`). No credential-provider injection point in either.

- **MINOR — `lastInsertId` return type narrower than contract.** `ConnectionInterface` declares `string|int|false` (matches plan), but `PdoConnection::lastInsertId()` implements `string|false` — `int` is never returned.

- **MINOR — Exception translator diverges from plan 10 §9 (arguably richer).** Uses `str_starts_with($sqlState, '28')` vs the plan's exact `'28000'`; adds PgSQL SQLSTATEs (`23505`/`23503`/`42601`), extra native codes (deadlock `1205`/`1213`, unique `1169`/`2601`/`19`), and SQLite `nativeCode === 1` message-sniffing. Reads SQLSTATE from `errorInfo[0]` rather than `getCode()`.

## 3. Extras

- **Rich connection/transaction event surface.** `ConnectionEventDispatcherInterface` (8 methods: `beforeConnectionOpened`/`connectionOpened`/`connectionFailed`/`connectionClosed` + full transaction lifecycle) with **cancellation hooks** — `beforeConnectionOpened`/`beforeTransactionBegan` return `?string` cancel reason → `OperationCancelledException`. Plan 10 §5.1 mentioned only a single `ConnectionOpenedEvent`.
- **`getDatabaseName()`** on `ConnectionInterface` — not in plan 10 §2.
- **`TransactionManager`** with a `transactional(callable)` wrapper (`src/Connection/TransactionManager.php`) — plan 10 deferred the full manager to the query-engine doc.
- **`ExecutionResult` timing** (`elapsedMs`, `sql`) and `PdoConnection::normalizeRow/normalizeValue` scalar coercion.
- **`SecretRedactor::dsn()`** redaction on connection failure (`src/Connection/PdoConnectionFactory.php:64`).
- **Inconsistent savepoint naming:** `PdoConnection` uses `sqlcraft_sp_N` (sequence); `TransactionManager` uses `sp_<hex>` (random) — two schemes for the same concept.

## 4. Faithful to Plan

- **`ConnectionInterface` core surface** (execute/query/prepare/transaction begin-commit-rollback/savepoints/lastInsertId/quoting) matches plan 10 §2 semantics.
- **Buffered and streaming `ResultInterface` implementations** (`StreamingResult`, buffered result) per plan 10 §7, with `StreamingResultException` guarding cursor misuse.
- **Savepoint-based nesting** in `Transaction` (savepoint create/release/rollback for nested begins) per plan 10 §6.
- **PDO exception translation** wired at the boundary (`PdoExceptionTranslator`) producing the typed hierarchy from plan 05 §9 — PDO never surfaces past the adapter (plan 10 §1 hexagonal boundary honored).
- **SQLite integration coverage** exists per M2 T7 (`tests/Integration/`).

## 5. Summary

The core single-connection path — `ConnectionInterface`, `PdoConnection`, buffered/streaming results, exception translation, savepoint-based `Transaction` — is implemented and broadly faithful to plan 10, with a notably richer event/cancellation system than specified. However, three headline architectural promises are entirely absent: the `Pool`/`Lazy`/`ReadReplica` decorators from the overview diagram, the named `ConnectionManager`, and the pluggable `CredentialProvider` boundary (credentials are inlined into `ConnectionParameters`). Two accepted parameters are functionally inert — isolation level is never applied to the database, and SSL options are never mapped to PDO attributes — and connections are opened eagerly rather than lazily as planned.
