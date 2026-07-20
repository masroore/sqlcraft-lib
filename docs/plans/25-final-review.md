# SQLCraft Planning — 25: Final Review

> Status: **Planning phase. No code exists yet.**
> Last updated: 2026-07-20
> Purpose: an adversarial self-evaluation of the entire SQLCraft architecture as designed across docs 00-24. This is not a summary and it does not exist to make the plan look good. It is a deliberate search for weaknesses, phrased so that each one is actionable rather than merely acknowledged.

---

## 1. Strengths

These are the parts of the design that hold up under scrutiny and are worth defending, not just restating.

**The capability model is a genuine improvement, not just type-safety theater.** Replacing `support(string): bool` + scattered `preg_match` version checks with a backed enum, an immutable `CapabilitySet`, and a single `PlatformCapabilityResolver` (`09-capability-model.md`) actually closes real Adminer bugs — a typo'd capability string silently returning `false` forever is a documented, real failure mode (`03-adminer-analysis.md` §4), and the enum approach makes that class of bug a parse error. This is not a cosmetic change; it changes the failure mode from "silent wrong behavior in production" to "caught at development time."

**The hexagonal boundary is drawn in the right place and consistently enforced on paper.** `PDO` never surfacing past `Connection` (`06-package-architecture.md` §2, `18-public-api.md` §7) is a real, checkable architectural invariant (`deptrac` rules exist for it, `19-package-structure.md` §2), not just a stated aspiration. This is the single most important property for the stated goal ("works in Laravel/Symfony/Slim/CLI/AI-agents/IDE-extensions unchanged") and the design consistently protects it across every document rather than leaking it in convenience methods.

**Rejecting Adminer's global-state mechanisms outright, rather than partially, is correct.** Docs 03 and 06 identify four distinct global-state mechanisms in Adminer (`define()` constants, `$_SESSION` nesting, static singletons, singleton accessor functions) and the response — constructor injection everywhere, `DriverRegistry` as the sole, deliberately-justified exception — is coherent and the exception is defended with actual reasoning (`18-public-api.md` §1) rather than smuggled in silently. Multi-connection support (N sessions to N engines in one process) is a direct, testable consequence of this choice, not just a slogan.

