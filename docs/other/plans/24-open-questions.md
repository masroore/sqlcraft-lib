# SQLCraft Planning — 24: Open Questions

> Status: **Planning phase. No code exists yet.**
> Last updated: 2026-07-20
> Purpose: catalog every unresolved design decision, known risk, unvalidated assumption, and deliberately incomplete area across docs 00-23. Each item states why it's open, what would close it, the current best guess, and links to the docs that partially address it. This document exists so that "we haven't decided this yet" is tracked explicitly rather than silently assumed away by the confident prose in the other 25 documents.

---

## 1. Design Decisions Requiring a Spike/Prototype

### 1.1 Streaming SELECT API shape

**Why open:** `12-query-engine.md` §3 commits to `\Generator`-backed streaming as the default (`$buffered = false`), but three real alternatives exist — plain PHP generators (chosen), a `ReadableStream`-style abstraction with `read()`/`close()` methods mimicking Node.js streams, or a cursor object with explicit `fetch()`/`fetchAll()` calls closer to raw PDO. The generator choice was made by architectural preference, not by prototyping consumer code against all three.

**What would close it:** Build the same "export 1M rows to CSV" workflow three ways and measure ergonomics (can a consumer `break` out of a `foreach` cleanly? does exception handling inside the loop work as expected? can the result be passed to something expecting `iterable` vs something expecting a PSR-7 stream?) and memory/CPU overhead of each shape.

**Current best guess:** Generators, because PHP has first-class `foreach` support and `12-query-engine.md` already threads this choice through `ResultInterface`, `BatchExecutor`, and `Exporter`. Reversing it later would touch every service that returns rows.

**Docs:** `12-query-engine.md` §3, `21-performance.md` (streaming-vs-buffered memory model), `25-final-review.md` §2 (named as a weakness).

### 1.2 SQLite WAL mode vs `TransactionManager`

**Why open:** `TransactionManager`'s savepoint-nesting design (`12-query-engine.md` §5.2) was designed against SQLite's rollback-journal transaction model. SQLite's WAL (Write-Ahead Logging) mode changes concurrent-reader/writer semantics (readers don't block on a writer, but only one writer at a time) and interacts with `busy_timeout`/`SQLITE_BUSY` retries differently than the default journal mode. No document has verified `TransactionManager` against WAL specifically.

**What would close it:** A dedicated test matrix: WAL mode + concurrent connections from the same process (relevant for CLI tools that might open multiple `PdoConnection`s to the same SQLite file) + savepoint nesting, checking for `SQLITE_BUSY` surfacing correctly as a typed exception rather than a raw PDO error.

**Current best guess:** WAL mode is opt-in per `ConnectionParameters` extras (not yet formally specified); `TransactionManager` should work unmodified since WAL doesn't change savepoint semantics, but this is an assumption, not a verified fact.

**Docs:** `12-query-engine.md` §5, `08-driver-architecture.md` §5 (SqlitePlatform).

### 1.3 PDO persistent connections vs connection-per-request assumptions

**Why open:** `18-public-api.md`'s entire design assumes a consumer calls `SQLCraftFactory::connect()` and gets a session scoped to that call. PDO's `ATTR_PERSISTENT` option reuses a connection across requests (common in traditional PHP-FPM deployments to avoid reconnect overhead), which can leak transaction state, prepared-statement handles, or `SET`-level session variables (isolation level, timeouts set via `SET LOCAL`) across logically unrelated `DatabaseSession` instances if the same underlying PDO handle is silently reused by the driver.

**What would close it:** A spike that opens two `DatabaseSession`s backed by `ATTR_PERSISTENT => true` PDO connections to the same DSN in the same process, and checks whether `TransactionManager` state, `SET LOCAL`-based timeout wrapping (`12-query-engine.md` §10), or capability-resolution caching leak between them.

**Current best guess:** SQLCraft should document `ATTR_PERSISTENT` as unsupported/unverified in v1, and `PdoConnection` should not enable it by default. Whether to actively forbid it (throw if a consumer passes it via extras) or merely warn is undecided.

**Docs:** `10-connection-layer.md` (not yet read in full during this planning pass — flagged here as needing cross-check), `18-public-api.md` §2.

---

## 2. Per-Engine Unknowns

### 2.1 Oracle XE licensing for CI

