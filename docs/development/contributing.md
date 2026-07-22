# Contributing to SQLCraft

Thank you for considering contributing to SQLCraft! This guide will help you get started.

## Code of Conduct

Be respectful, inclusive, and constructive. We're building software together.

## Getting Started

### 1. Fork and Clone

```bash
# Fork on GitHub, then clone
git clone https://github.com/YOUR_USERNAME/sqlcraft.git
cd sqlcraft
```

### 2. Set Up Development Environment

```bash
# Copy environment file
cp .env.example .env

# Build Docker containers
docker compose build php

# Install dependencies
docker compose run --rm php composer install

# Run tests
docker compose run --rm php composer test
```

### 3. Create a Branch

```bash
git checkout -b feature/your-feature-name
# or
git checkout -b fix/bug-description
```

## Development Workflow

### Running Tests

```bash
# Unit tests only (fast, no database)
composer test

# Integration tests (requires database services)
docker compose up -d mysql postgres sqlserver
composer test:integration

# Contract tests (platform conformance)
composer test:contract

# Golden file tests (SQL snapshot tests)
composer test:golden

# All tests
composer test:all
```

### Code Quality

```bash
# Static analysis
composer stan     # PHPStan level max
composer psalm    # Psalm analysis

# Code style
composer cs       # Check style
composer cs:fix   # Fix style automatically

# Rector (automated refactoring)
composer rector         # Check
composer rector:fix     # Apply

# Architecture validation
composer deptrac        # Check layer boundaries

# Mutation testing
composer infection      # Requires 80% MSI

# Run all checks
composer ci
```

### Writing Tests

#### Unit Tests

```php
namespace SQLCraft\Tests\Unit\ValueObjects;

use PHPUnit\Framework\TestCase;
use SQLCraft\ValueObjects\Identifier;
use SQLCraft\Exceptions\InvalidIdentifierException;

class IdentifierTest extends TestCase
{
    public function testValidIdentifier(): void
    {
        $id = new Identifier('users');
        $this->assertSame('users', $id->value);
    }
    
    public function testRejectsEmptyIdentifier(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        new Identifier('');
    }
    
    public function testRejectsNullByte(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        new Identifier("table\0name");
    }
}
```

#### Integration Tests

```php
namespace SQLCraft\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SQLCraft\SQLCraftFactory;
use SQLCraft\ValueObjects\ConnectionParameters;

class SchemaIntrospectionTest extends TestCase
{
    private DatabaseSession $db;
    
    protected function setUp(): void
    {
        $factory = new SQLCraftFactory();
        $this->db = $factory->session(
            new ConnectionParameters(database: ':memory:')
        );
        
        // Set up test schema
        $this->db->query('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    }
    
    public function testListTables(): void
    {
        $tables = $this->db->schema()->listTables();
        
        $this->assertCount(1, $tables);
        $this->assertTrue($tables->has('users'));
    }
}
```

#### Contract Tests

```php
namespace SQLCraft\Tests\Contract;

use SQLCraft\Tests\Contract\PlatformConformanceTestCase;

class SqlitePlatformConformanceTest extends PlatformConformanceTestCase
{
    protected function getPlatformName(): string
    {
        return 'sqlite';
    }
    
    protected function getConnectionParameters(): ConnectionParameters
    {
        return new ConnectionParameters(database: ':memory:');
    }
}
```

### Code Style

Follow PSR-12 with these additions:

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Module;

use SQLCraft\Contracts\SomeInterface;

final readonly class ExampleClass implements SomeInterface
{
    public function __construct(
        private string $property,
        private int $another,
    ) {
    }
    
    public function method(string $param): string
    {
        return $this->property . $param;
    }
}
```

**Key points:**
- `declare(strict_types=1);` on every PHP file
- Type hints on all parameters and return types
- `readonly` on immutable classes
- `final` on classes not designed for extension
- Named parameters in calls with 3+ arguments

## Architecture Guidelines

### Layer Boundaries

Follow the hexagonal architecture:

```
Contracts (interfaces only)
  ↑
ValueObjects, DTOs, Collections (immutable data)
  ↑
Domain Services (SchemaManager, DdlManager, QueryExecutor)
  ↑
Platform/Driver (MySQL, PostgreSQL, SQLite, SQL Server)
  ↑