**The DDL builder's intent/rendering separation (Option C in `13-ddl-services.md` §1.1) is the right tradeoff for this problem's actual complexity budget.** The document explicitly considered and rejected both extremes (Adminer's string-concatenation approach and a full AST) with concrete reasoning about why each is wrong for this specific use case, rather than defaulting to the most sophisticated-sounding option. This is a rare example in the doc set of an architecture decision that shows its rejected alternatives' actual failure modes instead of just naming them.

**The security model correctly identifies its boundary and does not overreach.** `15-security.md` §9's explicit table of what SQLCraft does NOT do (CSRF, sessions, brute-force throttling, IP allowlisting) drawn against what Adminer conflates into itself is a genuinely useful contribution — it prevents scope creep into "SQLCraft is now also an auth framework" while still taking real ownership of the part that is actually a library's job (identifier quoting, parameter binding, operator allowlisting).

---

## 2. Identified Weaknesses

### 2.1 Over-engineering risk: 26 planning documents before a line of real code

**What it is:** This project has produced roughly 26 design documents totaling hundreds of pages, specifying hundreds of interfaces, dozens of value objects, 27 events, and a six-milestone-plus roadmap — all before a single class exists in `src/`. Several documents (13, 16, 18) already show internal signs of drift from each other (see 2.7 below) that would have been caught in an afternoon of writing real code against real tests.

**What could go wrong:** Analysis paralysis is not a hypothetical risk here — it is already partially realized. `13-ddl-services.md`'s builder sketch and `18-public-api.md`'s usage example already disagree on whether `CreateTableBuilder` is fluent-mutable or `with*()`-immutable (`24-open-questions.md` §3.2), and this was only caught by cross-referencing during this review, not by any earlier process. The more documents accumulate before implementation, the more such inconsistencies compound, and the more expensive they become to reconcile because each new document builds on the assumption that earlier ones are internally consistent.

**Which docs it affects:** All of them, structurally — but 13/18's concrete contradiction is the clearest evidence.

**Recommended mitigation:** Stop writing net-new planning documents after this set. Treat M1 (Foundation, `23-roadmap.md`) as the mechanism that actually resolves ambiguities like 2.7 below — real code and real unit tests will surface remaining inconsistencies far faster than more prose would. If a genuine design question remains after M1's first pass, write a short, single-purpose decision doc, not another 500-line architecture document.

### 2.2 The platform abstraction may be too thin for highly divergent engines

**What it is:** `PlatformInterface`'s segregated sub-interfaces (`08-driver-architecture.md` §3) are designed against six relational engines that share a huge amount of structure — tables, columns, rows, SQL as the query language, roughly comparable transaction models. The interface shape (quoting, pagination, type-mapping, DDL rendering, introspection SQL) implicitly assumes "the underlying store is a SQL RDBMS" at every level. DuckDB is used as the extensibility walkthrough (`08-driver-architecture.md` §9) and is itself SQL-relational enough to fit reasonably well — but the prompt's own examples of genuinely divergent engines (ClickHouse, with its radically different storage/aggregation model and non-standard SQL dialect; Elasticsearch, which is not relational or SQL-based at all) would likely break the abstraction at the `IntrospectionDialectInterface`/`DdlDialectInterface` level, not just require "override a few methods."

**What could go wrong:** A third party attempts to implement `DriverInterface`/`PlatformInterface` for Elasticsearch and discovers that half the interface (foreign keys, transactions, sequences, check constraints) has no meaningful mapping at all, forcing either a pile of `throw new CapabilityNotSupportedException` stubs on nearly every method, or a fork of the interface hierarchy that defeats the "one PlatformInterface, add a driver" extensibility promise made in `08-driver-architecture.md` §9. The architecture would technically "support" this via the capability-gating mechanism, but the resulting driver would be mostly `CapabilityNotSupportedException` throws with a thin, honest sliver of real functionality — which is a valid outcome, but the docs oversell extensibility ("adding DuckDB should require implementing a bounded set of interfaces" — §1) without being explicit that this promise is scoped to *relational* engines specifically.

**Which docs it affects:** `08-driver-architecture.md` (throughout), `09-capability-model.md` (the capability enum is itself relational-schema-shaped — `Trigger`, `ForeignKeys`, `Sequence` — with no accommodation for, e.g., a document-store's or columnar-analytics-store's native concepts).

**Recommended mitigation:** Scope the extensibility claim explicitly to "SQL-speaking relational and near-relational engines" in `08-driver-architecture.md`, and do not present DuckDB as evidence the architecture generalizes to non-relational stores — it doesn't, and no document should imply otherwise. If non-relational support is ever a real goal, it needs its own architecture pass, not an extension of `PlatformInterface`.

### 2.3 The capability model is static per connection, not per-permission

**What it is:** `CapabilityResolverInterface::resolve()` (`09-capability-model.md` §4) is keyed on platform name + `ServerVersion`, resolved once and cached implicitly (the docs describe it as fast/no-round-trip specifically because it avoids re-querying). This models "does this *engine version* support triggers" correctly, but says nothing about "does *this connected user* have permission to create/see triggers" — a materially different and equally real-world question. A connection to a fully-featured PostgreSQL 16 server as a read-only, no-DDL-privilege role should not report `Capability::Table` (CREATE TABLE-adjacent capability) as unconditionally available the way the current model does, because the *engine* supports it even though *this specific credential* cannot use it.

**What could go wrong:** A consumer builds a permission-aware admin UI on top of `$db->capabilities()->has(Capability::Trigger)` expecting it to answer "can I use this," gets `true` (the engine supports triggers), attempts the operation, and gets a permission-denied error from the database instead of a clean `CapabilityNotSupportedException` — the exact bifurcated-error-experience problem the capability model was supposed to eliminate. `15-security.md` §10 acknowledges a related but distinct concern ("SQLCraft surfaces privilege information; it does not enforce it") but does not connect this back to the capability model's implicit "capability = usable" framing, leaving a conceptual gap between two documents that describe adjacent but disconnected mechanisms.

**Which docs it affects:** `09-capability-model.md` (throughout), `15-security.md` §10 (adjacent but unconnected).

**Recommended mitigation:** Document explicitly, prominently, in `09-capability-model.md`, that `CapabilitySet` answers "does this engine+version support this feature" and never "can this credential use this feature" — and that permission-based failures are a distinct, expected exception path (`InsufficientPrivilegesException`, already in the hierarchy per `05-domain-model.md` §9) that consumers must handle *in addition to*, not instead of, capability checks. This is a documentation fix, not necessarily an architecture change, but it needs to happen before a consumer builds the wrong mental model from `18-public-api.md` §3.10's example, which currently implies capability checks are sufficient gating.

### 2.4 Streaming-everywhere adds real complexity for a debatable universal benefit

**What it is:** `12-query-engine.md` §3 defaults every SELECT to a generator-backed streaming result. This is architecturally clean but has real downstream costs: every consumer of `ResultInterface` must be disciplined about consuming the generator exactly once, in order, without holding onto it across an unrelated operation on the same connection (interleaving a streaming read with a write on the same connection is a well-known footgun with unbuffered cursors on several engines — MySQL in particular will error if you try to issue a new query while an unbuffered result set is still being read). Most consumers, most of the time, want an array — that is simply how most PHP code is written and how most small (tens-to-hundreds-of-rows) admin-tool queries actually behave.

**What could go wrong:** A consumer iterates a streaming `SELECT`, and inside the loop calls another SQLCraft method that issues a second query on the *same* connection (e.g., an N+1-shaped FK-navigation lookup per row) — a pattern that is natural to write and easy to reach for, and that will fail or silently misbehave on at least one supported engine's unbuffered-cursor semantics unless SQLCraft actively guards against it. No document currently specifies this guard exists. `24-open-questions.md` §1.1 already flags the ergonomics side of this; this entry flags the *correctness* side, which is more serious.

**Which docs it affects:** `12-query-engine.md` §3 (the core design), `10-connection-layer.md` (the actual PDO-level unbuffered-cursor behavior — not fully cross-checked in this review pass), `24-open-questions.md` §1.1 and §7.1 (related, narrower questions).

**Recommended mitigation:** Either (a) `ConnectionInterface` must detect and throw a clear, typed exception when a second statement is issued on a connection with an open unbuffered cursor, rather than surfacing a raw driver error, or (b) `PdoConnection` must transparently use a second, pooled connection/cursor for the interleaved query. Whichever approach is chosen, it must be specified explicitly — this is currently an unaddressed correctness gap, not merely an ergonomics preference.

### 2.5 The DDL builder design cannot fully abstract ALTER TABLE, and the docs partially acknowledge this without fully reckoning with it

**What it is:** `13-ddl-services.md` §1.2 states plainly: "ALTER TABLE is the hardest operation... treated as a first-class design challenge in §5" — but §5 (SQLite table recreation) is a forward reference that, as of this document set, addresses only SQLite's specific limitation, not the full 50+-edge-case surface ALTER TABLE presents across six engines (column type-coercion compatibility rules, MySQL's `ALGORITHM=INSTANT`/`INPLACE`/`COPY` hints and when each is even legal, PostgreSQL's inability to change a column's type without an explicit `USING` cast expression in nontrivial cases, MSSQL's constraints on altering columns referenced by indexes/constraints/computed columns, Oracle's similar restrictions). `23-roadmap.md` M5 (written in this review pass) explicitly scoped acceptance criteria down to "common ALTER operations" and flagged the remainder as "post-M5 follow-up issues" — which is honest, but means the architecture document itself (13) oversells its own completeness relative to what the roadmap (23, written with fuller information) actually commits to delivering.

