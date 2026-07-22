# Audit 01 — Foundation Area
## Contracts · ValueObjects · DTO · Collections · Exceptions · Support · Capabilities

> Audit date: 2026-07-21
> Auditor: automated read-only pass (Kiro CLI)
> Sources consulted: docs/plans/02, 04, 05, 07 (§1-4, 11), 09, 18; src/Contracts, src/ValueObjects, src/DTO, src/Collections, src/Exceptions, src/Support, src/Capabilities; tests/Unit (Capabilities, Collections, Contracts, DTO, Exceptions, Support, ValueObjects); deptrac.yaml; composer.json

---

## HIGH Severity

### H-1 · `LazyCollection` promised in doc 07 §4 — class is entirely absent

**Promise:** doc 07 §4 explicitly states: *"Deferred loading is provided via `LazyCollection` which wraps a `\Closure` producer — the collection materialises on first iteration. Application services return `LazyCollection` for large result sets (e.g., all tables in a database with thousands of tables)."*

**Reality:** No `LazyCollection` file exists anywhere under `src/Collections/`. The word `LazyCollection` does not appear in any source file.

**Impact:** Every collection-returning method is eager-only. Databases with thousands of tables or columns materialise the entire result into memory on fetch. The performance contract the plan sells is undeliverable.

**Fix:** Add `src/Collections/LazyCollection.php` implementing `IteratorAggregate`/`Countable` with a `\Closure` producer; wire it into `AbstractImmutableCollection` as an optional lazy path.

---

### H-2 · `SchemaInspectorInterface` entirely absent — `src/Contracts/Schema/` is empty

**Promise:** doc 07 §1 lists `SchemaInspectorInterface` as a key Contracts interface. Doc 07 §5 names it as the high-level "compare two schemas, produce diff" API. Doc 18 §5 shows `$db->schema()->compare()` backed by `SchemaInspectorInterface`. Doc 07 §11 dependency table places Schema above Metadata.

**Reality:** `src/Contracts/Schema/` contains only `.gitkeep`. No `SchemaInspectorInterface.php` exists. The concrete `SchemaManager` (in `src/Schema/`) has no corresponding contract in `Contracts\Schema`.

**Impact:** The schema-diff entry point has no interface contract, breaking the DIP principle and making `SchemaManager` un-mockable via the Contracts layer.

**Fix:** Add `src/Contracts/Schema/SchemaInspectorInterface.php` defining `compare()`, `describeDiff()`, and related method signatures; add `SchemaManagerInterface` if needed.

---

### H-3 · `SecurityGuardInterface` entirely absent — `src/Contracts/Security/` is empty

**Promise:** doc 07 §1 lists `SecurityGuardInterface`. Doc 07 §10 names Security as a module with `SecurityGuard` implementing `SecurityGuardInterface`. Doc 18 §2.2 shows `DatabaseSession::security(): SecurityGuardInterface`.

**Reality:** `src/Contracts/Security/` contains only `.gitkeep`. No `SecurityGuardInterface.php` exists.

**Impact:** The security surface has no typed contract. `DatabaseSession::security()` cannot have a correct return type. Callers cannot mock security checks in unit tests.

**Fix:** Add `src/Contracts/Security/SecurityGuardInterface.php` with at least `can(string $action, QualifiedName $object): bool` and `require(...)` methods.

---

### H-4 · `QueryBuilderInterface` absent from `Contracts\Query`

**Promise:** doc 07 §1 lists `QueryBuilderInterface` as a key Contracts interface. Doc 07 §9 says Query depends on Contracts. Doc 18 §3.6 shows a full fluent query-builder public API backed by this interface.

**Reality:** `src/Contracts/Query/` contains `.gitkeep` and only `TableStatusProviderInterface.php`. No `QueryBuilderInterface.php` exists.

**Impact:** The fluent query-builder has no interface. Consumers cannot inject a typed query-builder or mock it; the contract boundary is broken.

**Fix:** Add `src/Contracts/Query/QueryBuilderInterface.php` covering `from()`, `where()`, `orderBy()`, `paginate()`, `toSql()`, `execute()`.

---

### H-5 · Capability enum missing ~20 cases named in doc 04 feature inventory

**Promise:** doc 04 is the exhaustive feature inventory mapping every Adminer capability to a `Capability::` case. It names cases including `QueryTimeout`, `DatabaseRename`, `TableMove`, `TableAnalyze`, `TableOptimize`, `TableCheck`, `TableRepair`, `Vacuum`, `TableInheritance`, `AutoIncrement`, `EnumSetTypes`, `OnUpdateClause`, `FullTextIndex`, `SpatialIndex`, `VectorIndex`, `IndexAlgorithms`, `IndexPrefixLength`, `CrossSchemaForeignKeys`, `DeferrableForeignKeys`, `TableEngines` (20 distinct cases).

