# `AbstractPlatformDecorator` — Platform Decoration Helper

> **Authoritative replacement:** `docs/other/plans/extensions-revised/04-implementation-handoff.md` and `03-verification.md`. This document is retained for history and is not an active implementation requirement.


> **Status:** SUPERSEDED — historical reference only
> **Phase:** 0 (Foundation — blocking)
> **Namespace:** `SQLCraft\Extension\`
> **Adminer equivalent:** Engine-flavor plugins (`mysql.inc.php` variations, MariaDB-specific tweaks)

---

## 1. The Problem

Adminer's platform differences are handled via inclusion of different driver `.inc.php` files and conditional function overrides. Plugins can override methods like `operators()`, `tableName()`, and driver-specific behavior by shadowing them.

SQLCraft's platforms are declared `final` (per `02-guiding-principles.md`: "final classes by default"), preventing subclassing. A consumer who wants to tweak `MySQLPlatform` behavior — say, disable `ON DELETE CASCADE` in DDL, or add a company-specific operator — cannot subclass.

**The solution is decoration**, but implementing `PlatformInterface` naively requires 20+ delegate methods. This boilerplate is the barrier that prevents extension authors from using the pattern.

---

## 2. `AbstractPlatformDecorator`

### File

`src/Extension/AbstractPlatformDecorator.php`

### Class Specification

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Extension;

use SQLCraft\Capabilities\CapabilitySet;
use SQLCraft\Contracts\Connection\ConnectionInterface;
use SQLCraft\Contracts\Platform\DdlDialectInterface;
use SQLCraft\Contracts\Platform\IntrospectionDialectInterface;
use SQLCraft\Contracts\Platform\PaginationInterface;
use SQLCraft\Contracts\Platform\PlatformInterface;
use SQLCraft\Contracts\Platform\QuotingInterface;
use SQLCraft\Contracts\Platform\TypeMapperInterface;
use SQLCraft\DDL\Definition\AlterTableDefinitionInterface;
use SQLCraft\DDL\Definition\CheckConstraintDefinitionInterface;
use SQLCraft\DDL\Definition\ColumnDefinitionInterface;
use SQLCraft\DDL\Definition\ForeignKeyDefinitionInterface;
use SQLCraft\DDL\Definition\IndexDefinitionInterface;
use SQLCraft\DTO\ColumnMeta;
use SQLCraft\DTO\ForeignKeyMeta;
use SQLCraft\DTO\IndexMeta;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\ValueObjects\QualifiedName;
use SQLCraft\ValueObjects\ServerVersion;

/**
 * Delegate-by-default decorator base for PlatformInterface.
 *
 * Extend this class and override only the methods you want to change.
 * All other methods forward to the wrapped platform unchanged.
 *
 * Usage:
 *
 *   final class ForcedCharsetPlatform extends AbstractPlatformDecorator
 *   {
 *       public function __construct(
 *           PlatformInterface  $inner,
 *           private readonly string $charset,
 *       ) {
 *           parent::__construct($inner);
 *       }
 *
 *       public function getDefaultCharset(): ?string
 *       {
 *           return $this->charset;
 *       }
 *   }
 *
 * @api
 */
abstract class AbstractPlatformDecorator implements PlatformInterface
{
    public function __construct(protected readonly PlatformInterface $inner) {}

    // ─────────────────────────────────────────────
    // PlatformInterface (non-dialect methods)
    // ─────────────────────────────────────────────

    #[\Override]
    public function getName(): string
    {
        return $this->inner->getName();
    }

    #[\Override]
    public function getFlavor(): ?string
    {
        return $this->inner->getFlavor();
    }

    #[\Override]
    public function getServerVersion(ConnectionInterface $connection): ServerVersion
    {
        return $this->inner->getServerVersion($connection);
    }

    #[\Override]
    public function getCapabilitySet(ServerVersion $version): CapabilitySet
    {
        return $this->inner->getCapabilitySet($version);
    }

    #[\Override]
    public function getDefaultCharset(): ?string
    {
        return $this->inner->getDefaultCharset();
    }

    #[\Override]
    public function getDefaultCollation(): ?string
    {
        return $this->inner->getDefaultCollation();
    }

    #[\Override]
    public function supportsSchemas(): bool
    {
        return $this->inner->supportsSchemas();
    }

    #[\Override]
    public function getKeywordList(): array
    {
        return $this->inner->getKeywordList();
    }

    #[\Override]
    public function getOperators(): array
    {
        return $this->inner->getOperators();
    }

    #[\Override]
    public function getSupportedAggregateFunctions(): array
    {
        return $this->inner->getSupportedAggregateFunctions();
    }

    // ─────────────────────────────────────────────
    // QuotingInterface
    // ─────────────────────────────────────────────

    #[\Override]
    public function quoteIdentifier(Identifier $identifier): string
    {
        return $this->inner->quoteIdentifier($identifier);
    }

    // ─────────────────────────────────────────────
    // PaginationInterface
    // ─────────────────────────────────────────────

    #[\Override]
    public function applySingleRowLimit(string $sql, string $whereClause): string
    {
        return $this->inner->applySingleRowLimit($sql, $whereClause);
    }

    // ─────────────────────────────────────────────
    // TypeMapperInterface
    // ─────────────────────────────────────────────

    // All TypeMapperInterface methods delegate to $this->inner.
    // (Method signatures verified against src/Contracts/Platform/TypeMapperInterface.php)

    // ─────────────────────────────────────────────
    // DdlDialectInterface
    // ─────────────────────────────────────────────

    #[\Override]
    public function renderDropTableStatement(QualifiedName $table, bool $ifExists, bool $cascade): string
    {
        return $this->inner->renderDropTableStatement($table, $ifExists, $cascade);
    }

    #[\Override]
    public function renderCreateViewStatement(
        QualifiedName $name,
        string $selectSql,
        bool $orReplace,
        array $columns,
        ?string $checkOption,
    ): string {
        return $this->inner->renderCreateViewStatement($name, $selectSql, $orReplace, $columns, $checkOption);
    }

    #[\Override]
    public function renderAlterTableStatement(AlterTableDefinitionInterface $alterTable): array
    {
        return $this->inner->renderAlterTableStatement($alterTable);
    }

    // ... (all remaining DdlDialectInterface methods delegate identically)

    // ─────────────────────────────────────────────
    // IntrospectionDialectInterface
    // ─────────────────────────────────────────────

    // All IntrospectionDialectInterface methods delegate to $this->inner.
    // (Full method list verified against src/Contracts/Platform/IntrospectionDialectInterface.php)
}
```