**Why open:** `M8` (`23-roadmap.md`) depends on running Oracle in Testcontainers for conformance and integration testing. Oracle Database Free/XE editions have historically carried licensing terms that are ambiguous or restrictive for automated CI use (redistribution, container-image provenance, usage caps) compared to MySQL/PostgreSQL/MSSQL's more CI-friendly container images.

**What would close it:** Legal/licensing review of the current Oracle Free container image's terms of use specifically for automated CI pipelines (not just local developer use), and a check of whether Oracle's official container registry requires an account/token that complicates anonymous CI runners.

**Current best guess:** Budget explicit spike time at the start of M8 (already noted in `23-roadmap.md`'s M8 risk section) and have a fallback plan — manual/scheduled-only Oracle testing rather than blocking every PR's CI on an Oracle container.

**Docs:** `23-roadmap.md` M8, `25-final-review.md` §3 (listed as a project risk).

### 2.2 MSSQL on Linux ARM64 in Testcontainers

**Why open:** Microsoft's official SQL Server Linux container images have had inconsistent ARM64 support historically (Apple Silicon developer machines, ARM-based CI runners). If a contributor or CI runner is ARM64-based, MSSQL integration tests may not run natively and would require emulation (slow) or be skipped.

**What would close it:** Verify current MSSQL container image ARM64 support against the actual CI runner architecture planned for the project (GitHub Actions' standard runners are x86_64, so this may be a non-issue for CI specifically but still affects contributor laptops).

**Current best guess:** Assume x86_64 CI runners for v1 (matches GitHub Actions defaults) and treat ARM64 developer-machine support as best-effort/documented-limitation rather than a blocking requirement.

**Docs:** `23-roadmap.md` M8, `19-package-structure.md` §2 (`.github/workflows/integration.yml`).

### 2.3 MariaDB-specific metadata tables vs INFORMATION_SCHEMA gaps

**Why open:** `03-adminer-analysis.md` §5 notes Adminer's `checkConstraints()` has an inline `flavor == 'maria'` branch reading `INFORMATION_SCHEMA.CHECK_CONSTRAINTS` differently keyed than MySQL/PostgreSQL. `09-capability-model.md` §6 documents MariaDB capability differences (sequences at 10.3+, check constraints at 10.2.1+) but the full set of `INFORMATION_SCHEMA` shape divergences between MySQL and MariaDB (which have drifted further apart since MariaDB 10.5+, especially around `mysql.*` system tables vs MariaDB's `mysql.*` compatibility views) has not been exhaustively cataloged.

**What would close it:** A systematic diff of `INFORMATION_SCHEMA` and `mysql.*` system-table shapes between the specific MySQL and MariaDB versions SQLCraft targets, run once during M3/M8 implementation, not assumed from documentation alone (vendor docs on this specific topic are not always current).

**Current best guess:** `MariaDbPlatform extends MySQLPlatform` (per `08-driver-architecture.md` §6) will need more per-method overrides in the `IntrospectionDialectInterface` implementation than the current docs anticipate. This is flagged as a likely underestimate in `25-final-review.md`.

**Docs:** `03-adminer-analysis.md` §5, `08-driver-architecture.md` §6, `09-capability-model.md` §6.

### 2.4 CockroachDB PgSQL-compatibility version targets

**Why open:** `08-driver-architecture.md` §6 mentions CockroachDB as a `PostgreSQLPlatform` flavor (analogous to MariaDB/MySQL) but CockroachDB is not one of the six initial engines committed to in `00-overview.md`. No document specifies which CockroachDB version(s), if any, are actually in scope for v1, v1.1, or ever — it appears only as an illustrative example of the flavor-extension mechanism, not a committed target.

**What would close it:** An explicit product decision: is CockroachDB support a real roadmap item, or purely an illustrative extensibility example that should be labeled as such to avoid confusing "the architecture supports this pattern" with "we are building this driver"?

**Current best guess:** Treat CockroachDB as *illustrative only* until a document explicitly adds it to a milestone. This document recommends `08-driver-architecture.md` be amended to state this explicitly, since as currently written it reads as more committed than it likely is.

**Docs:** `08-driver-architecture.md` §6.

---

## 3. API Design Open Questions

### 3.1 Should `SchemaManager` be a single aggregate, or should consumers wire inspectors directly?

**Why open:** `18-public-api.md` §2.2, §5 establishes `SchemaManager` as a facade aggregating all typed inspectors for discoverability (`$db->schema()->` autocompletes to everything). But `07-module-breakdown.md` §8 defines the inspectors as separate services with their own interfaces, and `18-public-api.md` §2.2 itself acknowledges "Consumers of a DI container *could* instead inject `MetadataServiceInterface`, `DdlBuilderInterface`, etc. directly." Both styles are declared public API, but no document resolves which is *recommended* for which consumer profile, and an aggregate facade risks becoming a god-object if inspectors keep growing.

**What would close it:** Real usage feedback from M4-M6 implementation and early framework-integration examples (M10) — does `SchemaManager` actually stay thin, or does it accumulate special-casing as more inspectors are added?

**Current best guess:** Keep `SchemaManager` as a *pure* aggregate with zero logic of its own (each method is a one-line delegation) so it never becomes a god-object; document the direct-injection style as the recommended pattern for consumers building their own services, and the facade as the recommended pattern for scripts/CLIs (this mirrors the distinction `18-public-api.md` §2.2 already draws but does not fully commit to as *guidance*, only as *both being legal*).

**Docs:** `18-public-api.md` §2.2, §5, `07-module-breakdown.md` §8.

### 3.2 Should DDL builders expose a fluent API, a wither API, or both?

**Why open:** `13-ddl-services.md` §2 shows builders with `with*()` wither methods (`withColumn()`, `withIndex()`) returning new immutable instances, consistent with the "everything is immutable" principle. But `18-public-api.md` §3.4 shows a *fluent, mutating* `->column()->column()->foreignKey()` chain in the actual usage example — which contradicts §2's `with*()` sketch unless the builder is mutable-during-construction and only freezes at `toSql()`/`execute()` (the pattern `18-public-api.md` §4 explicitly describes for `QueryBuilder`, but `13-ddl-services.md` does not explicitly state this same rule applies to DDL builders).

**What would close it:** An explicit amendment to `13-ddl-services.md` stating whether `CreateTableBuilder` follows the same "mutable-during-construction, immutable-once-built" rule as `QueryBuilder` (`18-public-api.md` §4), or whether it is strictly `with*()`-immutable throughout (in which case `18-public-api.md` §3.4's example is wrong and needs correcting).

**Current best guess:** Follow `18-public-api.md` §4's rule uniformly — DDL builders are fluent/mutable-during-construction like `QueryBuilder`, producing an immutable statement VO on `toSql()`. This resolves the apparent contradiction but is this document's inference, not a stated decision in `13-ddl-services.md` itself.

**Docs:** `13-ddl-services.md` §2, `18-public-api.md` §3.4, §4.

### 3.3 Single entry-point class vs DI-wired sub-services

**Why open:** Same underlying tension as 3.1, generalized: is `DatabaseSession` (with its 8 accessor methods) the primary way most consumers are expected to use SQLCraft, or is it a convenience for scripts while "real" applications wire `MetadataServiceInterface`/`QueryExecutorInterface`/etc. directly into their own service classes via their framework's container? `18-public-api.md` §2.2 says both are legal but doesn't commit to which one the documentation/examples (M10) should emphasize as the "default" recommendation.

**What would close it:** A decision on M10's example set: do the Laravel/Symfony integration examples (`examples/06-laravel-integration/`, `07-symfony-integration/`) inject `DatabaseSession` into consumer services, or do they inject individual `*ServiceInterface`s? Whichever pattern the flagship examples use becomes the de facto recommendation regardless of what the docs say.

**Current best guess:** `DatabaseSession` for scripts/CLI/simple consumers; direct sub-service injection for consumers already using a mature DI container and wanting per-service test doubles. Both examples should be shown in M10, explicitly labeled with when to prefer each.

**Docs:** `18-public-api.md` §2.2, §8.

---

## 4. Scope/Priority Open Questions

### 4.1 Schema diff + DDL migration generation — v1 or v1.1?

**Why open:** `06-package-architecture.md` §3 names "Schema" as a bounded context responsible for "high-level schema comparison and diff," and `05-domain-model.md` §7 lists `SchemaInspector`/`SchemaInspectorInterface` with a `compare()` method mentioned in `18-public-api.md` §5. But no document specifies the diff algorithm, what "equivalent DDL" means when regenerating a diff as ALTER statements, or how this interacts with the DDL builders' documented 95%-coverage limitation (`13-ddl-services.md` §1.2). This is a substantial feature (essentially a migration-generation tool) bolted onto the edge of the introspection/DDL layers without its own design document.

**What would close it:** A dedicated design document (`26-schema-diff.md` or similar) scoping exactly what diff granularity is supported (column type changes? index changes? does it detect renames vs drop+add?) before committing it to any milestone.

**Current best guess:** Defer full schema-diff/migration-generation to v1.1. Ship only the read-side comparison (`SchemaInspector::compare()` returning a list of detected differences as data, per `04-feature-inventory.md`-style DTOs) in v1 if time allows, but do NOT commit to auto-generating executable ALTER statements from a diff in v1 — that is a substantially harder correctness problem than the DDL builders already acknowledge they don't fully solve (`13-ddl-services.md` §1.2, `25-final-review.md` §2).

**Docs:** `06-package-architecture.md` §3, `05-domain-model.md` §7, `18-public-api.md` §5, `13-ddl-services.md` §1.2.

### 4.2 JSON/XML export — v1 core or plugin?

**Why open:** `04-feature-inventory.md` §17 lists SQL/CSV/TSV export as baseline capabilities but does not mention JSON/XML at all in the feature inventory, while `00-overview.md`'s reading guide once referenced an `export-service.md` covering "SQL, CSV, TSV" only. `23-roadmap.md` M7 (written in this session) treats JSON/XML as a stretch goal within M7, which is this document's own placeholder decision, not a resolved product decision from earlier docs.

**What would close it:** A product decision on whether JSON/XML export is common enough consumer demand to justify shipping in `SQLCraft\Export` core, versus being the first real test of the "third parties implement `FormatWriterInterface`" extensibility story (`07-module-breakdown.md` §10) — shipping it as an *example* third-party format plugin would double as a proof point for extensibility.

**Current best guess:** Ship SQL/CSV/TSV in v1 core; treat JSON as a good extensibility demo (implement it as `Acme\SQLCraftJsonExport\JsonFormatWriter` in `examples/`, proving the plugin story, rather than as core). XML is lower priority than JSON given declining consumer demand for XML export generally — likely v1.1+ or community-contributed.

**Docs:** `04-feature-inventory.md` §17, `07-module-breakdown.md` §10, `23-roadmap.md` M7.

### 4.3 PSR-6/PSR-16 metadata cache adapter — in-package or separate package?

**Why open:** `19-package-structure.md` §3 lists `psr/simple-cache-implementation` as `suggest`-only, and `16-events.md`/`M4`'s roadmap entry mentions a "metadata caching seam" (`MetadataCacheInterface`) as a deliverable, but no document specifies whether SQLCraft ships a *concrete* PSR-16 adapter (e.g., wrapping `symfony/cache` or a simple in-memory LRU) in the core package, or ships only the interface and expects every consumer to bring their own.

**What would close it:** A decision consistent with the zero-runtime-deps policy (`19-package-structure.md` §5) — since requiring any concrete PSR-16 implementation as a runtime dependency contradicts that policy, the realistic options are "interface only, zero bundled implementation" or "ship one trivial in-memory implementation with no external deps, as a convenience default."

**Current best guess:** Ship `MetadataCacheInterface` plus one dependency-free `InMemoryMetadataCache` (analogous to `InMemoryQueryHistory`, `12-query-engine.md` §11) as the zero-config default; document PSR-16 adapters as a consumer/community concern, not a SQLCraft core deliverable.

**Docs:** `19-package-structure.md` §3, §5, `12-query-engine.md` §11 (precedent pattern).

---

## 5. Dependency Decisions

### 5.1 `psr/event-dispatcher` — hard require vs soft require

**Why open:** `19-package-structure.md` §3-5 makes an explicit, deliberately maximalist choice to keep even PSR interface packages (`psr/event-dispatcher`, `psr/simple-cache`, `psr/log`) as `suggest`-only rather than `require`, acknowledging in §5.2 that "SQLCraft could reasonably do the same [as requiring them]" since interface packages are tiny and low-cost. This is flagged in the source document itself as a judgment call, not a closed question.

**What would close it:** Real feedback from early adopters — does the `suggest`-only policy actually prevent friction (as intended), or does it just mean every consumer has to separately discover and require `psr/event-dispatcher` themselves to get events working at all, adding a setup step the "required" alternative would avoid?

**Current best guess:** Keep as `suggest`-only for v1 per the stated rationale, but treat this as reversible — promoting `psr/event-dispatcher`/`psr/simple-cache`/`psr/log` to `require` in a future minor version is a backward-compatible tightening (adding a dependency a consumer likely already has transitively), not a breaking change, so there's no urgency to get this exactly right before v1.0.

**Docs:** `19-package-structure.md` §3, §5.

### 5.2 `psr/simple-cache` — same question, same status

Identical reasoning to 5.1, tracked separately because the two packages could reasonably land different answers (event dispatch is more central to the architecture — 27 events — than metadata caching, which is explicitly an optional performance seam per `21-performance.md`).

**Docs:** `19-package-structure.md` §3, §5.

### 5.3 `nikic/php-parser` vs a hand-written state machine for `StatementSplitter`

**Why open:** `12-query-engine.md` §4.1 specifies `StatementSplitterInterface::split()` must handle quoted strings, block/line comments, and custom `DELIMITER` directives — this is a nontrivial lexer problem. No document evaluates whether to depend on `nikic/php-parser` (a mature, widely-used PHP-focused parser toolkit, though it parses *PHP* code, not SQL, so its applicability here is actually questionable) or a purpose-built hand-rolled tokenizer for *SQL* statement splitting specifically. The prompt's framing suggests `nikic/php-parser` as a candidate, but that library parses PHP source, not SQL — it is very likely not directly applicable to this problem at all.

**What would close it:** A spike comparing a hand-rolled SQL-aware tokenizer (tracking quote state, comment state, delimiter state character-by-character) against any genuinely SQL-focused parsing library (if one with acceptable license/maintenance status exists) for correctness on edge cases (escaped quotes inside strings, nested block comments if any engine allows them, multi-byte delimiter tokens).

**Current best guess:** Hand-written state machine, dependency-free — this aligns with the zero-runtime-deps policy (`19-package-structure.md` §5) and the problem (statement splitting, not full SQL parsing/AST construction) is bounded enough that a purpose-built tokenizer is tractable and avoids adding a large, general-purpose dependency for a narrow need. `13-ddl-services.md` §1.1 already rejected a full-AST approach for DDL generation on complexity grounds; the same reasoning applies here.

**Docs:** `12-query-engine.md` §4.1, `19-package-structure.md` §5, `13-ddl-services.md` §1.1 (precedent reasoning).

---

## 6. PHP Version Forward-Compatibility

### 6.1 PHP 8.5 features that might affect the design

**Why open:** SQLCraft's floor is PHP 8.4 (`19-package-structure.md` §3), and the design already leans on PHP 8.4's `clone with` (`05-domain-model.md` §5) as the sole VO mutation path. PHP 8.5 (expected in the project's near-term timeframe given the stated "today" date) may introduce further language features — pipe operator proposals, additional readonly/property enhancements, or standard library additions — that could either simplify parts of this design (e.g., a cleaner wither syntax) or, more importantly, be adopted inconsistently by early codebase contributions before a floor-bump decision is made.

**What would close it:** A standing policy: SQLCraft targets PHP 8.4 syntax exclusively until a deliberate minor-version decision raises the floor; any 8.5-only feature use is rejected in code review until that floor bump happens, tracked via `composer.json`'s `php` constraint and CI's PHP version matrix.

**Current best guess:** No 8.5-specific features are assumed anywhere in docs 00-23; this is a non-issue as long as the floor-bump discipline above is followed. Flagged here mainly so a future contributor doesn't casually introduce an 8.5-only construct and quietly break the stated 8.4 floor.

**Docs:** `19-package-structure.md` §3, `05-domain-model.md` §5.

### 6.2 Readonly class/property promotion stability across all 8.4 targets

**Why open:** PHP 8.4 hardened and extended `readonly` semantics (including property-hook interactions), and the design assumes `readonly` classes with constructor promotion work identically across every PHP 8.4.x patch release the project might run on. Minor 8.4.x point-release bugs in edge cases (readonly + `clone with` + inheritance combinations) are plausible for a language feature this new at the time of writing.

**What would close it:** Running the full VO/DTO test suite (M1) against multiple PHP 8.4.x patch versions in CI (not just "latest 8.4") to catch any patch-level regression early.

**Current best guess:** Pin CI to test against at least the earliest supported 8.4.x patch and the latest available, not just one version, once M0's CI matrix is defined — this is a gap in `19-package-structure.md` §2's `.github/workflows/ci.yml` description, which does not currently specify a PHP-version test matrix at all.

**Docs:** `19-package-structure.md` §2, `05-domain-model.md` §5.

---

## 7. Performance Validation

### 7.1 Streaming generator overhead vs buffered fetching for small result sets

**Why open:** `12-query-engine.md` §3 defaults every `query()` call to streaming, on the architectural principle that memory safety matters more than raw throughput. But generators in PHP have real per-iteration overhead (coroutine-like suspend/resume machinery) compared to iterating a plain array, and the vast majority of real-world queries in an admin/introspection tool return small result sets (tens to low-hundreds of rows: listing tables, columns, indexes) where this overhead is pure cost with zero memory benefit. No document presents a benchmark; the streaming-by-default decision is stated as a principle, not measured.

**What would close it:** A microbenchmark comparing generator-based vs array-based iteration for result sets of realistic small sizes (10, 100, 1000 rows) across PHP 8.4's actual generator implementation, to quantify whether the overhead is negligible (likely, given PHP's generator implementation is reasonably efficient) or meaningful enough to justify a "small-result-set auto-buffering" optimization.

**Current best guess:** The overhead is very likely negligible for typical admin-tool query sizes and the memory-safety benefit for the tail case (someone browses a multi-million-row table without pagination) dominates the design tradeoff — but this is confidence based on general PHP knowledge, not a SQLCraft-specific measurement, and should be validated before or shortly after M6 ships rather than assumed indefinitely.

**Docs:** `12-query-engine.md` §3, `21-performance.md` (the document most likely to already partially address this — flagged for cross-check since this planning pass read it only by title, not full content).

---

## 8. Community/Governance

### 8.1 Package name and Packagist namespace

**Why open:** Every document in this set uses `vendor/sqlcraft` as a placeholder (`19-package-structure.md` §3's `composer.json` literally has `"name": "vendor/sqlcraft"`). No actual Packagist vendor namespace has been reserved or confirmed available. "SQLCraft" itself is a fairly generic, guessable name in the database-tooling space — collision risk with an existing Packagist package or GitHub organization has not been checked.

**What would close it:** A Packagist search and GitHub organization/repo name availability check for "sqlcraft" and close variants, before any public announcement or first tag.

**Current best guess:** Unresolved — this is a pure lookup task, trivial to close, but not yet done as of this planning pass. Flagged as a pre-implementation action item (see `25-final-review.md` §6).

**Docs:** `19-package-structure.md` §3.

### 8.2 License

**Why open:** `19-package-structure.md` §2-3 states MIT and cites "02-guiding-principles.md licensing stance," but this planning pass did not re-verify that `02-guiding-principles.md` contains a fully reasoned licensing decision (e.g., MIT vs Apache-2.0's patent grant, relevant if any contributed driver code touches patented database-connectivity techniques — unlikely but not zero-risk for, e.g., Oracle-specific integration code).

**What would close it:** Explicit confirmation that `02-guiding-principles.md`'s licensing section was a deliberate choice (not just a default), and that MIT is acceptable given the project's dependency graph (MIT is compatible with essentially everything SQLCraft might depend on, including PSR interfaces which are themselves MIT).

**Current best guess:** MIT, as already stated in `19-package-structure.md`. Low-risk, low-priority to revisit.

**Docs:** `19-package-structure.md` §2-3, `02-guiding-principles.md` (referenced but not re-verified in this pass).

### 8.3 Code of conduct and contribution model

**Why open:** No document in the 00-23 set addresses `CODE_OF_CONDUCT.md`, `CONTRIBUTING.md`, issue/PR templates, or a maintainer/governance model (single maintainer vs core team vs foundation-style governance). This is a genuine gap, not a deferred-on-purpose item — it simply has not been discussed anywhere in the planning set.

**What would close it:** A short governance document, likely written alongside M10 (documentation milestone) rather than needed before implementation begins — this is lower urgency than the technical open questions above since it does not block any code being written.

**Current best guess:** Standard `CONTRIBUTOR_COVENANT`-style code of conduct, PR-based contribution model with the core team as initial maintainers, revisited if/when the project attracts enough external contribution volume to need more formal structure. This is this document's own placeholder recommendation, not a decision made elsewhere.

**Docs:** None — genuine gap, also noted in `25-final-review.md` §5.