**Reality:** `src/Capabilities/Capability.php` has 35 cases, all matching doc 09 §2's shorter list. None of the 20 doc-04-named cases above appear (by any name) in the enum or in the codebase.

**Impact:** 20 named features from the complete feature inventory cannot be type-safely gated. Code that needs to check "does this engine support fulltext indexes" must either use an undeclared string or skip the capability check entirely, reintroducing Adminer's type-unsafe `support("string")` anti-pattern.

**Note:** The enum follows doc 09 (which is the final design doc). The issue is that doc 04 is never reconciled with doc 09, so the feature inventory is materially incomplete against the implemented capability set.

**Fix:** For each of the 20 missing features, either add a case to `Capability` enum matching the doc 09 naming style, or update doc 04 to map each feature to an existing case.

---

### H-6 · `QueryTimeoutException` absent — promised in doc 02 §8 and reinforced by doc 04

**Promise:** doc 02 §8 exception hierarchy includes `QueryException → QueryTimeoutException`. Doc 04 §1 (Server/Connection features) explicitly names `Capability::QueryTimeout` as a named feature with "Driver-level statement timeout where supported."

**Reality:** No `QueryTimeoutException` exists in `src/Exceptions/`. No `QueryTimeout` case exists in `Capability.php`. There is no timeout-related class anywhere in src/.

**Impact:** Query timeout enforcement — a first-class Adminer feature — has no typed exception path and no capability gate. Timed-out queries produce unclassified `QueryException` payloads; callers cannot distinguish a timeout from a syntax error.

**Fix:** Add `src/Exceptions/QueryTimeoutException.php extends QueryException`; add `case QueryTimeout = 'query_timeout'` to `Capability` enum.

---

## MED Severity

### M-1 · `QueryException` and `ConstraintViolationException` not `final` — doc 05 §9 contract broken

**Promise:** doc 05 §9 states: *"All exceptions are `final` classes extending the hierarchy."*

**Reality:**
- `src/Exceptions/QueryException.php` is declared `class QueryException` (not `final`).
- `src/Exceptions/ConstraintViolationException.php` is declared `class ConstraintViolationException` (not `final`).

All other concrete leaf exceptions (`SyntaxErrorException`, `DeadlockException`, `UniqueConstraintException`, etc.) are correctly `final`.

**Impact:** Third-party code can silently subclass `QueryException` or `ConstraintViolationException`, creating undeclared subtypes that may bypass `catch` hierarchies. The "closed hierarchy" contract is weakened.

**Fix:** Add `final` to both class declarations.

---

### M-2 · Doc 02 §9 capability enum is stale and contradicts doc 09 §2 and code

**Promise:** doc 02 §9 defines a `Capability` enum with cases: `Schemas='schemas'`, `MaterializedViews='materialized_view'`, `DeferrableForeignKeys='deferrable_fk'`, `StoredProcedures='procedures'`, `KillProcess='kill_process'`, `QueryTimeout='query_timeout'`, `FullTextSearch='fulltext'`, `ColumnComments='column_comments'`, `TableEngines='table_engines'`, `Collations='collations'`, `DescendingIndexes='desc_indexes'`, `CheckConstraints='check_constraints'`.

**Reality:** The actual code follows doc 09 §2 which uses: `Scheme='scheme'`, `MaterializedView='materializedview'`, `Kill='kill'`, `Comment='comment'`, `Collation='collation'`, `DescendingIndexes='descidx'`, `CheckConstraints='check'`. The doc 02 cases (`DeferrableForeignKeys`, `StoredProcedures`, `KillProcess`, `QueryTimeout`, `FullTextSearch`, `TableEngines`, `Collations`) are entirely absent.

**Impact:** A developer reading doc 02 will write `$caps->has(Capability::Schemas)` and get a parse error; the correct case is `Scheme`. This is a first-class discovery hazard.

**Fix:** Update doc 02 §9 to either reference doc 09 as authoritative or reproduce doc 09's exact case names and string values. Mark doc 02 §9's example block as "superseded by doc 09."

---

### M-3 · `Utilities` module referenced in deptrac.yaml and doc 07 §10 — `src/Utilities/` does not exist; `PaginationCalculator` and `IdentifierSanitizer` absent

