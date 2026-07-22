# SQLCraft Documentation

Welcome to the SQLCraft documentation. SQLCraft is a framework-independent PHP 8.4+ SDK for database administration that provides typed connection, metadata, DDL, query, import, export, capability, and event primitives.

## Table of Contents

### Getting Started
- [Installation](getting-started/installation.md) - Install and configure SQLCraft
- [Quick Start](getting-started/quick-start.md) - Your first SQLCraft application
- [Basic Concepts](getting-started/basic-concepts.md) - Core concepts and architecture

### User Guide
- [Connecting to Databases](user-guide/connections.md) - Connection management and pooling
- [Schema Introspection](user-guide/schema-introspection.md) - Inspecting database structure
- [DDL Operations](user-guide/ddl-operations.md) - Creating and modifying schema
- [Query Execution](user-guide/query-execution.md) - Executing queries and managing results
- [Import and Export](user-guide/import-export.md) - Data migration and backup
- [Security and Privileges](user-guide/security.md) - User and privilege management
- [Transactions](user-guide/transactions.md) - Transaction management
- [Events](user-guide/events.md) - Event system and listeners

### Database Platforms
- [MySQL/MariaDB](platforms/mysql.md) - MySQL and MariaDB specifics
- [PostgreSQL](platforms/postgresql.md) - PostgreSQL specifics
- [SQLite](platforms/sqlite.md) - SQLite specifics
- [SQL Server](platforms/sqlserver.md) - Microsoft SQL Server specifics

### Advanced Topics
- [Capabilities System](advanced/capabilities.md) - Understanding database capabilities
- [Streaming and Performance](advanced/streaming.md) - Memory-efficient data processing
- [Custom Drivers](advanced/custom-drivers.md) - Creating custom database drivers
- [Framework Integration](advanced/framework-integration.md) - Laravel, Symfony, and more

### API Reference
- [API Overview](api/overview.md) - Public API surface
- [Core Classes](api/core-classes.md) - DatabaseSession, SQLCraftFactory
- [Contracts](api/contracts.md) - Interface reference
- [Value Objects](api/value-objects.md) - Immutable data objects
- [DTOs](api/dtos.md) - Data Transfer Objects
- [Exceptions](api/exceptions.md) - Exception hierarchy

### Development
- [Contributing](development/contributing.md) - How to contribute
- [Testing](development/testing.md) - Running tests
- [Architecture](development/architecture.md) - Internal architecture
- [Release Process](development/release-process.md) - Versioning and releases

## Quick Links

- [GitHub Repository](https://github.com/vendor/sqlcraft)
- [Issue Tracker](https://github.com/vendor/sqlcraft/issues)
- [Changelog](../CHANGELOG.md)
- [License](../LICENSE)

## Supported Platforms

| Platform | Status | Version Support |
|----------|--------|-----------------|
| SQLite | ✅ Full | 3.8.0+ |
| MySQL | ✅ Full | 5.7+, 8.0+ |
| MariaDB | ✅ Full | 10.3+ |
| PostgreSQL | ✅ Full | 10+, 11+, 12+, 13+, 14+, 15+, 16+ |
| SQL Server | ✅ Full | 2017+, 2019+, 2022+ |
| Oracle | ⏳ Future | Deferred to post-v1.0 |

## Getting Help

- Check the [examples](../examples/) directory for working code samples
- Read the [API documentation](api/overview.md)
- Open an [issue](https://github.com/vendor/sqlcraft/issues) if you find a bug
- Check existing issues for common problems and solutions
