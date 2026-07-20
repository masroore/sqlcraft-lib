# SQLCraft Planning — 04: Feature Inventory

> Status: **Planning phase. No code exists yet.**
> Last updated: 2026-07-20
> Exhaustive inventory of every Adminer capability, mapped to the SQLCraft module that owns it, the `Capability` enum value that gates it, and per-engine variance notes. Nothing from the operation surface in the master brief is omitted.

Legend for the coverage matrix at the end: **F** = Full support, **P** = Partial support (engine-specific caveat noted), **A** = Absent (engine has no equivalent concept).

---

## 1. Server / Connection

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| Connect via DSN/credentials | Connection | *(none — baseline)* | `PdoConnection::fromDsn()`; all engines |
| Server info / version | Metadata | *(none — baseline)* | `MetadataService::serverInfo()` |
| Server variables (show/set) | Metadata / DDL | `Capability::ServerVariables` | MySQL/MariaDB `SHOW VARIABLES`; PG `SHOW ALL`; MSSQL `sp_configure`; SQLite N/A |
| Process list | Execution | `Capability::ProcessList` | MySQL/MariaDB/PG/MSSQL yes; SQLite/Oracle differ |
| Kill process | Execution | `Capability::KillProcess` | MySQL `KILL`, PG `pg_terminate_backend()`, MSSQL `KILL`; SQLite absent |
| Query timeout enforcement | Execution | `Capability::QueryTimeout` | Driver-level statement timeout where supported |
| SSL connection options | Connection | *(none — connection config)* | PDO SSL attributes per driver |
| Permanent/remembered login | *(explicitly out of scope)* | — | Session/credential storage is an application concern, not SQLCraft's |

## 2. Databases & Schemas/Namespaces

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| List databases | Metadata | *(none — baseline)* | `MetadataService::listDatabases()` |
| Create database | DDL | `Capability::DatabaseManagement` | Charset/collation options vary |
| Alter database (charset/collation) | DDL | `Capability::DatabaseManagement` | MySQL/MariaDB yes; PG limited; SQLite N/A (file-based) |
| Drop database | DDL | `Capability::DatabaseManagement` | — |
| Rename database | DDL | `Capability::DatabaseRename` | MySQL: no direct rename (dump/recreate pattern); PG: `ALTER DATABASE RENAME` |
| List schemas/namespaces | Metadata | `Capability::Schemas` | PG `information_schema.schemata`, MSSQL schemas; MySQL/MariaDB/SQLite have no separate namespace layer (schema == database) |
| Create/alter/drop schema | DDL | `Capability::Schemas` | PG `CREATE SCHEMA`; MSSQL `CREATE SCHEMA`; absent elsewhere |
| List collations | Metadata | `Capability::Collations` | MySQL/MariaDB rich; PG uses OS collations; SQLite minimal; MSSQL yes |
| List charsets | Metadata | `Capability::Charsets` | MySQL/MariaDB per-column; PG database-level encoding; SQLite N/A |

## 3. Tables

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| List tables | Metadata | *(none — baseline)* | `MetadataService::listTables()` |
| Table status (rows, size, engine, collation) | Metadata | *(none — baseline)* | `TableStatus` VO; `Engine` key absent for PG/SQLite/MSSQL/Oracle |
| Create table | DDL | *(none — baseline)* | `DDLService::createTable()` |
| Alter table (rename, comment) | DDL | *(none — baseline)* | — |
| Drop table | DDL | *(none — baseline)* | — |
| Copy table | DDL | `Capability::TableCopy` | `CREATE TABLE ... AS SELECT` pattern varies; MSSQL `SELECT INTO` |
| Move table (between DBs/schemas) | DDL | `Capability::TableMove` | MySQL `RENAME TABLE db1.t TO db2.t`; PG requires dump/restore across DBs, only cross-schema move is native |
| Truncate table | DDL | *(none — baseline)* | — |
| Analyze table | Execution | `Capability::TableAnalyze` | MySQL/MariaDB `ANALYZE TABLE`; PG `ANALYZE`; SQLite `ANALYZE`; MSSQL stats update; Oracle `DBMS_STATS` |
| Optimize table | Execution | `Capability::TableOptimize` | MySQL/MariaDB `OPTIMIZE TABLE`; others absent or different (PG `VACUUM FULL`) |
| Check table | Execution | `Capability::TableCheck` | MySQL/MariaDB `CHECK TABLE`; SQLite `PRAGMA integrity_check`; others absent |
| Repair table | Execution | `Capability::TableRepair` | MyISAM-era MySQL feature; absent on InnoDB-only, PG, SQLite, MSSQL, Oracle |
| Vacuum table | Execution | `Capability::Vacuum` | PostgreSQL-specific (`VACUUM`); SQLite has `VACUUM` too (whole-DB) |
| Table comment | DDL | `Capability::TableComment` | MySQL/MariaDB/PG yes; SQLite no native comment; MSSQL via extended properties; Oracle `COMMENT ON TABLE` |
| Table engine selection | DDL | `Capability::TableEngines` | MySQL/MariaDB only (InnoDB/MyISAM/etc.); all others absent |
| Partitioning | DDL/Metadata | `Capability::Partitioning` | MySQL/MariaDB/PG/Oracle support various partition types; SQLite/MSSQL(Standard edition) limited or absent |
| Table inheritance | Metadata | `Capability::TableInheritance` | PostgreSQL-specific (`INHERITS`) |

