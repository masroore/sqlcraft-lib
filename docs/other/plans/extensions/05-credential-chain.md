# `CredentialProviderChain` and Default Credential Implementations

> **Status:** PLAN ONLY  
> **Phase:** 0 (Foundation — blocking)  
> **Namespace:** `SQLCraft\Connection\`  
> **Adminer equivalent:** `login-servers.php` (multi-server credential list)

---

## 1. Current State

SQLCraft already ships three `CredentialProviderInterface` implementations:

| Class | File | Description |
|---|---|---|
| `EnvCredentialProvider` | `src/Connection/EnvCredentialProvider.php` | Reads from environment variables |
| `ArrayCredentialProvider` | `src/Connection/ArrayCredentialProvider.php` | Key → `Credential` map, inline |
| `CallbackCredentialProvider` | `src/Connection/CallbackCredentialProvider.php` | Resolves via a user-supplied callable |

These cover the most common single-source cases. What is missing is a **composite** provider for fallback chains.

---

## 2. `CredentialProviderChain`

### Purpose

Tries each registered `CredentialProviderInterface` in order until one successfully resolves the key. Models the **Chain of Responsibility** pattern. Equivalent in spirit to Adminer's `login-servers.php` (multiple pre-configured server credentials) but generic and DI-friendly.

### File

`src/Connection/CredentialProviderChain.php`

### Full Specification

```php
<?php

declare(strict_types=1);

namespace SQLCraft\Connection;

use SQLCraft\Contracts\Connection\CredentialProviderInterface;
use SQLCraft\ValueObjects\Credential;

/**
 * Tries each provider in order; returns the first successful resolution.
 *
 * Useful for fallback strategies:
 *
 *   new CredentialProviderChain([
 *       new VaultCredentialProvider($vault),    // try Vault first
 *       new EnvCredentialProvider,              // fall back to env vars
 *       new ArrayCredentialProvider(['db' => new Credential('root', '')]),
 *   ])
 *
 * Throws a RuntimeException if all providers fail, with the last
 * provider's exception attached as $previous for diagnosis.
 *
 * @api
 */
final class CredentialProviderChain implements CredentialProviderInterface
{
    /** @var list<CredentialProviderInterface> */
    private readonly array $providers;

    /**
     * @param  iterable<CredentialProviderInterface>  $providers
     * @throws \InvalidArgumentException if no providers are given
     */
    public function __construct(iterable $providers)
    {
        $list = [...$providers];

        if ($list === []) {
            throw new \InvalidArgumentException(
                'CredentialProviderChain requires at least one provider.',
            );
        }

        $this->providers = $list;
    }

