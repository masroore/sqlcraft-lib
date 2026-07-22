# SQLCraft Planning — 23: Implementation Roadmap

> Status: **Planning phase. No code exists yet.**
> Last updated: 2026-07-20
> Scope: a phased implementation plan from empty repository to v1.0. Each milestone lists objective, deliverables, hard dependencies, acceptance criteria, risks/mitigations, whether it ships independently usable functionality, and a relative T-shirt size (S/M/L/XL). This document assumes the architecture in docs 00-19 (and 13/15/16 where present) is settled; it does not re-argue those decisions.

---

## Sizing Legend

S = a few days of focused work. M = one to two weeks. L = two to four weeks. XL = a month or more, typically because it spans multiple engines or has irreducible edge-case surface (ALTER TABLE). Sizes are relative to each other, not calendar commitments — no team size or velocity is assumed.

---

## M0 — Project Setup

**Objective:** Establish the repository, tooling, and CI pipeline with zero application source code. Prove the build/lint/analysis pipeline works before any domain code exists, so every subsequent milestone inherits a working safety net rather than bolting one on later.

**Key deliverables:**
- `composer.json` per `19-package-structure.md` §3 (PHP 8.4 floor, `ext-pdo` only runtime require, all PSR packages `suggest`-only)
- PSR-4 skeleton directories (`src/`, `tests/{Unit,Integration,Contract,Golden,Fixtures}`) with `.gitkeep` placeholders, no real classes
- `phpstan.neon.dist` at max level, `psalm.xml`, `.php-cs-fixer.dist.php`, `rector.php`, `infection.json.dist`, `deptrac.yaml` (dependency rules from `06-package-architecture.md` §4 encoded, even though there's nothing yet to violate them)
- `.github/workflows/ci.yml` (static analysis + unit test job, will pass trivially on an empty `src/`) and `.github/workflows/integration.yml` (Testcontainers matrix scaffold, jobs defined but marked `if: false` until M2)
- `tools/` isolated composer.json for Rector, per `19-package-structure.md` §6
- `LICENSE` (MIT), `README.md` stub, `.gitattributes` export-ignore rules

**Hard dependencies:** None. This is the first milestone.

**Acceptance criteria:**
- `composer install` succeeds on a clean PHP 8.4 environment
- `composer run ci` (stan + psalm + cs + deptrac + rector + test) exits 0 against the empty skeleton
- CI pipeline runs green on a trivial PR (e.g., adding a comment to README)
- `deptrac analyse` runs without error even though there are zero classes to check

**Risks & mitigations:** *Risk:* over-configuring tooling before real code exists reveals config mistakes only once code lands, causing rework. *Mitigation:* keep tool configs minimal and permissive at M0; tighten thresholds (e.g., Infection MSI minimums) incrementally starting at M2 once there is real code to measure.

**Ships independently usable functionality:** No — this is pure infrastructure.

**Size:** S

---

## M1 — Foundation

**Objective:** Build every piece of the domain model that requires no database connection at all: contracts, value objects, DTOs, collections, the exception hierarchy, and the capability system. Everything in this milestone is unit-testable with zero I/O, which is the whole point — it de-risks the riskiest *design* surface (are the VOs/DTOs shaped right?) before any adapter code depends on them.

**Key deliverables:**
- `SQLCraft\Contracts\*` — every interface named in `07-module-breakdown.md` §1 (`ConnectionInterface`, `DriverInterface`, `PlatformInterface` and its segregated sub-interfaces, `MetadataProviderInterface`, `DdlBuilderInterface`, `QueryBuilderInterface`, `ExecutorInterface`, `ImporterInterface`, `ExporterInterface`, `CapabilityResolverInterface`, `EventDispatcherInterface`, `SecurityGuardInterface`)
- `SQLCraft\ValueObjects\*` — `Identifier`, `QualifiedName`, `DataType`, `DefaultValue`, `ForeignKeyAction`, `TriggerTiming`, `TriggerEvent`, `RoutineDirection`, `IndexType`, `Charset`, `Collation`, `Engine`, `ServerVersion`, `ConnectionParameters`, `Privilege` (`05-domain-model.md` §3, `07-module-breakdown.md` §2)
- `SQLCraft\DTO\*` — `TableStatus`, `ColumnMeta`, `IndexMeta`, `ForeignKeyMeta`, `TriggerMeta`, `RoutineMeta`, `RoutineParameter`, `ViewMeta`, `SequenceMeta`, `DatabaseMeta`, `SchemaMeta`, `UserMeta`, `ServerInfo`, `PartitionInfo`, `BackwardKeyMeta`, `ProcessMeta` (`05-domain-model.md` §4, `07-module-breakdown.md` §3)
- `SQLCraft\Collections\*` — `AbstractImmutableCollection` + all typed collections (`07-module-breakdown.md` §4)
- `SQLCraft\Exceptions\*` — full hierarchy (`05-domain-model.md` §9)
- `SQLCraft\Capabilities\*` — `Capability` enum, `CapabilitySet`, `ExtendedCapability`, `CapabilityNotSupportedException` (`09-capability-model.md` §2-3, §10) — resolver logic itself is deferred to M3 since it needs `ServerVersion` predicates tied to real platforms, but the enum/set/exception trio is pure data and belongs here
- `SQLCraft\Support\*` — `StringUtil`, `TypeUtil`, `ArrayUtil`

**Hard dependencies:** M0 (tooling must exist to enforce quality on this code from day one).

**Acceptance criteria:**
- 100% of this milestone's classes have unit tests requiring no database, no filesystem, no network
- PHPStan max + Psalm pass with zero suppressions on all of `Contracts`, `ValueObjects`, `DTO`, `Collections`, `Exceptions`, `Capabilities`, `Support`
- `deptrac analyse` passes — no class in this milestone imports anything outside the dependency rules in `06-package-architecture.md` §4 (in particular: `Contracts` depends on nothing; `ValueObjects` depends only on `Support`)
- Property-based tests (per `07-module-breakdown.md` §2) validate VO invariants (e.g., `Identifier` rejects empty string and null byte)
- Infection mutation score baseline established (target ≥80% MSI on this milestone's code, per `19-package-structure.md` §3 `infection` script)

**Risks & mitigations:** *Risk:* DTO/VO shapes designed in `05-domain-model.md` turn out to be missing a field once real per-engine hydration is attempted in M4, forcing a breaking change to types application code (M2/M3) already depends on. *Mitigation:* treat every class in this milestone as `@internal`-stable-but-not-BC-frozen until M4 proves the shapes against all four initial platforms; do not tag anything here as public/SemVer-covered until M4 exit.

**Ships independently usable functionality:** No — these are building blocks with no behavior of their own, but the milestone is independently *testable* and *reviewable*, which is valuable in itself for a design this type-heavy.

**Size:** L

---

## M2 — Connection Layer

**Objective:** Prove the hexagonal boundary at its narrowest point: wrap PDO so that nothing above this layer ever sees a `\PDO` or `\PDOStatement` instance. Validate against SQLite only — SQLite requires no external service, keeping this milestone's CI fast and dependency-free, and it is a real, spec-compliant SQL engine (not a mock), so the integration tests exercise genuine PDO behavior.

**Key deliverables:**
- `SQLCraft\Connection\{ConnectionInterface, PdoConnection, ConnectionFactory, ConnectionPool, TransactionManager, StatementResult, QueryLogger}` (`07-module-breakdown.md` §5)
- `ResultInterface` with both streaming (generator-backed) and buffered modes (`12-query-engine.md` §3)
- `CredentialProvider` interface + a basic static implementation
- `PdoExceptionTranslator` — maps raw `\PDOException`/SQLSTATE codes to the typed exception hierarchy from M1 (`ConnectionFailedException`, `SyntaxErrorException`, `UniqueConstraintException`, `DeadlockException`, etc.)
- `TransactionManagerInterface` with savepoint-based nesting (`12-query-engine.md` §5) — implemented and tested against SQLite's savepoint support specifically, since SQLite's transaction model (`DEFERRED`/`IMMEDIATE`/`EXCLUSIVE`) is distinct enough from the other five engines to catch abstraction leaks early

**Hard dependencies:** M1 (needs `ConnectionParameters` VO, exception hierarchy, `ResultInterface` contract).

**Acceptance criteria:**
- `SqliteDriver` + `SqlitePlatform` stub (minimal — just enough to unblock `ConnectionFactory`, full `SqlitePlatform` is M3's job) can open a connection, execute a statement, and return results, both streamed and buffered
- Integration test suite runs against a real (file-based and in-memory) SQLite database, no mocking of PDO itself
- No test or production code path constructs a `\PDO` object outside `PdoConnection`/`ConnectionFactory` — enforced by `deptrac`
- `TransactionManager::transactional()` correctly nests via savepoints; a test proves a nested `transactional()` call inside another does not attempt a real nested `BEGIN`
- Every raw `\PDOException` thrown by the SQLite driver in a deliberately-triggered failure (bad SQL, unique violation, missing table) surfaces as the correct typed SQLCraft exception, never as `\PDOException` itself, at the public boundary

**Risks & mitigations:** *Risk:* designing the exception-translation map against SQLite only may miss SQLSTATE/error-code shapes that MySQL/PostgreSQL/MSSQL use differently, requiring rework in M3/M8. *Mitigation:* explicitly scope this milestone's exception map to "the classification logic is per-platform and pluggable" (each `Platform` supplies its own SQLSTATE→exception map, per `18-public-api.md` §6) rather than hardcoding a SQLite-shaped map into the shared `Connection` layer — SQLite validates the *mechanism*, not the final per-engine mapping table.

**Ships independently usable functionality:** Yes, narrowly — a consumer could use SQLCraft against SQLite alone for basic connect/execute/transact workflows after this milestone, though without schema introspection or DDL.

**Size:** M

---

## M3 — Platform & Driver Core

**Objective:** Build the full `PlatformInterface` contract and its first four concrete implementations (MySQL, MariaDB, PostgreSQL, SQLite), establish the conformance test suite that will keep all current and future platforms honest, and finish the capability resolver that M1 deferred.

**Key deliverables:**
- `PlatformInterface` (composite of `QuotingInterface`/`PaginationInterface`/`TypeMapperInterface`/`DdlDialectInterface`(contract only, full builder integration is M5)/`IntrospectionDialectInterface`) + `AbstractPlatform` template-method base (`08-driver-architecture.md` §3-4)
- `MySQLPlatform`, `MariaDbPlatform extends MySQLPlatform`, `PostgreSQLPlatform`, `SqlitePlatform` (full, superseding M2's stub) — quoting, pagination, type-mapping, `buildCapabilityMatrix()` per platform (`08-driver-architecture.md` §5-7, §9)
- `PlatformCapabilityResolver` (`09-capability-model.md` §4) wired to real `ServerVersion` detection against live connections
- `DriverRegistry` + `MySQLDriver`, `PostgreSQLDriver`, `SqliteDriver` (`08-driver-architecture.md` §8, `07-module-breakdown.md` §6)
- **Conformance test suite** (`tests/Contract/`, per `19-package-structure.md` §2): a shared PHPUnit test base that every `PlatformInterface` implementation must pass — asserts quoting round-trips, pagination SQL is syntactically valid on the real engine, capability matrix entries match `09-capability-model.md` §6's authoritative table

**Hard dependencies:** M2 complete (needs a working `Connection`/`ConnectionFactory` to open real connections against each engine for conformance testing).

**Acceptance criteria:**
- All four platforms pass the full conformance suite against live Testcontainers instances (MySQL 8.x, MariaDB 10.x/11.x, PostgreSQL 16.x, SQLite in-process)
- `MariaDbPlatform`'s flavor-flag branching is isolated to `buildCapabilityMatrix()`/version-gate predicates only — a conformance test specifically asserts no `getFlavor() ===` branch exists in any method other than capability resolution (grep-based or a custom PHPStan rule per `08-driver-architecture.md` §6)
- A capability-matrix regression test compares each platform's `buildCapabilityMatrix()` output against the `09-capability-model.md` §6 table verbatim, failing CI if they drift silently
- `$registry->register()` + `get()` demonstrated with a throwaway fifth "fake" driver in a test, proving third-party extensibility without core changes (`08-driver-architecture.md` §9's DuckDB walkthrough, exercised as a test double rather than a real DuckDB dependency)

**Risks & mitigations:** *Risk:* the conformance suite is under-specified at design time and turns out to be either too strict (fails valid platform-specific behavior) or too loose (passes broken platforms). *Mitigation:* build the conformance suite incrementally alongside the first platform (`SqlitePlatform`, already partially proven in M2) and treat MySQL/PostgreSQL as the suite's real stress test — if the suite needs escape hatches for legitimate per-engine variance, add them as explicit `@requires-capability` skip annotations rather than weakening assertions globally.

**Ships independently usable functionality:** Yes — connect, quote, and paginate correctly against four real engines, though still no schema introspection or DDL.

**Size:** XL (four platforms, each with real quoting/pagination/type edge cases, plus building the conformance harness itself for the first time)

---

## M4 — Schema Introspection

**Objective:** Deliver the read side of the whole library: every inspector service returning typed DTOs for the four M3 platforms, aggregated behind `SchemaManager`.

**Key deliverables:**
- `ServerInspector`, `TableInspector`, `ColumnInspector`, `IndexInspector`, `ForeignKeyInspector`, `ViewInspector`, `RoutineInspector`, `TriggerInspector`, `SequenceInspector`, `CheckConstraintInspector`, `UserInspector` (prompt brief; `07-module-breakdown.md` §8's `MetadataService` is the aggregation point these compose into)
- `MetadataFactoryInterface` implementations per platform (`MySQLMetadataFactory`, `PostgreSQLMetadataFactory`, `SqliteMetadataFactory`; MariaDB reuses/extends MySQL's per `05-domain-model.md` §8)
- `SchemaManager` facade (`18-public-api.md` §2.2, §5) — `listDatabases()`, `listTables()`, `describeTable()` (batched, single round-trip-set per `18-public-api.md` §3.3), `listSchemas()`, `describeView()`, `describeRoutine()`
- Metadata caching seam: `MetadataCacheInterface` (PSR-16-shaped) defined and wired as an optional constructor dependency, with cache invalidation triggered by `SchemaChangedEvent`/`AfterDdlExecuted` — concrete cache *implementation* is optional/deferred, but the seam must exist now so DDL (M5) doesn't have to retrofit invalidation later

**Hard dependencies:** M3 complete (every inspector needs `IntrospectionDialectInterface` SQL per platform and the capability gates from the resolver).

**Acceptance criteria:**
- `describeTable()` against all four platforms returns a `TableStructure` DTO with columns/indexes/foreignKeys/triggers/status populated correctly, verified against hand-built fixture schemas per engine (`tests/Fixtures/`)
- Capability-gated inspectors (e.g., `SequenceInspector` on MySQL, which lacks sequences) throw `CapabilityNotSupportedException` rather than returning an empty/wrong result silently
- `allFields()`-equivalent (cross-table column enumeration, `04-feature-inventory.md` §19) is implemented as a single batched query per engine, not N+1 per-table calls — verified by a query-count assertion in the integration test
- Golden-file tests (`tests/Golden/`) snapshot the introspection SQL generated per platform, catching accidental dialect drift in future changes

**Risks & mitigations:** *Risk:* per-engine `INFORMATION_SCHEMA`/system-catalog quirks (documented as open questions in `24-open-questions.md` §2 — e.g., MariaDB-specific metadata gaps) surface only once real fixture schemas are introspected, forcing DTO field additions that ripple back into M1's "stable" shapes. *Mitigation:* this is exactly why M1's types were not tagged BC-frozen (see M1 risk note) — budget explicit rework time in this milestone for DTO adjustments discovered here, and only freeze the public API surface (`18-public-api.md` §7) at M10.

**Ships independently usable functionality:** Yes — full schema introspection/reporting tooling is usable standalone after this milestone (e.g., a "describe my database" CLI is buildable here).

**Size:** L

---

## M5 — DDL Services

**Objective:** Deliver the write side of schema management: every DDL builder, rendering per platform, with SQLite's table-recreation strategy as the hardest proof point.

**Key deliverables:**
- `DdlDialectInterface` full method set (`13-ddl-services.md` §3) implemented on all four M3/M4 platforms
- All builder VOs: `CreateTableBuilder`, `AlterTableBuilder`, `DropTableBuilder`, `CreateIndexBuilder`, `DropIndexBuilder`, `TruncateBuilder`, `CreateViewBuilder`, `DropViewBuilder`, `CreateTriggerBuilder`, `DropTriggerBuilder`, `CreateRoutineBuilder`, `DropRoutineBuilder`, `CreateSequenceBuilder`, `DropSequenceBuilder`, `CreateDatabaseBuilder`, `DropDatabaseBuilder`, `CreateSchemaBuilder`, `DropSchemaBuilder`, `UseDatabaseBuilder` (`13-ddl-services.md` §2.3)
- `TableRecreationStrategy` for SQLite (`13-ddl-services.md` §5 forward reference) — the four-plus-statement CREATE-copy-drop-rename sequence for ALTER operations SQLite cannot express natively
- `DdlManager` facade (`18-public-api.md` §2.2) wiring builders to `QueryExecutor::executeDdl()`, firing `BeforeDdlExecuted`/`AfterDdlExecuted` (stub events acceptable here if M9 hasn't landed the full event catalog yet — see cross-milestone note below)

**Hard dependencies:** M4 complete (DDL builders consume the same `ColumnMeta`/`IndexMeta`/`ForeignKeyMeta` DTOs that introspection produces, and `AlterTableBuilder` needs the "original" column/index state from introspection to compute a diff-shaped MODIFY COLUMN statement).

**Acceptance criteria:**
- `CreateTableBuilder`, executed against all four platforms, produces a table that `describeTable()` (M4) can then re-introspect — this is a weaker but concretely testable substitute for full round-trip DDL↔introspection equivalence (see `25-final-review.md` for why full round-trip is not guaranteed)
- SQLite `AlterTableBuilder` (add column, drop column, rename column) is verified end-to-end against a real SQLite file, including the multi-statement recreation path for operations SQLite's native `ALTER TABLE` cannot express
- Every builder's `toSql()` is unit-testable against a *mocked* `DdlDialectInterface` with zero live connection, proving the intent/rendering separation actually holds in practice, not just on paper
- Capability-gated builders (`CreateSequenceBuilder` on MySQL, `CreateSchemaBuilder` on SQLite) throw at `execute()`-time via the same `CapabilitySet::require()` pattern as M4's inspectors

**Risks & mitigations:** *Risk:* ALTER TABLE has 50+ real per-engine edge cases (see `25-final-review.md` §2) that this milestone cannot fully enumerate up front. *Mitigation:* scope M5's acceptance criteria to the common ALTER operations (add/drop/modify/rename column, add/drop index/FK/check constraint) explicitly, and track exotic cases (multi-column type coercion chains, partition-aware ALTER, MySQL's `ALGORITHM=INSTANT` hints) as post-M5 follow-up issues rather than blocking the milestone on 100% ALTER coverage.

**Ships independently usable functionality:** Yes — full schema-management tooling (create/alter/drop everything) is usable standalone after this milestone for the four M3/M4 platforms.

**Size:** XL (SQLite recreation alone is a significant sub-project; ALTER TABLE is acknowledged as the hardest single operation across the whole roadmap)

---

## M6 — Query Engine

**Objective:** Deliver the data-browsing and raw-execution surface: streaming/buffered results, pagination, the `SelectQuery` builder, EXPLAIN, and query history — the layer a CLI tool, AI agent, or admin-UI backend actually spends most of its time calling.

**Key deliverables:**
- `QueryExecutor` (`execute()`, `query()`, `executeDdl()`, `queryWithTimeout()` — `12-query-engine.md` §2, §10)
- `StatementSplitterInterface` + `BatchExecutor` for multi-statement/DELIMITER handling (`12-query-engine.md` §4)
- `SelectQuery` VO + `SelectQueryRenderer` + `WhereCondition`/`OrderByClause`/`ColumnSelection` with operator allowlisting (`12-query-engine.md` §7, `15-security.md` §5.1)
- `Paginator` + `PaginationParams` + `Page` DTO, with the approximate-count strategy from `TableStatus::$rows` (`12-query-engine.md` §6)
- `ExplainServiceInterface` (`12-query-engine.md` §8) and `WarningsProviderInterface` (`12-query-engine.md` §9)
- `QueryHistoryInterface` + `NullQueryHistory`/`InMemoryQueryHistory`/`CallbackQueryHistory` (`12-query-engine.md` §11)
- `QueryManager` facade (`18-public-api.md` §2.2)

**Hard dependencies:** M2 complete (needs `ConnectionInterface`/`ResultInterface`/`TransactionManager`). M4 is useful but not blocking — `Paginator`'s approximate-count optimization degrades gracefully to always-exact-COUNT if `TableStatus` isn't wired yet, so M6 can proceed in parallel with M4/M5 if team capacity allows.

**Acceptance criteria:**
- Streaming (`buffered: false`, the default) is proven to hold constant memory against a multi-million-row SQLite fixture — a memory-usage assertion in the integration test, not just a functional pass/fail
- `SelectQueryRenderer` output is verified never to interpolate a `WhereCondition::$value` into the SQL string — a static/regex-based test scans generated SQL for suspicious literal-looking values where a `?`/named placeholder is expected
- `BatchExecutor` correctly handles a multi-statement script with a `DELIMITER $$` block (routine body containing semicolons) against MySQL, matching Adminer's documented behavior
- `TransactionManager::transactional()` (built in M2) is exercised through `QueryExecutor` end-to-end, including a deliberately-thrown-exception rollback test
- `Paginator` against a table with no WHERE clause uses the approximate `TableStatus::$rows` count when available and sets `totalApprox = true`, verified by assertion, not just documented behavior

**Risks & mitigations:** *Risk:* the streaming-by-default API shape may prove awkward for consumers who expect arrays (this is flagged as an open design question in `24-open-questions.md` §1 and a named weakness in `25-final-review.md` §2). *Mitigation:* ship the `buffered: true` escape hatch from day one of this milestone (already specified, not deferred) so the ergonomics question can be resolved by real usage feedback during M6-M10 rather than blocking M6's completion on getting the DX perfectly right up front.

**Ships independently usable functionality:** Yes — this milestone alone (plus M2) is enough to build a read-heavy CLI/reporting tool.

**Size:** L

---

## M7 — Import/Export

**Objective:** Deliver streaming import and export with pluggable formats, built on the DDL (M5) and Query (M6) layers rather than reimplementing statement execution.

**Key deliverables:**
- `Exporter` + `FormatWriterInterface` implementations for SQL, CSV, TSV (JSON/XML deferred per the open question in `24-open-questions.md` §4 on whether they ship in v1 or as a plugin — default assumption for this roadmap: SQL/CSV/TSV ship in M7, JSON/XML are stretch goals of the same milestone, not a hard requirement)
- `Importer` using `StatementSplitterInterface` (built in M6) + `BatchExecutor` (built in M6), adding CSV/TSV-specific type-coercion-on-import logic
- Progress events: `ImportStartedEvent`/`ImportProgressEvent`/`ImportFinishedEvent`/`ImportFailedEvent`, `ExportStartedEvent`/`ExportProgressEvent`/`ExportFinishedEvent` (`16-events.md` §5.5) — this is the first milestone that requires the event *classes* to exist even if M9 hasn't finished wiring the full dispatcher story; a minimal no-op-safe event emission path is acceptable here, hardened in M9
- Output compression wrapping (gzip; bzip2 as a stretch goal, `04-feature-inventory.md` §17)

**Hard dependencies:** M5 complete (export of triggers/routines/events needs DDL builders to render their definitions faithfully) and M6 complete (import executes via `BatchExecutor`; export of data rows uses the streaming `QueryExecutor::query()`).

**Acceptance criteria:**
- Exporting and then importing the same table (structure + data) round-trips to an equivalent table on the same engine — verified for all four M3 platforms
- CSV export/import round-trips column values correctly for at least: integers, strings with embedded delimiters/quotes, NULL, and binary/BLOB columns (via the platform's `quoteBinary()`/PDO binary binding path)
- A large-file import (constructed fixture, tens of thousands of statements) completes without loading the whole file into memory — measured, not assumed
- `ImportProgressEvent` fires at a configurable statement/byte interval, verified by a listener-counting test

**Risks & mitigations:** *Risk:* CSV/TSV type-coercion rules (`04-feature-inventory.md` §16 flags this as needing "documented per-column" rules) are underspecified — ambiguous cases (empty string vs NULL, date format variance) could produce silently wrong imports. *Mitigation:* ship a conservative, explicit coercion policy (empty CSV field → NULL only if the column is nullable, otherwise empty string; dates parsed via `DateTimeImmutable` with a documented format list, reject/error on ambiguous input rather than guessing) and document it prominently rather than trying to auto-detect intent.

**Ships independently usable functionality:** Yes — a full backup/restore and data-migration tool is usable standalone after this milestone.

**Size:** L

---

## M8 — Remaining Platform: MSSQL (Oracle deferred)

**Objective:** Extend the M3 platform work to Microsoft SQL Server. Oracle is explicitly deferred from v1.0 because its driver/platform and CI support were never implemented.

**Key deliverables:**
- `SqlServerPlatform` (full: `[bracket]` quoting, `TOP`/`OFFSET FETCH` pagination, schemas, view triggers, sequences since 2012) + `SqlServerDriver`
- The SQL Server platform passes the full M3 conformance suite
- SQL Server inspectors (M4) and DDL builders (M5) are implemented and fixture-tested
- Coverage-matrix cross-check against `04-feature-inventory.md` §"Coverage Matrix" — every SQL Server `F`/`P`/`A` cell is validated against real behavior

**Hard dependencies:** M3 partial (the `PlatformInterface` contract and conformance suite must exist). M5 and M4 builder/inspector patterns must be stable before extending SQL Server. Oracle is not part of the v1 dependency graph.

**Acceptance criteria:**
- Testcontainers-based integration tests for MSSQL pass in CI; Oracle remains outside the v1 CI matrix
- `pdo_sqlsrv`/`pdo_dblib` extension availability is documented per supported OS/PHP-build combination
- All five v1 engines pass identical consumer-facing workflow tests from `18-public-api.md` §3 (the "engine-swap guarantee" demonstrated concretely, §3.11)

**Risks & mitigations:** SQL Server CI remains the main infrastructure risk because the required PDO extension and container image are platform-specific. Oracle is deferred to a future milestone with its own driver and CI feasibility decision.

**Ships independently usable functionality:** Yes — this milestone completes the v1 SQL Server extension. It does not include Oracle.

**Size:** L (SQL Server dialect and infrastructure work; Oracle deferred)

---

## M9 — Security & Events

**Objective:** Thread the full PSR-14 event catalog and the complete security/validation layer through every service built in M2-M8, closing the gap between "events and security are designed" (docs 15-16) and "every code path actually emits/enforces them."

**Key deliverables:**
- Full 27-event catalog wired at every emission point identified in `16-events.md` §5 (connection, query execution, transactions, schema/metadata, import/export, capability) — auditing M2-M8's code to confirm every documented event actually fires where documented, not just where convenient
- `SimpleEventDispatcher` + `SimpleListenerProvider` (`16-events.md` §2, §6.2) as the zero-dependency fallback dispatcher
- `IdentifierQuoter`, `OperatorValidator`, and the full input-validation/allowlisting layer (`15-security.md` §2, §5) verified against every user-input entry point across all services built so far — this is a security *audit* milestone as much as a build milestone
- Credential redaction (`#[\SensitiveParameter]`, exception-message policy per `15-security.md` §7-8) verified across the full exception hierarchy
- DoS/resource-limit defaults (row caps, statement-count caps, `Paginator::$maxLimit`) wired and confirmed configurable per `15-security.md` §11

**Hard dependencies:** M6 complete (query execution is the largest event-emission surface) and M7 complete (import/export events are the second-largest).

**Acceptance criteria:**
- A dedicated audit pass confirms every event in `16-events.md`'s full catalog table is dispatched from the documented emission point — tracked as a checklist, not just "seems to work"
- A security-focused test suite specifically attempts injection via every attack surface enumerated in `15-security.md` §6.1 (column values, table/column names, WHERE operators, ORDER BY direction, LIMIT/OFFSET, DDL type names, aggregate function names, schema/database names, routine names) and confirms each is blocked at construction time, not execution time
- `BeforeQueryExecuted`'s `replaceSql()` interception path is stress-tested with a tenant-scoping extension example (per `16-events.md` §5.2's stated use case) to confirm the mutation contract is sound in practice
- Credential values never appear in any log line or exception message across a full-suite grep-based test sweep

**Risks & mitigations:** *Risk:* this milestone discovers that some event documented in `16-events.md` was never actually wired into the M2-M8 implementation, or wired inconsistently (e.g., fired on success but not on the equivalent failure path). *Mitigation:* this is precisely why M9 exists as a dedicated milestone rather than assuming "events are designed in doc 16, therefore implemented" — treat every gap found here as an M9 deliverable (fix the wiring), not a deferred followup, since security/observability gaps are the class of bug most costly to discover post-release.

**Ships independently usable functionality:** No new consumer-facing functionality — this milestone hardens and completes cross-cutting concerns across everything already shipped.

**Size:** L

---

## M10 — Documentation & v1.0

**Objective:** Freeze the public API, ship consumer-facing documentation and framework integration examples, and cut the v1.0 tag.

**Key deliverables:**
- `examples/` — all eight numbered example directories from `19-package-structure.md` §10, each independently runnable
- `README.md` full rewrite (currently a stub from M0) covering install, quickstart, and links to `docs/plans/` for architecture depth
- API documentation generation (PHPDoc-driven; tool choice — e.g., phpDocumentor — is an open question tracked in `24-open-questions.md`)
- Laravel and Symfony integration examples fleshed out from the sketches in `18-public-api.md` §8.3-8.4 into real, runnable mini-apps
- Public API surface audit: every class/method tagged `@internal` vs public per `18-public-api.md` §7 is reviewed one final time before the SemVer promise (`18-public-api.md` §10) takes effect
- `CHANGELOG.md` populated retroactively for the full M0-M9 history
- v1.0.0 annotated git tag, Packagist publish

**Hard dependencies:** All prior milestones (M0-M9) complete.

**Acceptance criteria:**
- Every example in `examples/` runs against a real (Testcontainers or in-memory) database with zero manual setup beyond `composer install`
- A fresh developer (ideally someone not on the core team) can follow the README quickstart to a working connection + first query in under 10 minutes — measured via a documentation-usability dry run, not assumed
- `composer run ci` is green, `infection --min-msi=80 --min-covered-msi=90` passes (the real threshold enforced from here forward, per `19-package-structure.md` §3)
- No class is both untagged `@internal` and missing from the public-API table in `18-public-api.md` §7 — the audit closes every gap, one direction or the other
- The v1.0.0 tag's `CHANGELOG.md` heading matches what `release.yml` validates (`19-package-structure.md` §7)

**Risks & mitigations:** *Risk:* freezing the public API surface at this point locks in any ergonomics mistakes discovered too late (e.g., the streaming-by-default question from M6, or the `DatabaseSession` aggregate's shape) for a full major-version cycle. *Mitigation:* treat M10 as a hard gate with an explicit "last chance to break something" review pass across `18-public-api.md`'s entire public-vs-internal table before tagging — this is the intended purpose of keeping the API surface fluid through M1-M9 rather than freezing early, and is the reason `19-package-structure.md` §7's `@internal` enforcement tooling exists.

**Ships independently usable functionality:** Yes — this is the v1.0 release itself.

**Size:** M

---

## Dependency Graph (ASCII)

```
 M0 (setup)
  │
  ▼
 M1 (foundation: contracts/VOs/DTOs/collections/capabilities-data)
  │
  ▼
 M2 (connection layer — SQLite only) ──────────────┐
  │                                                 │
  ▼                                                 │
 M3 (platform+driver core — MySQL/MariaDB/PgSQL/SQLite)   │
  │                                                 │
  ▼                                                 │
 M4 (schema introspection)                          │
  │                                                 │
  ▼                                                 │
 M5 (DDL services) ◄────────────────────────────────┘
  │                                    (M6 also only needs M2;
  │                                     can run in parallel with M3-M5)
  ▼                                          │
 M7 (import/export) ◄────────── M6 (query engine)
  │                                   ▲
  │                                   │
  └──────────────┬────────────────────┘
                 ▼
          M9 (security & events)
                 │
                 ▼
          M8 (remaining platform: MSSQL; Oracle deferred)
          [can start once M3 contract is stable —
           drawn after M9 here only for layout; in
           practice M8 may run concurrently with M6/M7/M9
           once M3-M5's patterns are proven on 4 engines]
                 │
                 ▼
          M10 (docs & v1.0 freeze)
```

**Reading notes on parallelism:** M6 (Query Engine) has a hard dependency only on M2, not on M3/M4/M5 — a team with enough capacity could run M3-M5 and M6 concurrently after M2 lands, converging at M7 (which needs both). M8 (remaining platforms) has a hard dependency only on M3's *contract* being stable, not on M3 being fully "done" in the sizing sense — in practice M8 could start as soon as M4/M5's patterns are proven against the first four engines, running concurrently with M6/M7/M9, and this is the recommended real-world sequencing despite the linear layout above (drawn linearly for readability, not to imply serialization is mandatory). M9 depends on M6 and M7 being complete because it audits their event-emission surfaces — it cannot meaningfully start earlier. M10 is a hard convergence point: every other milestone must be done first.


### DDL scope deferrals

Rename database, move table, alter trigger, scheduled events, and user-defined types remain explicitly deferred to a future version.