## 4. Columns

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| List columns/fields | Metadata | *(none — baseline)* | `Field` → `ColumnDefinition` VO |
| Add column | DDL | *(none — baseline)* | — |
| Modify column (type/length/nullability) | DDL | *(none — baseline)* | Some engines require full rebuild (SQLite pre-3.35 limited `ALTER TABLE`) |
| Drop column | DDL | `Capability::DropColumn` | SQLite historically required table-rebuild workaround; 3.35+ supports `DROP COLUMN` natively |
| Reorder column | DDL | `Capability::ColumnReorder` | MySQL/MariaDB `AFTER`/`FIRST`; PG/SQLite/MSSQL/Oracle have no native reorder (column order is physical/immutable) |
| Column default value | DDL | *(none — baseline)* | Expression defaults vary by engine |
| Auto-increment / identity | DDL | `Capability::AutoIncrement` | MySQL `AUTO_INCREMENT`; PG `SERIAL`/`GENERATED ... AS IDENTITY`; SQLite `AUTOINCREMENT`; MSSQL `IDENTITY`; Oracle `GENERATED ... AS IDENTITY` (12c+) or sequence+trigger |
| Column comment | DDL | `Capability::ColumnComments` | MySQL/MariaDB/PG/Oracle yes; SQLite no; MSSQL via extended properties |
| Generated/computed columns | DDL | `Capability::GeneratedColumns` | MySQL/MariaDB/PG(12+)/MSSQL/Oracle support `STORED`/`VIRTUAL`; SQLite supports generated columns (3.31+) |
| ENUM/SET types | Metadata/DDL | `Capability::EnumSetTypes` | MySQL/MariaDB native `ENUM`/`SET`; PG via custom types (`CREATE TYPE ... AS ENUM`); SQLite/MSSQL/Oracle absent (emulated via CHECK) |
| Column-level privileges | Security | `Capability::ColumnPrivileges` | MySQL/MariaDB/PG/MSSQL support column-level GRANT; SQLite has no privilege model |
| `ON UPDATE` clause (e.g. timestamp auto-update) | DDL | `Capability::OnUpdateClause` | MySQL/MariaDB `ON UPDATE CURRENT_TIMESTAMP`; others require triggers |

## 5. Indexes

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| List indexes | Metadata | *(none — baseline)* | `Index` → `IndexDefinition` VO |
| Create/drop index | DDL | *(none — baseline)* | — |
| Primary key index | DDL | *(none — baseline)* | — |
| Unique index | DDL | *(none — baseline)* | — |
| Fulltext index | DDL | `Capability::FullTextIndex` | MySQL/MariaDB InnoDB/MyISAM; PG via `tsvector`+GIN (different mechanism); MSSQL native fulltext catalogs; SQLite via FTS3/4/5 virtual tables; Oracle Text |
| Spatial index | DDL | `Capability::SpatialIndex` | MySQL/MariaDB `SPATIAL`; PG via PostGIS extension (not core); MSSQL native spatial types; SQLite via SpatiaLite extension; Oracle Spatial |
| Vector index | DDL | `Capability::VectorIndex` | Emerging: PG `pgvector` extension, MySQL 9.x `VECTOR`; not yet standard on MSSQL/Oracle/SQLite core |
| Index algorithm selection (BTREE/HASH) | DDL | `Capability::IndexAlgorithms` | MySQL/MariaDB explicit `USING`; PG `USING btree/hash/gin/gist/spgist/brin`; SQLite/MSSQL/Oracle mostly fixed B-tree |
| Descending index columns | DDL | `Capability::DescendingIndexes` | PG/MSSQL/Oracle/SQLite(3.30+)/MySQL(8.0+) support `DESC` per column; MariaDB support varies by version |
| Partial indexes | DDL | `Capability::PartialIndexes` | PG (`WHERE` clause) and SQLite native; MySQL/MariaDB/MSSQL/Oracle absent (MSSQL has "filtered indexes" as a near-equivalent, tracked separately) |
| Index key prefix length | DDL | `Capability::IndexPrefixLength` | MySQL/MariaDB (`col(10)`); others use full-column or functional indexes instead |