**Promise:** doc 07 §10 names the Utilities module explicitly: *"`PaginationCalculator` (page/total → offset/limit), `IdentifierSanitizer` (strips dangerous characters from user input before it reaches an `Identifier` VO)."* The `deptrac.yaml` has a `Utilities` layer entry (`Utilities: [Support]`).

**Reality:** No `src/Utilities/` directory exists. `PaginationCalculator` does not exist (only `PaginationParams` in `src/Query/`). `IdentifierSanitizer` does not exist anywhere. The deptrac layer exists as a declaration but has no source files to enforce.

**Impact:** Input sanitization before `Identifier` construction is undone — the `Identifier` VO validates post-construction, but there is no pre-VO layer to strip dangerous characters from user-supplied names. `PaginationCalculator` logic is either absent or duplicated inline.

**Fix:** Create `src/Utilities/PaginationCalculator.php` and `src/Utilities/IdentifierSanitizer.php`; add tests.

---

### M-4 · No property-based testing library — doc 07 §2 promises property-based tests for VO invariants

**Promise:** doc 07 §2 testing seam: *"Pure value construction; no mocks needed. Property-based tests validate invariants."*

**Reality:** `composer.json` dev dependencies include PHPUnit, infection/infection (mutation testing), PHPStan, Psalm — no property-based testing library (no Eris, no `giorgiosironi/eris`, no `shrikeh/phpunit-testing`, no equivalent). VO tests in `tests/Unit/ValueObjects/` use PHPUnit data providers with manually written edge cases, not property-based generation.

**Impact:** Invariant coverage is limited to what the developer thought to manually enumerate. Property-based testing would automatically discover cases like extreme string lengths, Unicode edge cases, or boundary precision values for `DataType`.

**Fix:** Add a property-based library (e.g., `eris/eris` or equivalent); convert `IdentifierTest`, `DataTypeTest`, and `ServerVersionTest` to include generated-input tests.

---

### M-5 · `deptrac.yaml` grants `Exceptions` layer access to `Capabilities` — contradicts doc 07 §11

**Promise:** doc 07 §11 dependency table: `Exceptions → Contracts, ValueObjects` (no Capabilities). Separately `Capabilities → Contracts, ValueObjects, Exceptions`.

**Reality:** `deptrac.yaml` ruleset includes:
```yaml
Exceptions:
  - Contracts
  - ValueObjects
  - Capabilities   # <-- not in doc 07 §11
```

**Impact:** If any code in `src/Exceptions/` ever imports from `src/Capabilities/`, deptrac will silently allow a dependency that creates a latent cycle risk: Capabilities → Exceptions → Capabilities. Currently no such import exists (the split is clean), but the deptrac rule is wrong and removes the guard.

**Fix:** Remove `Capabilities` from the `Exceptions` allowed-deps list in `deptrac.yaml`.

---

### M-6 · `PlatformCapabilityResolver` not declared `readonly` — doc 02 §7 immutability mandate

**Promise:** doc 02 §7: *"All data returned by SQLCraft is immutable."* and doc 02 §11: *"Readonly classes: All VOs and DTOs are `readonly` classes."*

**Reality:** `src/Capabilities/PlatformCapabilityResolver.php` is declared `final class PlatformCapabilityResolver` without `readonly`. Its constructor properties `$matrix` and `$events` carry `private readonly` individually, making it effectively immutable, but PHP's `readonly` class keyword is not applied.

**Impact:** Low practical risk since all properties are individually readonly, but violates the stated convention and is detectable by Psalm/PHPStan strict-readonly rules.

**Fix:** Change declaration to `final readonly class PlatformCapabilityResolver`.

---

## LOW Severity

### L-1 · `CapabilityNotSupportedException` lives in `SQLCraft\Capabilities`, not `SQLCraft\Exceptions` — namespace split

**Promise:** doc 05 §9 locates the full exception hierarchy under `SQLCraft\Exceptions`. Doc 09 §10 places `CapabilityNotSupportedException` in `SQLCraft\Capabilities` namespace.

**Reality:** `src/Capabilities/CapabilityNotSupportedException.php` is in namespace `SQLCraft\Capabilities` but extends `SQLCraft\Exceptions\CapabilityException`. All other exceptions live in `SQLCraft\Exceptions`.

**Impact:** `catch (SQLCraft\Exceptions\CapabilityException $e)` works (correct hierarchy), but a consumer looking for all exceptions under `SQLCraft\Exceptions` will miss this class. IDE "find usages" and `\SQLCraft\Exceptions\*` namespace imports don't cover it.