**Note on completeness:** The stub above shows the delegation pattern. The actual implementation must enumerate every method from all five composed interfaces (`PlatformInterface`, `DdlDialectInterface`, `IntrospectionDialectInterface`, `PaginationInterface`, `QuotingInterface`, `TypeMapperInterface`). The complete list of ~40 methods must be read from the current interface files before implementation to avoid drift.

---

## 3. Concrete Example Decorators

These ship with SQLCraft as reference implementations in `src/Extension/Platform/`.

### 3.1 `ReadOnlyPlatformDecorator`

Disables all write-producing DDL by throwing on any mutation DDL method:

```php
// src/Extension/Platform/ReadOnlyPlatformDecorator.php
final class ReadOnlyPlatformDecorator extends AbstractPlatformDecorator
{
    #[\Override]
    public function renderDropTableStatement(QualifiedName $table, bool $ifExists, bool $cascade): string
    {
        throw new ReadOnlyViolationException("DDL writes are disabled in read-only mode.");
    }

    // Override all other render*() methods similarly
}
```

### 3.2 `CapabilityOverridePlatformDecorator`

Allows consumers to add or remove capabilities without re-implementing a full platform:

```php
// src/Extension/Platform/CapabilityOverridePlatformDecorator.php
final class CapabilityOverridePlatformDecorator extends AbstractPlatformDecorator
{
    /** @param Capability[] $add @param Capability[] $remove */
    public function __construct(
        PlatformInterface $inner,
        private readonly array $add = [],
        private readonly array $remove = [],
    ) {
        parent::__construct($inner);
    }

    #[\Override]
    public function getCapabilitySet(ServerVersion $version): CapabilitySet
    {
        $set = $this->inner->getCapabilitySet($version);
        foreach ($this->add as $cap) {
            $set = $set->with($cap);
        }
        foreach ($this->remove as $cap) {
            $set = $set->without($cap);
        }
        return $set;
    }
}
```

---

## 4. How to Wire in `DriverRegistry`

Platform decorators are wired at the driver level:

