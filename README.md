# SQLCraft

Framework-independent, PDO-based database administration SDK for PHP 8.4+.

SQLCraft exposes typed, capability-aware connection, introspection, DDL,
query, import, and export services without owning HTTP, UI, or application
state.

> **Status:** early development. The public API is not stable.

## Installation

```bash
composer require vendor/sqlcraft
```

## Development

```bash
composer install
composer run ci
```

See `docs/plans/` for the implementation design and milestone roadmap.