**Fix:** Either move the class to `src/Exceptions/CapabilityNotSupportedException.php` (namespace `SQLCraft\Exceptions`) and add a deprecated alias in `Capabilities\`, or add a doc note in both namespaces acknowledging the placement.

---

### L-2 · `AbstractImmutableCollection` and all concrete collections are not `readonly` classes

**Promise:** doc 02 §7 mandates `readonly` for all immutable data-carriers. Doc 07 §4: *"Collections are immutable."*

**Reality:** `AbstractImmutableCollection` is `abstract class` (not `readonly`). All 21 concrete collections are `final class` (not `final readonly class`). The `$items` property carries `protected readonly array`, so mutation is prevented per-property, but the class itself is not readonly-class-declared.

**Impact:** PHP readonly-class enforcement is absent. A subclass could in theory declare additional mutable properties, bypassing the immutability contract. PHPStan readonly-class rules will not fire.

**Fix:** Declare `abstract readonly class AbstractImmutableCollection`; all concrete collections become implicitly readonly-class-compliant.

---

### L-3 · `SecretRedactor` in `Support` module — undocumented, not listed in any plan

**Promise:** doc 07 §10 lists the Support module's classes as: `StringUtil`, `TypeUtil`, `ArrayUtil`. No others mentioned.

**Reality:** `src/Support/SecretRedactor.php` exists and is not mentioned in any plan document.

**Impact:** Not a correctness gap — `SecretRedactor` is useful (redacts credentials from `ConnectionParameters` in logs). But it is undocumented infrastructure. If Support grows further undocumented utilities, plan docs become misleading.

**Fix:** Add `SecretRedactor` to doc 07 §10 Support entry; it is a legitimate member of the module.

---

### L-4 · `ColumnMeta.privileges` typed `array` without a typed wrapper — doc 02 §11 bans untyped `array`

**Promise:** doc 02 §11: *"`mixed` type — every parameter and return type is explicit."* Doc 02 §7 example shows `ColumnDefinition.privileges` as `Privileges $privileges` (a VO).

**Reality:** `src/DTO/ColumnMeta.php` constructor declares `public array $privileges` with a `@param list<int> $privileges` docblock only. No typed wrapper is used.

**Impact:** PHPStan cannot statically enforce the `list<int>` shape at call sites without a native type. The doc 02 §7 example suggests `Privileges` VO should be used here instead of raw `array`.

**Fix:** Create (or reuse) a `PrivilegesMap` or `ColumnPrivileges` VO wrapping the integer bitmask; replace `array $privileges` in `ColumnMeta`.

---

## Summary Table

| # | Severity | Area | One-line Fix |
|---|----------|------|--------------|
| H-1 | HIGH | Collections | Add `src/Collections/LazyCollection.php` with closure-backed lazy materialisation |
| H-2 | HIGH | Contracts/Schema | Add `src/Contracts/Schema/SchemaInspectorInterface.php` |
| H-3 | HIGH | Contracts/Security | Add `src/Contracts/Security/SecurityGuardInterface.php` |
| H-4 | HIGH | Contracts/Query | Add `src/Contracts/Query/QueryBuilderInterface.php` |
| H-5 | HIGH | Capabilities | Add ~20 missing enum cases from doc 04 (or reconcile doc 04 with doc 09) |
| H-6 | HIGH | Exceptions / Capabilities | Add `QueryTimeoutException` and `Capability::QueryTimeout` |
| M-1 | MED | Exceptions | Mark `QueryException` and `ConstraintViolationException` as `final` |
| M-2 | MED | Doc drift | Update doc 02 §9 to match doc 09 §2 case names |
| M-3 | MED | Utilities | Create `src/Utilities/` with `PaginationCalculator` and `IdentifierSanitizer` |
| M-4 | MED | Testing | Add a property-based testing library and convert VO invariant tests |
| M-5 | MED | deptrac | Remove `Capabilities` from `Exceptions` allowed-deps in `deptrac.yaml` |
| M-6 | MED | Capabilities | Change `PlatformCapabilityResolver` to `final readonly class` |
| L-1 | LOW | Exceptions | Move `CapabilityNotSupportedException` to `SQLCraft\Exceptions` namespace |
| L-2 | LOW | Collections | Declare `AbstractImmutableCollection` and subclasses as `readonly class` |
| L-3 | LOW | Support | Document `SecretRedactor` in doc 07 §10 |
| L-4 | LOW | DTO | Replace `array $privileges` in `ColumnMeta` with a typed VO |