```php
// Consumer's bootstrap — wrap the existing MySQL driver's platform
$decorated = new class(new MySQLDriver($pdo, new MySQLPlatform)) implements DriverInterface {
    public function __construct(private readonly MySQLDriver $inner) {}

    public function connect(ConnectionParameters $params): ConnectionInterface
    {
        return $this->inner->connect($params);
    }

    public function getPlatform(ConnectionInterface $connection): PlatformInterface
    {
        return new ForcedCharsetPlatform(
            $this->inner->getPlatform($connection),
            charset: 'utf8mb4',
        );
    }

    public function getName(): string { return $this->inner->getName(); }
    public function getFlavor(): ?string { return $this->inner->getFlavor(); }
    public function buildDsn(ConnectionParameters $params): string { return $this->inner->buildDsn($params); }
    public function getPdoDriverNames(): array { return $this->inner->getPdoDriverNames(); }
};

$registry->register($decorated);
```

For simpler wrapping, a `DriverDecorator` base class follows the same pattern — see `06-built-in-extensions.md §3`.

---

## 5. `AbstractDriverDecorator`

Follows the same pattern as `AbstractPlatformDecorator`, for consumers who want to wrap a whole driver:

```php
// src/Extension/AbstractDriverDecorator.php
abstract class AbstractDriverDecorator implements DriverInterface
{
    public function __construct(protected readonly DriverInterface $inner) {}

    #[\Override]
    public function buildDsn(ConnectionParameters $params): string
    {
        return $this->inner->buildDsn($params);
    }

    #[\Override]
    public function connect(ConnectionParameters $params): ConnectionInterface
    {
        return $this->inner->connect($params);
    }

    #[\Override]
    public function getPlatform(ConnectionInterface $connection): PlatformInterface
    {
        return $this->inner->getPlatform($connection);
    }

    #[\Override]
    public function getName(): string { return $this->inner->getName(); }

    #[\Override]
    public function getPdoDriverNames(): array { return $this->inner->getPdoDriverNames(); }
}
```

Usage — inject a custom platform without rewriting the whole driver:

```php
final class AcmeCharsetMysqlDriver extends AbstractDriverDecorator
{
    #[\Override]
    public function getPlatform(ConnectionInterface $connection): PlatformInterface
    {
        return new ForcedCharsetPlatform(
            $this->inner->getPlatform($connection),
            charset: 'latin1',
        );
    }
}
```

---

## 6. Implementation Notes

1. **Do not add any logic to `AbstractPlatformDecorator` beyond delegation.** All behavioral variants belong in concrete decorator subclasses.
2. **Generate the full delegation from the interface files**, not from the `AbstractPlatform` concrete class. Coupling to the concrete class will create a maintenance burden when `AbstractPlatform` gains internal methods.
3. **Verify all sub-interface method signatures** before writing, because `PlatformInterface extends DdlDialectInterface, IntrospectionDialectInterface, PaginationInterface, QuotingInterface, TypeMapperInterface` — that's 5 interfaces, each read from `src/Contracts/Platform/`.
4. **PHP 8.4 readonly on constructor param** — `$this->inner` is `readonly` on the constructor. Subclasses that need to call `parent::__construct()` must pass the inner platform.
5. **No `@internal` on this class** — it is `@api` because extension authors depend on it.

---

## 7. Testing Requirements

| Test | Type |
|---|---|
| Unoverridden methods delegate to inner | Unit (mock inner, verify forwarding) |
| Overridden methods return new value | Unit |
| `ForcedCharsetPlatform` returns configured charset | Unit |
| `CapabilityOverridePlatformDecorator` adds/removes caps | Unit |
| Decorated driver registers and resolves in `DriverRegistry` | Integration |
| Decorated platform's `getCapabilitySet()` used by `SchemaManager` | Integration |

---

## 8. File Summary

| File | New/Modified |
|---|---|
| `src/Extension/AbstractPlatformDecorator.php` | 🆕 New |
| `src/Extension/AbstractDriverDecorator.php` | 🆕 New |
| `src/Extension/Platform/ReadOnlyPlatformDecorator.php` | 🆕 New |
| `src/Extension/Platform/CapabilityOverridePlatformDecorator.php` | 🆕 New |
| `tests/Extension/AbstractPlatformDecoratorTest.php` | 🆕 New |
| `tests/Extension/AbstractDriverDecoratorTest.php` | 🆕 New |