## 6. Foreign Keys

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| List foreign keys | Metadata | *(none — baseline)* | `ForeignKey` → `ForeignKeyDefinition` VO |
| Create/drop foreign key | DDL | `Capability::ForeignKeys` | SQLite requires `PRAGMA foreign_keys` enabled at runtime; enforcement is opt-in per-connection |
| Composite foreign keys (multi-column) | DDL | `Capability::ForeignKeys` | All 6 engines support multi-column FKs |
| Cross-database/cross-schema foreign keys | DDL | `Capability::CrossSchemaForeignKeys` | MSSQL/PG support cross-schema within same DB; cross-database FKs largely unsupported everywhere except MySQL/MariaDB (same server) |
| ON DELETE / ON UPDATE actions | DDL | *(none — baseline)* | Action set (`RESTRICT|NO ACTION|CASCADE|SET NULL|SET DEFAULT`) varies slightly; SQLite lacks `SET DEFAULT` reliability pre-3.x |
| Deferrable constraints | DDL | `Capability::DeferrableForeignKeys` | PostgreSQL/Oracle native `DEFERRABLE INITIALLY {DEFERRED|IMMEDIATE}`; MySQL/MariaDB/SQLite/MSSQL absent |

## 7. Constraints (Check)

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| List check constraints | Metadata | `Capability::CheckConstraints` | MariaDB exposes via `INFORMATION_SCHEMA.CHECK_CONSTRAINTS` keyed differently than MySQL/PG (see `driver.inc.php:270-275` flavor branch) |
| Create/drop check constraint | DDL | `Capability::CheckConstraints` | MySQL 8.0.16+ enforces (silently ignored before); MariaDB 10.2+; PG full support; SQLite full support; MSSQL full support; Oracle full support |

## 8. Views & Materialized Views

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| List views | Metadata | *(none — baseline)* | — |
| Create/alter/drop view | DDL | *(none — baseline)* | — |
| Materialized views | DDL/Metadata | `Capability::MaterializedViews` | PostgreSQL/Oracle native; MySQL/MariaDB/SQLite/MSSQL absent (MSSQL "indexed views" are a partial analogue, tracked separately) |
| View triggers (INSTEAD OF) | DDL | `Capability::ViewTriggers` | PG/MSSQL/Oracle/SQLite support `INSTEAD OF` triggers on views; MySQL/MariaDB do not |
| Updatable view detection | Metadata | `Capability::UpdatableViews` | Varies by view complexity per engine; exposed as a derived metadata flag, not a DDL operation |

## 9. Routines (Procedures/Functions) & Call

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| List routines | Metadata | `Capability::StoredProcedures` / `Capability::StoredFunctions` | SQLite has neither (no server-side procedural layer) |
| Create/alter/drop procedure | DDL | `Capability::StoredProcedures` | MySQL/MariaDB/PG/MSSQL/Oracle; SQLite absent |
| Create/alter/drop function | DDL | `Capability::StoredFunctions` | MySQL/MariaDB/PG/MSSQL/Oracle; SQLite absent (has application-defined functions only, not SQL-layer) |
| Call procedure with params | Execution | `Capability::StoredProcedures` | IN/OUT/INOUT parameter passing conventions differ significantly; MySQL/MariaDB `CALL`, PG functions returning values, MSSQL `EXEC`, Oracle `BEGIN ... END;` blocks |
| Multiple procedural languages | Metadata | `Capability::MultipleRoutineLanguages` | PostgreSQL (`plpgsql`, `sql`, `plpython3u`, etc.) and Oracle (PL/SQL, Java stored procs) support multiple languages; MySQL/MariaDB/MSSQL/SQLite are single-language |

