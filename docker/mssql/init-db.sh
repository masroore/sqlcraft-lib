#!/usr/bin/env bash
# Creates the SQLCraft test database and login in SQL Server.
# Runs once as an init container after the mssql service is healthy.
set -euo pipefail

HOST="${MSSQL_HOST:-mssql}"
SA_PASS="${MSSQL_SA_PASSWORD:-SQLcraft_Test1!}"
DB="${MSSQL_DB:-sqlcraft_test}"
SQLCMD="/opt/mssql-tools18/bin/sqlcmd"

echo "[mssql-init] Creating database '$DB' …"

# Step 1: create the database if it doesn't already exist
$SQLCMD -S "$HOST" -U sa -P "$SA_PASS" -C -b -Q "
IF DB_ID(N'${DB}') IS NULL
    CREATE DATABASE [${DB}];
"

# Step 2: create a dedicated login + user with db_owner in that database
$SQLCMD -S "$HOST" -U sa -P "$SA_PASS" -C -b -Q "
USE [master];
IF NOT EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'sqlcraft')
    CREATE LOGIN [sqlcraft] WITH PASSWORD = N'${SA_PASS}', CHECK_POLICY = OFF;
"

$SQLCMD -S "$HOST" -U sa -P "$SA_PASS" -C -b -d "$DB" -Q "
IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name = N'sqlcraft')
BEGIN
    CREATE USER [sqlcraft] FOR LOGIN [sqlcraft];
    ALTER ROLE [db_owner] ADD MEMBER [sqlcraft];
END
"

echo "[mssql-init] Done — database '${DB}' ready."
