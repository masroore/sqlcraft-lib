#!/usr/bin/env bash
# Waits for SQL Server to accept connections, then creates the test database.
# Runs as an init container (depends_on: mssql condition: service_healthy).
set -euo pipefail

HOST="${MSSQL_HOST:-mssql}"
SA_PASS="${MSSQL_SA_PASSWORD:-SQLcraft_Test1!}"
DB="${MSSQL_DB:-sqlcraft_test}"
SQLCMD="/opt/mssql-tools18/bin/sqlcmd"

echo "[mssql-init] Creating database '$DB' on $HOST …"

$SQLCMD -S "$HOST" -U sa -P "$SA_PASS" -C -b -Q "
IF DB_ID('$DB') IS NULL
    CREATE DATABASE [$DB];
GO
IF NOT EXISTS (SELECT 1 FROM [$DB].sys.server_principals WHERE name = 'sqlcraft')
BEGIN
    CREATE LOGIN [sqlcraft] WITH PASSWORD = '$SA_PASS', CHECK_POLICY = OFF;
    USE [$DB];
    CREATE USER [sqlcraft] FOR LOGIN [sqlcraft];
    ALTER ROLE db_owner ADD MEMBER [sqlcraft];
END
"

echo "[mssql-init] Done."