## 10. Triggers

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| List triggers | Metadata | `Capability::Triggers` | SQLite supports triggers; all 6 engines support table triggers |
| Create/alter/drop trigger | DDL | `Capability::Triggers` | — |
| Timing (BEFORE/AFTER/INSTEAD OF) | DDL | `Capability::Triggers` / `Capability::ViewTriggers` | `INSTEAD OF` only on views, see §8 |
| Event granularity (INSERT/UPDATE/DELETE, per-column) | DDL | `Capability::Triggers` | PG supports column-specific `UPDATE OF col` triggers; others are table-wide per-event |
| Statement-level vs row-level triggers | DDL | `Capability::StatementLevelTriggers` | PG/Oracle distinguish `FOR EACH STATEMENT` vs `FOR EACH ROW`; MySQL/MariaDB/SQLite/MSSQL are row-level only |

## 11. Events (Scheduler)

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| List events | Metadata | `Capability::Events` | MySQL/MariaDB event scheduler only; PG/SQLite/MSSQL/Oracle absent (PG has `pg_cron` as a non-core extension; MSSQL has SQL Agent jobs as a server-level, not database-level, analogue — explicitly not mapped to this capability) |
| Create/alter/drop event | DDL | `Capability::Events` | — |
| Event schedule (interval/at) | DDL | `Capability::Events` | — |
| Event status (enabled/disabled) | DDL | `Capability::Events` | — |

## 12. Sequences

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| List sequences | Metadata | `Capability::Sequences` | PostgreSQL native; Oracle native; MariaDB 10.3+ native; MySQL/SQLite/MSSQL absent (MSSQL has `SEQUENCE` objects since 2012 — included under this capability for MSSQL, correcting a common oversight) |
| Create/alter/drop sequence | DDL | `Capability::Sequences` | — |
| Set/get sequence value (`nextval`/`currval`) | Execution | `Capability::Sequences` | Syntax differs: PG `nextval('seq')`, Oracle `seq.NEXTVAL`, MSSQL `NEXT VALUE FOR seq` |

## 13. User-Defined Types

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| List user-defined types | Metadata | `Capability::UserDefinedTypes` | PostgreSQL (`CREATE TYPE`: enum, composite, range, domain) is the primary case; Oracle supports `CREATE TYPE` (object types); MSSQL supports `CREATE TYPE` (alias types, table types); MySQL/MariaDB/SQLite absent |
| Create/alter/drop user-defined type | DDL | `Capability::UserDefinedTypes` | — |

## 14. Data (Browse/Select/Insert/Update/Delete/Clone/Search)

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| Browse/select data with column projection | Query | *(none — baseline)* | `QueryService::select()` |
| Aggregate functions in select | Query | *(none — baseline)* | `COUNT`/`SUM`/`AVG`/`MIN`/`MAX`; grouping functions vary slightly (e.g. PG `STRING_AGG` vs MySQL `GROUP_CONCAT`) — exposed via `Capability::AggregateFunction` sub-map, not gating the base feature |
| WHERE operators (per-engine operator set) | Query | *(none — baseline, per-op capability sub-map)* | Regex match operators differ: MySQL `REGEXP`, PG `~`, MSSQL `LIKE` only (no native regex without CLR), SQLite `REGEXP` (requires loaded extension), Oracle `REGEXP_LIKE` |
| Order by / limit / pagination | Query | *(none — baseline)* | `LIMIT`/`OFFSET` vs `TOP`/`OFFSET FETCH` vs `ROWNUM` — normalized via `PlatformInterface::buildLimitClause()` |
| Foreign key navigation (follow FK to related row) | Query | *(none — baseline)* | Built from `ForeignKeyDefinition` metadata, not engine-specific |
| Backward keys (reverse FK — "referenced by") | Metadata/Query | *(none — baseline)* | `BackwardKey` VO; derived from FK metadata scan, same across engines |
| Inline row edit | Query | *(none — baseline)* | `QueryService::update()` with primary-key-scoped WHERE |
| Row insert | Query | *(none — baseline)* | — |
| Row delete | Query | *(none — baseline)* | — |
| Row clone/duplicate | Query | *(none — baseline)* | Composed from select + insert; no engine-specific SQL needed |
| BLOB download | Query | `Capability::BlobStreaming` | Exposed as PHP `resource` stream, never as an in-memory string, per `01-vision.md` streaming goal |
| Cross-table search | Query/Metadata | `Capability::CrossTableSearch` | Adminer's "search across tables" feature; implemented as a metadata-driven fan-out of per-table `LIKE`/`ILIKE` queries, not a single-engine primitive |

