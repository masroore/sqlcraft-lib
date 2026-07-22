# M10 Public API Audit

Date: 2026-07-21

Scope: the implementation at the M10 convergence point, compared with
`docs/plans/18-public-api.md §7` and the package structure in
`docs/plans/19-package-structure.md`.

## Stable surface

The following namespaces are intentionally consumer-facing and are covered by
SemVer unless a member is explicitly marked `@internal`:

- `SQLCraft\Contracts\*` — extension and dependency-injection contracts.
- `SQLCraft\ValueObjects\*`, `SQLCraft\DTO\*`, and `SQLCraft\Collections\*` —
  immutable domain data.
- `SQLCraft\Exceptions\*` — expected failure hierarchy.
- `SQLCraft\Capabilities\Capability`, `CapabilitySet`,
  `ExtendedCapability`, and `CapabilityNotSupportedException`.
- `SQLCraft\Connection\Transaction` and `ConnectionInterface`'s connection
  boundary; PDO remains private to the connection implementation.
- `SQLCraft\Driver\DriverRegistry` and the built-in driver/platform adapters.
- `SQLCraft\Schema\*`, `SQLCraft\DDL\*`, `SQLCraft\Execution\*`, and
  `SQLCraft\Query\*` service and builder APIs.
- `SQLCraft\Import\*` and `SQLCraft\Export\*` format, option, source, sink,
  and orchestration APIs.
- `SQLCraft\Events\*` and `SQLCraft\Security\*` event and validation APIs.
- `SQLCraft\Support\*` pure, stateless utility APIs.

Public API examples use interfaces, value objects, and manager methods. Concrete
manager constructor signatures are composition details; consumers should prefer
factory methods and dependency-injection contracts where available.

## Internal surface

Every concrete class in the following list is explicitly marked `@internal` and
is excluded from compatibility promises:

- `Connection\ConnectionFactory`, `PdoConnection`, `PdoConnectionFactory`,
  `PdoExceptionTranslator`, `PdoPreparedStatement`, `TransactionManager`, and
  `Connection\Result\{BufferedResult,StreamingResult}`.
- `Metadata\*` implementations and `MetadataFactoryInterface`.
- `DDL\Sqlite\TableRecreationStrategy`.
- `Platform\SqlitePlatform` (the M2 implementation stub).

The audit found no concrete class intended to be internal without an annotation.
The only internal implementation detail not represented by a class annotation is
platform SQL template text and the capability matrix shape; both are documented
as internal in the public API plan.

## Deferred design entries

`SQLCraftFactory` and `DatabaseSession` are the planned convenience composition
root described in `18-public-api.md §2.2`. The current release exposes the same
composition explicitly through `DriverRegistry`, driver adapters, and the typed
manager factories. The convenience aggregate is not claimed as an implemented
v1.0 class until its complete service graph exists.

`SecurityGuardInterface` is likewise a planned higher-level facade; the shipped
security surface is the construction-time validation and allowlisting layer in
`SQLCraft\Security` plus typed exceptions.

Oracle (`OraclePlatform`, `OracleDriver`, `pdo_oci`, and Oracle integration) is
explicitly deferred. No Oracle class, extension, service, or compatibility claim
is included in this release.

## Verification

- `composer run ci`: green (PHPStan max, Psalm max, CS-Fixer, deptrac, Rector,
  unit tests, and golden tests).
- All eight `examples/*/run.php` scripts: green against in-memory SQLite.
- No `\PDO` or `\PDOStatement` references exist outside `SQLCraft\Connection`.
- `php` Compose service has no engine `depends_on` entries.
