# Installation

## Requirements

SQLCraft requires:

- **PHP 8.4 or higher**
- **ext-pdo** (PDO extension)
- PDO driver extensions for your target databases:
  - `ext-pdo_sqlite` for SQLite
  - `ext-pdo_mysql` for MySQL/MariaDB
  - `ext-pdo_pgsql` for PostgreSQL
  - `ext-pdo_sqlsrv` or `ext-pdo_dblib` for SQL Server

## Installing via Composer

Install SQLCraft using Composer:

```bash
composer require vendor/sqlcraft
```

## Installing Database Extensions

### SQLite

SQLite support is typically built into PHP:

```bash
# Check if sqlite is available
php -m | grep pdo_sqlite
```

If missing, install it:

```bash
# Debian/Ubuntu
sudo apt-get install php8.4-sqlite3

# macOS with Homebrew
brew install php
```

### MySQL/MariaDB

```bash
# Debian/Ubuntu
sudo apt-get install php8.4-mysql

# macOS with Homebrew
brew install php
```

### PostgreSQL

```bash
# Debian/Ubuntu
sudo apt-get install php8.4-pgsql

# macOS with Homebrew
brew install php
```

### SQL Server

#### Linux (using Microsoft's official driver)

```bash
# Install ODBC Driver for SQL Server
curl https://packages.microsoft.com/keys/microsoft.asc | sudo apt-key add -
curl https://packages.microsoft.com/config/ubuntu/$(lsb_release -rs)/prod.list | sudo tee /etc/apt/sources.list.d/mssql-release.list
sudo apt-get update
sudo ACCEPT_EULA=Y apt-get install -y msodbcsql17

# Install pdo_sqlsrv extension
sudo pecl install sqlsrv pdo_sqlsrv
```

#### Linux (using FreeTDS)

```bash
# Debian/Ubuntu
sudo apt-get install php8.4-sybase freetds-dev

# This provides pdo_dblib
```

#### Windows

Download the official Microsoft driver from [PHP.net](https://www.php.net/manual/en/sqlsrv.requirements.php).

## Optional Dependencies

SQLCraft integrates with PSR interfaces when available:

```bash
# For event dispatching (PSR-14)
composer require psr/event-dispatcher

# For logging (PSR-3)
composer require psr/log

# For caching metadata (PSR-16)
composer require psr/simple-cache
```

You'll also need concrete implementations:

```bash
# Example: Using Symfony components
composer require symfony/event-dispatcher
composer require symfony/cache
composer require monolog/monolog
```

## Verifying Installation

Create a test script `test.php`:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use SQLCraft\SQLCraftFactory;
use SQLCraft\ValueObjects\ConnectionParameters;

$factory = new SQLCraftFactory();
$session = $factory->session(new ConnectionParameters(database: ':memory:'));

$result = $session->query('SELECT 1 as test');
foreach ($result as $row) {
    echo "SQLCraft is working! Test value: " . $row['test'] . "\n";
}
```

Run it:

```bash
php test.php
```

Expected output:
```
SQLCraft is working! Test value: 1
```

## Docker Development Environment

For development and testing, use the provided Docker environment:

```bash
# Clone the repository
git clone https://github.com/vendor/sqlcraft.git
cd sqlcraft

# Copy environment file
cp .env.example .env

# Build containers
docker compose build

# Install dependencies
docker compose run --rm php composer install

# Run tests
docker compose run --rm php composer test

# Start all database services
docker compose up -d
```

## Next Steps

- [Quick Start Guide](quick-start.md) - Build your first SQLCraft application
- [Basic Concepts](basic-concepts.md) - Understand SQLCraft's architecture
- [Connecting to Databases](../user-guide/connections.md) - Learn about connection management
