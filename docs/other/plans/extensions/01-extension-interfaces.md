# Extension Interfaces — `SQLCraft\Extension\` Namespace

> **Status:** PLAN ONLY  
> **Phase:** 0 (Foundation — blocking)  
> **Namespace:** `SQLCraft\Extension\`  
> **Depends on:** `src/Contracts/` interfaces

---

## 1. Overview

The `SQLCraft\Extension\` namespace provides **helper/registry infrastructure** for consumers who register extensions. It does NOT define the primary extension contracts (those live in `SQLCraft\Contracts\`) — it provides:

- `ServiceProviderInterface` — a registration pattern for grouping related extensions
- `ExtensionBundle` — a concrete base class implementing `ServiceProviderInterface`

This design is inspired by Laravel's `ServiceProvider` and Symfony's `Bundle` patterns, adapted to SQLCraft's DI-agnostic model.

---

## 2. `ServiceProviderInterface`

### Purpose

A `ServiceProviderInterface` is a self-contained unit of extension registration. It receives SQLCraft's registries and wires one or more extensions in a single `register()` call. This replaces Adminer's directory-scanned plugin list with an explicit, DI-friendly registration pattern.

### File

`src/Extension/ServiceProviderInterface.php`

### Interface Specification

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Extension;

use SQLCraft\Driver\DriverRegistry;
use SQLCraft\Export\FormatRegistry;
use Psr\EventDispatcher\ListenerProviderInterface;

interface ServiceProviderInterface
{
    /**
     * Register drivers, formats, listeners, and other extensions.
     *
     * Implementations call the passed registry/provider methods directly.
     * The method is called once at bootstrap — not per-request, not per-connection.
     *
     * @api
     */
    public function register(
        DriverRegistry          $drivers,
        FormatRegistry          $formats,
        ListenerProviderInterface $listeners,
    ): void;

    /**
     * Human-readable name of this extension bundle.
     * Used only for error reporting and introspection.
     *
     * @api
     */
    public function getName(): string;
}
```

### Key Design Decisions

1. **Three registries only.** `register()` accepts exactly the three registries covering all three extension mechanisms: drivers (mechanism 2), formats (mechanism 3), and listeners (mechanism 1). Additional service swaps (e.g., swapping `CredentialProviderInterface`) are performed in the consumer's DI container — `ServiceProviderInterface` does not receive a general-purpose container because SQLCraft is container-agnostic.

2. **No `boot()` / two-phase lifecycle.** Unlike Symfony bundles, there is no `boot()` method. SQLCraft's extension points are all registration-time, not request-time. Consumers who need request-time setup use PSR-14 events.

3. **No constructor injection from SQLCraft.** The consumer's DI container creates the `ServiceProvider` and injects its own dependencies. SQLCraft calls `register()` only.

### Usage

```php
// Consumer's bootstrap
$sqlcraft = SQLCraftFactory::create()
    ->withServiceProvider(new AcmeCorporateExtensions($config))
    ->build();

// Or manually (no factory helper):
$serviceProvider = new AcmeCorporateExtensions($config);
$serviceProvider->register($drivers, $formats, $listeners);
```

---

## 3. `ExtensionBundle`

### Purpose

An abstract base class that implements `ServiceProviderInterface` and provides no-op defaults for the `register()` parameters an extension doesn't use. Concrete bundles only override what they need.

### File

`src/Extension/ExtensionBundle.php`

### Class Specification

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Extension;

use SQLCraft\Driver\DriverRegistry;
use SQLCraft\Export\FormatRegistry;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Convenient base class for extension bundles.
 *
 * Override only the registration methods you need.
 * Default implementations are no-ops.
 *
 * @api
 */
abstract class ExtensionBundle implements ServiceProviderInterface
{
    #[\Override]
    final public function register(
        DriverRegistry            $drivers,
        FormatRegistry            $formats,
        ListenerProviderInterface $listeners,
    ): void {
        $this->registerDrivers($drivers);
        $this->registerFormats($formats);
        $this->registerListeners($listeners);
    }

    protected function registerDrivers(DriverRegistry $drivers): void
    {
        // no-op by default
    }

    protected function registerFormats(FormatRegistry $formats): void
    {
        // no-op by default
    }

    protected function registerListeners(ListenerProviderInterface $listeners): void
    {
        // no-op by default
    }

    #[\Override]
    public function getName(): string
    {
        return static::class;
    }
}
```

### Usage Example

```php
// Third-party DuckDB driver extension
final class DuckDbExtension extends ExtensionBundle
{
    public function __construct(private readonly string $duckDbPath) {}

    #[\Override]
    protected function registerDrivers(DriverRegistry $drivers): void
    {
        $drivers->register(new DuckDbDriver($this->duckDbPath));
    }