## 15. SQL Execution & History

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| Execute raw SQL command | Execution | *(none — baseline)* | `ExecutionService::execute()` |
| Multi-statement execution | Execution | `Capability::MultiStatement` | PDO driver-dependent; some drivers (e.g. `pdo_pgsql`) do not support multiple statements in one prepared call reliably — gated explicitly rather than assumed |
| Custom statement delimiter | Execution | `Capability::CustomDelimiter` | Relevant mainly for routine/trigger bodies containing `;` — a parsing concern in the Execution/Import layer, not a server capability per se |
| EXPLAIN plan | Execution | `Capability::ExplainPlan` | All 6 engines support some form (`EXPLAIN`, `EXPLAIN ANALYZE`, MSSQL `SET SHOWPLAN_ALL`) |
| Query warnings | Execution | `Capability::QueryWarnings` | MySQL/MariaDB `SHOW WARNINGS`; others surface via SQLSTATE/notices differently |
| Query history | *(explicitly out of scope for persistence)* | — | SQLCraft may emit a `QueryExecuted` event (see `16-events.md`) that a consumer can persist; SQLCraft itself does not store history |

## 16. Import

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| SQL file import (chunked) | Import | *(none — baseline)* | Streamed via generator, chunked execution, per `23-streaming-memory.md` |
| CSV import | Import | `Capability::CsvImport` | Delimiter/enclosure/escape configurable; type coercion rules documented per-column |
| TSV import | Import | `Capability::TsvImport` | Same pipeline as CSV with tab delimiter preset |
| Import progress callback | Import | *(none — baseline, cross-cutting)* | `ImportService` accepts a `ProgressCallback` for row/byte counters |
| Transaction strategy on import failure | Import | *(none — baseline)* | Configurable: single transaction, per-chunk transaction, or no transaction — explicit choice, not implicit |

## 17. Export/Dump

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| SQL dump export | Export | *(none — baseline)* | Streaming generator output |
| CSV export | Export | `Capability::CsvExport` | — |
| TSV export | Export | `Capability::TsvExport` | — |
| Output compression (gzip) | Export | `Capability::GzipCompression` | Wraps output stream, engine-independent |
| Output compression (bzip2) | Export | `Capability::Bzip2Compression` | Same pattern; requires `ext-bz2` |
| Tar archive output | Export | `Capability::TarArchive` | For multi-file exports (e.g., one file per table) |
| Structure-only / data-only / both export options | Export | *(none — baseline)* | `ExportOptions` VO flag |
| Include triggers/routines/events in dump | Export | `Capability::Triggers` / `Capability::StoredProcedures` / `Capability::Events` (reused) | Export honors the same capability gates as the DDL features it serializes |
| Export scope (all-databases/single-database/single-table) | Export | *(none — baseline)* | `ExportScope` enum |

## 18. Users, Roles, and Privileges

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| List users | Security | `Capability::UserManagement` | SQLite has no user/privilege model at all — entire section is `A` for SQLite |
| Create/alter/drop user | Security | `Capability::UserManagement` | — |
| Password management | Security | `Capability::UserManagement` | Hash algorithm and rotation policy vary per engine (`mysql_native_password` vs `caching_sha2_password` vs PG `SCRAM-SHA-256` vs MSSQL policy-based) |
| Roles (as distinct from users) | Security | `Capability::Roles` | PG unifies users and roles (`CREATE ROLE ... LOGIN`); MySQL 8.0+/MariaDB 10.0.5+ have `CREATE ROLE`; MSSQL has database roles and server roles as distinct concepts; Oracle has roles distinct from users |
| Grant/revoke privileges (matrix: object × privilege × grantee) | Security | `Capability::PrivilegeManagement` | Granularity varies: MySQL/MariaDB support global/db/table/column/routine level; PG table/column/schema/database/sequence level; MSSQL object/schema/database/server level; Oracle object/system level |
| Privilege matrix introspection (who can do what) | Security/Metadata | `Capability::PrivilegeManagement` | Exposed as `PrivilegeGrant` VO collections, not raw `SHOW GRANTS` text |

