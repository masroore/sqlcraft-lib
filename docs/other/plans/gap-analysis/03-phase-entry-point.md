# Phase 3 — Consumer entry point & wiring

> Depends on: Phase 2 (contracts, credential VO half).
> Release-blocking: yes — without this, nobody can use the library without hand-wiring every service.
> Closes audit findings: 07 §3.3/§4 (SQLCraftFactory/DatabaseSession); 02 findings 1/2/4/7 (ConnectionManager, CredentialProvider, factory signature, bootstrap); 06 finding 8 (FormatRegistry).

The library currently has no top-level entry point. A consumer must manually assemble
`PdoConnectionFactory`, `QueryExecutor`, `SchemaManager`, `DdlManager`, `Exporter`, etc.
This phase builds the composition root the design promised, so every later feature has one
obvious place to be wired.

---

## 3.1 `DriverRegistry` bootstrap (already exists — extend, don't recreate)

**Correction to audit 07:** `DriverRegistry` **exists** at `src/Driver/DriverRegistry.php`
(instance-based, DI-friendly). Only `FormatRegistry` is genuinely absent. Do not recreate the
driver registry.

**Work:** ensure a bootstrap path pre-registers the five built-in drivers (MySQL, PostgreSQL,
SQLite, SqlServer — MariaDB reuses MySQL driver). This is `SQLCraftFactory`'s job (§3.2). Update
`08-driver-architecture.md` §8 to the instance-based API (Phase 8 §docs).

---

## 3.2 `SQLCraftFactory` + `DatabaseSession`

**Problem:** doc 18 §2–3 define these as the root consumer entry points (the static Facade
was explicitly rejected in §2.1 — do **not** build one). Neither exists. This is the single
highest-impact usability gap. (Audit 07 §3.3, High.)

**Work:**
1. `src/SQLCraftFactory.php` — constructs a `DriverRegistry` pre-populated with the five built-in drivers, holds a `CredentialProviderInterface` and optional `EventDispatcherInterface`, and produces `DatabaseSession` instances from `ConnectionParameters` (or a credential key).
2. `src/DatabaseSession.php` — the per-connection handle exposing the service surface per doc 18 §3: `query()`, `schema(): SchemaManagerInterface`, `ddl(): DdlManager`, `security(): SecurityGuardInterface` (Phase 4), `export()`, `import()`. Wires the `CacheInvalidationListener` (Phase 1 §1.3) and the event dispatcher into the services it hands out.
3. `DatabaseSession::ddl()` is the sole advertised DDL execution path (Phase 1 §1.2 decision A).

**Acceptance:** a consumer writes `$session = $factory->session($params); $session->schema()->getTables();`
end to end with no manual service assembly. A smoke integration test exercises the full wiring
against SQLite.

---

## 3.3 `ConnectionManager`

**Problem:** doc 10 §5.2 defines `ConnectionManager` as the named-connection holder — the
multi-connection centerpiece (N connections to N engines), the counterpart to Adminer's single
global `$driver`. Absent, no contract. (Audit 02 finding 1, High.)

**Work:**
1. `src/Contracts/Connection/ConnectionManagerInterface.php` — `get(string $name)`, `add(string $name, ConnectionInterface)`, `closeAll()`.
2. `src/Connection/ConnectionManager.php` (final) implementing it.
3. `SQLCraftFactory` uses `ConnectionManager` as its primary connection handle so multiple named sessions coexist.

**Acceptance:** two named connections to different engines coexist under one manager; `closeAll()`
closes both. Unit test.

---

## 3.4 `CredentialProvider` subsystem

**Problem:** doc 10 §4 defines `CredentialProviderInterface`, the `Credential` readonly VO
(`#[\SensitiveParameter]` password), and three built-ins (`Array`, `Env`, `Callback`). None
exist; `ConnectionFactory` takes a bare `DriverInterface` and ignores credentials. (Audit 02
finding 2, High. `Credential` VO also Audit 07 §1.2.3 / 01 foundation.)

**Work:**
1. `src/ValueObjects/Credential.php` (Phase 2 may have stubbed the VO; finalize here) — `username`, `#[\SensitiveParameter] password`.
2. `src/Contracts/Connection/CredentialProviderInterface.php` — `resolve(string $key): Credential`.
3. `src/Connection/ArrayCredentialProvider.php`, `EnvCredentialProvider.php`, `CallbackCredentialProvider.php`.
4. Reconcile `ConnectionFactory` signature with doc 10 §5.1 (registry + credential provider + events), or document `PdoConnectionFactory` as the real design and update the plan. Inject the credential provider so `SQLCraftFactory` resolves secrets through it, never through raw strings.

**Acceptance:** `$factory->session('primary')` resolves credentials via the provider; password
never appears as a plain string parameter outside the `Credential` VO. Providers unit-tested.

---

## 3.5 `FormatRegistry`

**Problem:** doc 14 §7 defines a typed writer/reader registry (`registerWriter`, `getWriter`,
`getSupportedWriteFormats`). Absent — `Exporter` takes writers as constructor variadics, so
consumers can't query supported formats or add a writer without reconstructing `Exporter`.
`CsvSemicolonFormatWriter` exists but is unregisterable. (Audit 06 finding 8, Medium.)

**Work:**
1. `src/Export/FormatRegistry.php` — `registerWriter(FormatWriterInterface)`, `getWriter(string $format)`, `getSupportedWriteFormats(): array`; pre-register SQL/CSV/TSV/CSV-semicolon.
2. Change `Exporter` to accept a `FormatRegistry` (in addition to or instead of the variadic).
3. `SQLCraftFactory` builds the default registry so `DatabaseSession::export()` has all built-in formats.
4. (Reader-side `FormatReaderInterface` is Phase 6.)

**Acceptance:** `$registry->getSupportedWriteFormats()` returns the four built-ins including
the previously-orphaned semicolon writer; a consumer registers a custom writer without touching
`Exporter`'s constructor. Unit test.

---

## Phase 3 exit criteria

- `SQLCraftFactory` + `DatabaseSession` give a one-line path from params to working services.
- `ConnectionManager` supports multiple named connections.
- Credentials flow through `CredentialProviderInterface` + `Credential` VO, never raw strings.
- `FormatRegistry` is the export-format entry point; the orphan semicolon writer is reachable.
- Event dispatcher + cache-invalidation listener wired centrally in the factory.
- `make build`/`make test` green; a full SQLite smoke test exercises the composition root.