**What could go wrong:** A consumer calls `AlterTableBuilder::modifyColumn()` to widen a `VARCHAR(50)` to `VARCHAR(200)` on one engine and it works, then does the equivalent narrowing operation (`VARCHAR(200)` to `VARCHAR(50)` with existing data that doesn't fit) on another engine and gets a raw, unclassified driver error rather than a well-typed `IncompatibleColumnChangeException` — because the builder's `toSql()` genuinely cannot know, without querying live data, whether a narrowing type change is safe. This is not a bug in the design so much as an inherent limit that the docs should state as a limit, not imply is "handled."

**Which docs it affects:** `13-ddl-services.md` §1.2, §5 (forward reference not yet fulfilled as a complete document), `23-roadmap.md` M5 (the roadmap's honesty here actually exposes doc 13's overreach).

**Recommended mitigation:** Amend `13-ddl-services.md` to state explicitly, near §1.2, that ALTER TABLE support is *intentionally scoped* to structurally-safe operations in v1 (add/drop/rename column, add/drop constraint/index) and that data-dependent operations (narrowing types, adding NOT NULL to a column with existing NULLs) are out of scope until validated against live data — which requires a design this document set does not yet have (a pre-flight validation query per risky ALTER, run before attempting the DDL). Do not let the roadmap document be the only place this limitation is honestly stated.

### 2.6 The event system's 27 objects are justified for the library but the interception-event mutation surface is a latent injection risk

**What it is:** `16-events.md`'s `BeforeQueryExecuted::replaceSql()` (§5.2) lets a listener replace the SQL and parameters of a query in flight — explicitly designed to support use cases like automatic tenant-scoping (`AND tenant_id = ?` injection into every SELECT). This is a powerful, useful extension point. It is also, by construction, a sanctioned way for arbitrary third-party code to rewrite SQL text after all of `15-security.md`'s allowlisting/binding protections have already been applied to the original query — meaning a buggy or malicious listener has a clean, documented API for reintroducing exactly the injection risk the rest of the security model works hard to prevent, and no document specifies any validation on what `replaceSql()` accepts (it takes a raw `string $sql` with no `Identifier`/VO-typed structure at all).

**What could go wrong:** A well-intentioned tenant-scoping extension has a bug in its own string-concatenation logic when building the replacement SQL (ironic, since this is the exact category of bug the rest of the architecture eliminates by construction) and reintroduces a classic SQL injection vulnerability at exactly the interception point most likely to see third-party, less-audited code.

**Which docs it affects:** `16-events.md` §5.2 (the mechanism itself), `15-security.md` (does not mention this interception surface anywhere in its threat model, §6.1's attack-surface table has no row for "event listener SQL replacement").

**Recommended mitigation:** Add `BeforeQueryExecuted`'s `replaceSql()` explicitly to `15-security.md` §6.1's threat-surface table, with the documented mitigation being "consumer responsibility, by design — same category as the raw `execute($sql)` escape hatch already documented in §6.2." This costs nothing architecturally but currently the omission makes the threat model read as more complete than it is.

### 2.7 No round-trip integration between schema introspection and DDL generation

**What it is:** `describeTable()` (Metadata/introspection, M4) and `CreateTableBuilder`/`AlterTableBuilder` (DDL, M5) both consume and produce the same DTOs (`ColumnMeta`, `IndexMeta`, `ForeignKeyMeta`), which invites the assumption that introspecting a table and feeding the result back into a DDL builder produces equivalent DDL — a "read it, regenerate it, get the same table back" round-trip guarantee. No document states this guarantee exists, and `23-roadmap.md` M5 (written in this pass, with the benefit of having read both sides) explicitly downgrades this to "a weaker but concretely testable substitute" (re-introspecting after create, not full DDL-text equivalence) specifically because the stronger guarantee is not designed for anywhere in docs 05/13.

**What could go wrong:** A consumer builds a schema-migration or backup/restore tool assuming `describeTable()` → `CreateTableBuilder` → `toSql()` round-trips faithfully (a natural, even obvious thing to want from a library with this shape), and discovers that platform-specific DDL details captured only informally (storage parameters, some index options, collation nuances not modeled in `IndexMeta`) are silently dropped in the round trip, producing a structurally similar but not identical table.

**Which docs it affects:** `05-domain-model.md` (DTO shapes), `13-ddl-services.md` (builder shapes) — the gap is *between* these two documents, not within either one.

**Recommended mitigation:** State explicitly, in both `05-domain-model.md` and `13-ddl-services.md`, that DTO-to-builder round-tripping is *not* a guaranteed property of v1, and that a genuine round-trip-safe "clone this table's structure" feature (if wanted) needs its own explicit design and test suite (a natural M5/M6-adjacent follow-up, not an assumed side effect of shared DTOs). This is the single clearest example in the whole doc set of two well-designed subsystems whose combination was never explicitly verified.

### 2.8 `DriverRegistry`'s "not really global state" framing is only partly convincing

**What it is:** `08-driver-architecture.md` §8 and `18-public-api.md` §1 both defend `DriverRegistry` as a legitimate exception to the "no static state" principle because it is a "stateless lookup table," not mutable session state. But `08-driver-architecture.md` §8's own code sketch uses `private static array $drivers = []` — this *is* process-wide mutable static state by any technical definition; the defense is that it's *conceptually* read-mostly (populated once at bootstrap, read many times), not that it is literally immutable or non-static. `18-public-api.md` §9, by contrast, shows `DriverRegistry` being *instantiated* (`new DriverRegistry()`, then `$registry->register(...)`, passed into `SQLCraftFactory`'s constructor) — which is a different, non-static design than §8's static-property sketch. These two documents describe two different implementations of the same class without acknowledging the discrepancy.

**What could go wrong:** Nothing catastrophic, but it is exactly the kind of inconsistency flagged generally in 2.1 — it will cost real implementation time to notice and reconcile in M3, and until reconciled, two people reading two different documents will reasonably believe two different things about a fairly central class.

**Which docs it affects:** `08-driver-architecture.md` §8 (static-property sketch), `18-public-api.md` §1, §9 (instance-based sketch).

**Recommended mitigation:** Pick one. The instance-based design (`18-public-api.md`'s version) is architecturally preferable — it is genuinely not static state, is trivially mockable/replaceable per-`SQLCraftFactory`, and doesn't require the "well, it's *conceptually* not really global" defense at all. `08-driver-architecture.md` §8 should be corrected to match, and its own defense of "not really global state" should be replaced with "this isn't static state at all" once the code sketch is fixed.

---

## 3. Key Risks

**PHP 8.4 as a hard floor limits the initial hosting/adoption surface.** Many production PHP environments — especially shared hosting and slower-moving enterprise deployments, a real fraction of Adminer's actual historical user base — will not have PHP 8.4 available for months to years after its release. This is a deliberate, defensible choice for a *greenfield* library (Adminer's PHP 5.3 floor was the thing being explicitly rejected), but it does mean the addressable "replace Adminer with SQLCraft-based tooling today" market is smaller than Adminer's own install base for a meaningful transition period.

**`pdo_oci` (Oracle) is genuinely hard to install, and this affects both CI and real-world adoption, not just CI.** This is flagged in `24-open-questions.md` §2.1 as a CI risk, but it's equally a *consumer* risk — a framework developer wanting SQLCraft's Oracle support will hit the same `pdo_oci` installation friction Adminer's own users have complained about for years. This is not something SQLCraft's architecture can fix; it is inherent to Oracle's PHP driver ecosystem, but it should be named as a project risk, not just a CI inconvenience, since it may suppress real Oracle-driver adoption regardless of how well `OraclePlatform` is implemented.

**Packagist namespace and package-name availability have not been verified** (`24-open-questions.md` §8.1) — a purely administrative risk, trivially closable, but currently unclosed, and blocking on it late (e.g., discovering a name collision after the README/examples/branding are all written) would be an avoidable, self-inflicted delay.

**Scope creep beyond the planning phase is a live risk given the pattern already observed in this document set.** Doc 22 (migration map, written in this session) surfaced two genuine capability gaps (schema-diff/migration generation, cross-table search's missing dedicated service) that are not yet fully scoped features but are clearly implied as "coming eventually" by cross-references in other docs. Each such implied-but-unscoped feature is a scope-creep vector once implementation starts and momentum makes "just add this too" easier to justify than it should be.

**The six-engine v1 commitment is ambitious relative to the team-size/timeline information available.** No document states team size, calendar timeline, or funding model. `23-roadmap.md`'s milestones, sized relative to each other, sum to a very large undertaking (multiple XL milestones) for what appears — based on the absence of any stated team/organization beyond "SQLCraft Contributors" — to possibly be a small team or solo effort. This is not a criticism of the architecture but a real project-risk: the architecture is sized for a project with more implementation capacity than has been demonstrated to exist.

---

## 4. Assumptions That Might Be Wrong

| Assumption | What happens if false |
|---|---|
| Consumers want typed VOs/DTOs/Collections badly enough to accept the verbosity and learning curve over Adminer's "just an array" simplicity | Adoption stalls because the API feels heavyweight for simple scripts; a "lite" array-returning compatibility mode might be needed, undermining the type-safety pitch |
| Streaming-by-default is the right universal default rather than an opt-in for large-result cases only | Every simple consumer pays a small but real ergonomics/performance tax (2.4) for a benefit only a minority of queries need; reversing the default post-v1.0 is a breaking change per the SemVer promise (`18-public-api.md` §10) |
| The capability matrix (`09-capability-model.md` §6) is accurate and stable across the specific point-versions of each engine actually deployed in the wild | If the matrix has errors (plausible — it was compiled from documentation/analysis, not exhaustively verified against every listed version), consumers get incorrect `has()`/`require()` results, which is worse than no capability system at all because it's a *confident* wrong answer |
| Six engines can share one `PlatformInterface` shape without it becoming a lowest-common-denominator abstraction that undersells any single engine's real capabilities | If PostgreSQL-specific power (e.g., rich native types, advanced indexing) doesn't fit cleanly, PostgreSQL users reach for `ExtendedCapability`/raw-SQL escape hatches so often that the "typed, safe" value proposition erodes for that entire user segment |
| A single Composer package (not split by driver) remains the right call as the codebase grows to include MSSQL and Oracle's real implementation weight | If Oracle/MSSQL support turns out to be rarely used relative to MySQL/PostgreSQL/SQLite, every consumer still pays the (small, autoload-lazy, but nonzero) maintenance-surface and conceptual-complexity cost of a monolith sized for 6 engines |
| The `DatabaseSession`/`SchemaManager`/`DdlManager`/`QueryManager` aggregate-facade layer stays thin and doesn't accumulate special-case logic over time | If it doesn't stay thin, SQLCraft re-derives its own version of Adminer's god-object problem (`Adminer` class mixing many concerns) one abstraction layer removed, just with better types |

---

## 5. What Is NOT Covered That Should Be

**Governance/contribution model** — genuinely absent from all 26 documents, not deferred on purpose (`24-open-questions.md` §8.3). Low urgency but a real gap.

**A concrete answer to Adminer's "search across tables" cross-table search feature** — `04-feature-inventory.md` §14 names it (`Capability::CrossTableSearch`) but no document designs the service that implements it; it exists only as a capability flag with no home. This is flagged in `22-migration-map.md`'s "genuine capability gaps" section but is worth restating here as a real design gap, not just a roadmap item.

**Concurrency/locking behavior across the connection-per-request-vs-persistent-connection question** (`24-open-questions.md` §1.3) is under-specified to the point that it is not clear whether SQLCraft's design has even fully committed to "one PDO handle per `DatabaseSession`, never shared" as an invariant, or merely assumes it informally.

**A explicit backward-compatibility test for the capability enum's "additive is always safe" claim** (`18-public-api.md` §10) against the *specific, real* pattern of consumers doing an exhaustive `match` over `Capability::cases()` — the doc acknowledges this is a known risk for such consumers but does not describe any tooling (a PHPStan rule discouraging exhaustive `match` over this specific enum, for instance) to actually reduce the risk, only documentation warning about it.

**Any discussion of how `ColumnMeta`/`IndexMeta`/etc. DTOs handle genuinely vendor-specific metadata that has no cross-engine equivalent at all** (e.g., PostgreSQL's `pg_attribute.attidentity`, MySQL's generated-column `stored`/`virtual` distinction beyond the single `generated` boolean already modeled, Oracle's `RAW`/`LONG RAW` legacy type quirks) — the DTOs as sketched in `05-domain-model.md` are reasonably rich but there is no stated escape hatch (an `extra: array<string, mixed>` bag, or per-platform DTO subclasses) for capturing engine-specific metadata the shared shape simply doesn't have a field for. Adminer's loose arrays had this flexibility "for free" (any driver could stuff an extra key into the array); SQLCraft's `readonly` typed DTOs have deliberately traded that flexibility away, and no document names what replaces it.

---

## 6. Recommended Actions Before Implementation Begins

1. **Reconcile the `13-ddl-services.md` vs `18-public-api.md` builder-mutability contradiction (2.7's sibling issue, §3.2 in doc 24) and the `DriverRegistry` static-vs-instance contradiction (2.8) as the very first task of M1** — both are cheap to fix now and expensive to fix once code and tests exist on top of the wrong version of either.
2. **Run the Packagist/GitHub name-availability check (§3, §8.1 of doc 24) this week** — it is a five-minute task blocking nothing else, with no reason to leave it open into M10.
3. **Spike the unbuffered-cursor-interleaving correctness question (2.4) before M6 locks in `QueryExecutor`'s streaming default** — this is a correctness gap, not a style preference, and is cheapest to resolve before `ResultInterface`'s contract is implemented and tested against, not after.
4. **Add the explicit scope-limitation language to `13-ddl-services.md` (ALTER TABLE, 2.5) and `05-domain-model.md`/`13-ddl-services.md` (round-trip guarantee, 2.7) before M4/M5 implementation starts**, so the implementers building against these docs don't inherit an overconfident spec.
5. **Time-box an actual Oracle-in-CI feasibility spike now, not at the start of M8 as currently planned** — if Oracle CI genuinely isn't feasible, that changes M8's sizing and risk profile enough that it's worth knowing before five other milestones are built on the assumption that six-engine CI parity is achievable.

---

## 7. Verdict

The architecture is implementable as described, and the parts that matter most — the hexagonal boundary, the capability model, the rejection of Adminer's global state and magic dispatch — are genuinely sound engineering, not just confident prose. But the plan has real internal inconsistencies (2.5, 2.7, 2.8) that only surfaced because this review cross-referenced documents against each other rather than reading each in isolation, which is itself evidence that 26 documents produced before any code is more than this project needed to reach a buildable starting point, and that the marginal value of documents 20+ was lower than documents 05-16. The single biggest threat to success is not any individual technical weakness above — all of them are fixable with a paragraph of amendment or a day of spike work — it is the compounding cost of continuing to plan in prose instead of starting M1: every additional planning document adds surface area for exactly the kind of cross-document contradiction this review found twice, and the only tool that reliably catches those contradictions (real code, real tests, a real compiler) has not been used once in this entire 26-document effort. Start writing code.