## 19. Metadata / Introspection (Cross-Cutting)

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| `allFields()` — all columns across all tables in schema | Metadata | *(none — baseline)* | Used for cross-table search and global autocomplete; single batched query per engine rather than N+1 |
| Schema diagram data (ERD) | Metadata | *(none — baseline, data only)* | SQLCraft returns the graph (`TableCollection` + `ForeignKeyDefinition[]`); rendering the diagram is explicitly a consumer concern |
| Partition metadata | Metadata | `Capability::Partitioning` (reused) | `Partitions` VO: partition method, expression, list of partitions with names/values |

## 20. Maintenance Operations

Covered inline in §3 (Analyze/Optimize/Check/Repair/Vacuum) to keep table-lifecycle operations together; cross-referenced here for completeness of the "maintenance ops" category called out in the brief.

## 21. Misc

| Feature | SQLCraft Module | Capability | Notes |
|---|---|---|---|
| Schema diagram (ERD) | Metadata | *(none — data only)* | See §19; no rendering in SQLCraft |
| BLOB download | Query | `Capability::BlobStreaming` | See §14 |
| FK navigation | Query | *(none — baseline)* | See §14 |
| Backward keys | Metadata/Query | *(none — baseline)* | See §14 |

---

## Coverage Matrix — Feature × Initial Six Engines

| Feature Category | MySQL | MariaDB | PostgreSQL | SQLite | MSSQL | Oracle |
|---|---|---|---|---|---|---|
| Schemas/Namespaces | A | A | F | A | F | P (schemas ≈ users) |
| Table engines (storage engine choice) | F | F | A | A | A | A |
| Table comment | F | F | F | A | P (ext. properties) | F |
| Materialized views | A | A | F | A | P (indexed views, partial analogue) | F |
| View triggers (INSTEAD OF) | A | A | F | F | F | F |
| Stored procedures | F | F | F | A | F | F |
| Stored functions | F | F | F | A | F | F |
| Multiple routine languages | A | A | F | A | A | F (PL/SQL + Java) |
| Events/Scheduler | F | F | A | A | A | A |
| Sequences | A | P (10.3+) | F | A | F (2012+) | F |
| User-defined types | A | A | F | A | F | F |
| Check constraints | F (8.0.16+) | F (10.2+) | F | F | F | F |
| Deferrable foreign keys | A | A | F | A | A | F |
| Fulltext index | F | F | P (via tsvector/GIN, different mechanism) | P (via FTS3/4/5 virtual tables) | F | P (Oracle Text, separate feature set) |
| Spatial index | F | F | P (via PostGIS extension) | P (via SpatiaLite extension) | F | P (Oracle Spatial) |
| Vector index | P (9.x+) | A | P (via pgvector extension) | A | A | A |
| Descending index columns | P (8.0+) | P (varies) | F | P (3.30+) | F | F |
| Partial indexes | A | A | F | F | A (filtered indexes are a distinct near-equivalent) | A |
| Index prefix length | F | F | A | A | A | A |
| Generated/computed columns | F | F | F (12+) | F (3.31+) | F | F |
| Auto-increment / identity | F | F | F (SERIAL/IDENTITY) | F | F | F (12c+ IDENTITY, or sequence+trigger) |
| Column comments | F | F | F | A | P (ext. properties) | F |
| ENUM/SET native types | F | F | P (via CREATE TYPE) | A | A | A |
| Partitioning | F | F | F | A | P (Enterprise-oriented features) | F |
| Table inheritance | A | A | F | A | A | A |
| Roles distinct from users | P (8.0+) | P (10.0.5+) | F (unified) | A | F | F |
| Column-level privileges | F | F | F | A | F | P (limited) |
| Cross-schema foreign keys | A (no schema concept) | A | F | A | F | P (via synonyms) |
| Process list / kill | F | F | F | A | F | P (via v$session) |
| Analyze / Optimize / Check / Repair | F (legacy MyISAM-era ops partly deprecated on InnoDB) | F | P (ANALYZE/VACUUM, different semantics) | P (ANALYZE/integrity_check, different semantics) | P (stats update, different semantics) | P (DBMS_STATS, different semantics) |
| Multi-statement execution | P (driver-dependent) | P | P | F | F | P |
| CSV/TSV import & export | F | F | F | F | F | F |
| User/privilege management | F | F | F | A (no user model) | F | F |

This matrix is the authoritative source for `Capability` map defaults in `08-capability-model.md` — any platform implementation that diverges from a `Full` or `Absent` marker here without a documented reason should be treated as a bug in the capability map, not in this inventory.