    /**
     * Resolves a credential key by trying each provider in registration order.
     *
     * @throws \RuntimeException if no provider could resolve the key
     */
    #[\Override]
    public function resolve(string $key): Credential
    {
        $lastException = null;

        foreach ($this->providers as $provider) {
            try {
                return $provider->resolve($key);
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        throw new \RuntimeException(
            sprintf('No credential provider could resolve key "%s".', $key),
            previous: $lastException,
        );
    }

    /** @return list<CredentialProviderInterface> */
    public function getProviders(): array
    {
        return $this->providers;
    }

    public function count(): int
    {
        return count($this->providers);
    }
}
```

### Design Decisions

1. **Eagerly rejects empty provider lists** — failing early at construction rather than at the first `resolve()` call makes misconfiguration easier to spot.
2. **Attaches `$lastException` as `previous`** — preserves the diagnostic chain so callers can find the root cause (e.g., a Vault connection error) from the thrown exception.
3. **`getProviders()` exposes the list** — for introspection in test assertions and debug logging.
4. **`count()` helper** — avoids forcing callers to call `count($chain->getProviders())`.

---

## 3. Usage Patterns

### Pattern A: Vault → Env → Hardcoded Fallback

```php
$provider = new CredentialProviderChain([
    new VaultCredentialProvider($vault, 'secret/data/sqlcraft'),
    new EnvCredentialProvider,
    new ArrayCredentialProvider(['admin' => new Credential('root', 'secret')]),
]);

$factory = new SQLCraftFactory(credentials: $provider);
```

### Pattern B: Per-Environment Chain

```php
$provider = match (APP_ENV) {
    'production'  => new VaultCredentialProvider($vault, 'prod/sqlcraft'),
    'staging'     => new CredentialProviderChain([
                         new EnvCredentialProvider,
                         new ArrayCredentialProvider(['default' => new Credential('app', 'staging')]),
                     ]),
    default       => new ArrayCredentialProvider(['default' => new Credential('root', '')]),
};
```

### Pattern C: Via ExtensionBundle

```php
final class VaultCredentialsExtension extends ExtensionBundle
{
    public function __construct(
        private readonly VaultClient $vault,
        private readonly string $path,
    ) {}

    #[\Override]
    public function getName(): string { return 'acme/vault-credentials'; }

    // Credential providers are not wired through ServiceProviderInterface
    // (which only handles drivers, formats, listeners).
    // Wire via the DI container instead:
    //
    //   $container->set(CredentialProviderInterface::class,
    //       fn($c) => new CredentialProviderChain([
    //           new VaultCredentialProvider($c->get(VaultClient::class), $path),
    //           $c->get(EnvCredentialProvider::class),
    //       ])
    //   );
}
```

**Important:** `CredentialProviderInterface` is a DI-injected dependency of `SQLCraftFactory`, not a format/driver/listener registry entry. It is wired via the DI container or the `SQLCraftFactory` constructor — not via `ServiceProviderInterface::register()`. The example above shows the DI container approach.

---

## 4. `Credential` Value Object — Verification

Before implementing, confirm `src/ValueObjects/Credential.php` exists and has the expected shape:

```php
// Expected shape (verify against actual file):
final readonly class Credential
{
    public function __construct(
        public readonly string $username,
        public readonly string $password,
    ) {}
}
```

If the file does not exist or has a different shape, the implementation plan for `CredentialProviderChain` must be updated accordingly.

---

## 5. Testing Requirements

| Test | Type | Expected Behavior |
|---|---|---|
| Returns from first successful provider | Unit | First provider succeeds, second never called |
| Falls through on exception to next provider | Unit | First throws, second succeeds |
| Throws RuntimeException when all providers fail | Unit | All throw, RuntimeException raised |
| Last exception attached as `previous` | Unit | `->getPrevious()` === last provider's exception |
| Rejects empty provider list | Unit | `InvalidArgumentException` at construction |
| `count()` returns provider count | Unit | Matches count of passed providers |
| `getProviders()` returns registration-order list | Unit | Array equality |
| Integration: chain used by `SQLCraftFactory` | Integration | Full connection test with mock providers |

```php
// Example test
public function test_falls_through_on_exception(): void
{
    $failing  = $this->createMock(CredentialProviderInterface::class);
    $failing->method('resolve')->willThrowException(new \RuntimeException('vault down'));

    $succeeding = $this->createMock(CredentialProviderInterface::class);
    $succeeding->method('resolve')->willReturn(new Credential('user', 'pass'));

    $chain = new CredentialProviderChain([$failing, $succeeding]);
    $result = $chain->resolve('any-key');

    self::assertSame('user', $result->username);
}

public function test_attaches_last_exception_as_previous(): void
{
    $last = new \RuntimeException('last error');
    $failing = $this->createMock(CredentialProviderInterface::class);
    $failing->method('resolve')->willThrowException($last);

    $chain = new CredentialProviderChain([$failing]);

    try {
        $chain->resolve('key');
        self::fail('Expected RuntimeException');
    } catch (\RuntimeException $e) {
        self::assertSame($last, $e->getPrevious());
    }
}
```

---

## 6. File Summary

| File | New/Modified |
|---|---|
| `src/Connection/CredentialProviderChain.php` | 🆕 New |
| `tests/Connection/CredentialProviderChainTest.php` | 🆕 New |
| `docs/development/extension-guide.md` | ✏️ Add credential chain section |