    #[\Override]
    public function getName(): string
    {
        return 'acme/sqlcraft-duckdb';
    }
}

// Query logging extension
final class QueryLoggingExtension extends ExtensionBundle
{
    public function __construct(private readonly LoggerInterface $logger) {}

    #[\Override]
    protected function registerListeners(ListenerProviderInterface $listeners): void
    {
        $logging = new QueryLogger($this->logger);
        $listeners->listen(AfterQueryExecuted::class, $logging->onQueryExecuted(...));
        $listeners->listen(QueryFailedEvent::class, $logging->onQueryFailed(...));
    }
}

// Combined export format extension
final class ExtraFormatsExtension extends ExtensionBundle
{
    #[\Override]
    protected function registerFormats(FormatRegistry $formats): void
    {
        $formats->registerWriter(new JsonFormatWriter);
        $formats->registerWriter(new XmlFormatWriter);
        $formats->registerWriter(new PhpFormatWriter);
        $formats->registerReader(new JsonFormatReader);
    }
}
```

---

## 4. `SQLCraftFactory` Integration Points

Plan: `SQLCraftFactory` (already implemented at `src/SQLCraftFactory.php`) should grow a `withServiceProvider()` helper to wire bundles at construction time.

### Proposed Addition to `SQLCraftFactory`

```php
// New method on SQLCraftFactory
public function withServiceProvider(ServiceProviderInterface $provider): static
{
    $clone = clone $this;
    $clone->pendingProviders[] = $provider;
    return $clone;
}

// Applied during session() / build() — after registries are constructed:
private function applyProviders(): void
{
    $listenerProvider = $this->getOrCreateListenerProvider();
    foreach ($this->pendingProviders as $provider) {
        $provider->register($this->drivers, $this->formats, $listenerProvider);
    }
}
```

**Note:** This is an additive, non-breaking change to `SQLCraftFactory`. The existing constructor interface remains unchanged. Providers are applied before the first `session()` call.

---

## 5. `ListenerProviderInterface` Compatibility

`ServiceProviderInterface::register()` accepts `Psr\EventDispatcher\ListenerProviderInterface` as the listener registry parameter. However, the standard PSR-14 `ListenerProviderInterface` only defines `getListenersForEvent()` — it has no `listen()` method.

SQLCraft's own `SimpleListenerProvider` adds `listen()`. For the `ServiceProviderInterface` to be usable with any PSR-14 provider (including framework-provided ones), we need a more specific contract.

### `ListenableProviderInterface`

**File:** `src/Contracts/Events/ListenableProviderInterface.php`

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Contracts\Events;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * A listener provider that also supports registering listeners at runtime.
 *
 * Extends PSR-14's read-only ListenerProviderInterface with a write method.
 * SQLCraft's SimpleListenerProvider implements this interface.
 *
 * @api
 */
interface ListenableProviderInterface extends ListenerProviderInterface
{
    /**
     * @param class-string $eventClass
     */
    public function listen(string $eventClass, callable $listener, int $priority = 0): void;
}
```

**Then** update `ServiceProviderInterface` to accept `ListenableProviderInterface` instead of the read-only PSR-14 one.

**And** update `SimpleListenerProvider` to declare `implements ListenableProviderInterface`.

---

## 6. Testing Requirements

| Test | Type | Notes |
|---|---|---|
| `ExtensionBundle::register()` dispatches to all three hooks | Unit | Mock registries |
| No-op defaults don't touch the registries | Unit | Assert no calls made |
| `ServiceProviderInterface` can be typed in DI wiring | Architectural | PHPStan level 10 |
| `ListenableProviderInterface` accepted by `ServiceProviderInterface` | Type check | Psalm |
| `SQLCraftFactory::withServiceProvider()` is immutable | Unit | Clone returns new instance |
| Provider registered drivers appear in `DriverRegistry` | Integration | Full stack |
| Provider registered formats appear in `FormatRegistry` | Integration | Full stack |
| Provider registered listeners fire on relevant events | Integration | Full stack |

---

## 7. File Summary

| File | New/Modified |
|---|---|
| `src/Extension/ServiceProviderInterface.php` | 🆕 New |
| `src/Extension/ExtensionBundle.php` | 🆕 New |
| `src/Contracts/Events/ListenableProviderInterface.php` | 🆕 New |
| `src/Events/SimpleListenerProvider.php` | ✏️ Add `implements ListenableProviderInterface` |
| `src/SQLCraftFactory.php` | ✏️ Add `withServiceProvider()` |
| `tests/Extension/ExtensionBundleTest.php` | 🆕 New |
| `tests/Extension/ServiceProviderInterfaceTest.php` | 🆕 New |