Connection (PDO wrapper)
```

**Rules enforced by Deptrac:**
- `Contracts` depends on nothing
- `ValueObjects` depend only on `Support`
- `Platform` cannot depend on `Connection` concretes
- No circular dependencies

### Naming Conventions

| Type | Pattern | Example |
|------|---------|---------|
| Interface | `*Interface` | `ConnectionInterface` |
| Value Object | Noun | `Identifier`, `DataType` |
| DTO | `*Meta`, `*Status`, `*Info` | `ColumnMeta`, `TableStatus` |
| Collection | `*Collection` | `TableCollection` |
| Exception | `*Exception` | `ConnectionFailedException` |
| Event | `*Event`, `After*`, `Before*` | `QueryExecutedEvent` |
| Builder | `*Builder` | `CreateTableBuilder` |

### Immutability

Value objects and DTOs must be immutable:

```php
// ✅ Good - immutable
final readonly class DataType
{
    public function withPrecision(int $precision): self
    {
        return new self(
            name: $this->name,
            precision: $precision,
            scale: $this->scale
        );
    }
}

// ❌ Bad - mutable
class DataType
{
    public function setPrecision(int $precision): void
    {
        $this->precision = $precision;
    }
}
```

### Error Handling

Always throw typed exceptions:

```php
// ✅ Good - typed exception
if (!$this->isValid($identifier)) {
    throw new InvalidIdentifierException(
        "Identifier cannot be empty",
        identifier: $identifier
    );
}

// ❌ Bad - generic exception
if (!$this->isValid($identifier)) {
    throw new \RuntimeException("Invalid identifier");
}
```

## Pull Request Process

### 1. Ensure CI Passes

```bash
# Run full CI locally
composer ci

# Must pass:
# - PHPStan level max
# - Psalm
# - PHP CS Fixer
# - Deptrac
# - Rector
# - All unit tests
```

### 2. Write Tests

- Unit tests for all new logic
- Integration tests for database interactions
- Contract tests for new platform implementations
- Golden tests for SQL generation changes

### 3. Update Documentation

- Add docblocks to public methods
- Update relevant markdown files in `docs/`
- Add examples for new features
- Update CHANGELOG.md

### 4. Create Pull Request

**Title format:**
- `feat: add sequence support for PostgreSQL`
- `fix: handle null in column default values`
- `docs: update import/export examples`
- `test: add coverage for DDL builders`
- `refactor: extract query rendering logic`

**Description should include:**
- What changed and why
- Related issues (`Fixes #123`)
- Breaking changes (if any)
- Migration guide (if needed)

### 5. Code Review

- Respond to feedback promptly
- Keep discussions focused
- Request clarification if needed
- Update based on review

## Adding a New Feature

### Example: Adding Trigger Support

1. **Create contracts**:
```php
// src/Contracts/DDL/CreateTriggerBuilderInterface.php
interface CreateTriggerBuilderInterface
{
    public function before(string $event): self;
    public function after(string $event): self;
    public function toSql(): string;
}
```

2. **Add value objects/DTOs**:
```php
// src/DTO/TriggerMeta.php
final readonly class TriggerMeta
{
    public function __construct(
        public string $name,
        public string $table,
        public string $timing,
        public string $event,
        public string $body,
    ) {}
}
```

3. **Implement builders**:
```php
// src/DDL/CreateTriggerBuilder.php
final class CreateTriggerBuilder implements CreateTriggerBuilderInterface
{
    // Implementation
}
```

4. **Add platform support**:
```php
// src/Platform/MySQL/MySQLPlatform.php
public function createTrigger(string $name, string $table): string
{
    // MySQL-specific SQL
}
```

5. **Write tests**:
```php
// tests/Unit/DDL/CreateTriggerBuilderTest.php
// tests/Integration/MySQL/TriggerTest.php
// tests/Contract/TriggerConformanceTest.php
```

6. **Update documentation**:
```markdown
<!-- docs/user-guide/ddl-operations.md -->
## Creating Triggers

...
```

## Release Process

Maintainers handle releases:

1. Update CHANGELOG.md
2. Bump version in relevant files
3. Tag release: `git tag v1.2.0`
4. Push: `git push origin v1.2.0`
5. GitHub Actions publishes to Packagist

## Community

- **Issues**: Report bugs or request features
- **Discussions**: Ask questions or share ideas
- **Pull Requests**: Submit changes

## License

By contributing, you agree that your contributions will be licensed under the MIT License.

## Getting Help

- Read the [documentation](../README.md)
- Check existing [issues](https://github.com/vendor/sqlcraft/issues)
- Ask in [discussions](https://github.com/vendor/sqlcraft/discussions)

Thank you for contributing! 🎉
