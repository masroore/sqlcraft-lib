# M8 platform status — SQL Server

SQLCraft has a verified SQL Server 2022 adapter slice:

- `SqlServerPlatform` implements bracket quoting, `OFFSET … FETCH` pagination,
  SQL Server DDL fragments, version-aware sequence capability, and native catalog
  SQL generation.
- `SqlServerDriver` uses PDO `sqlsrv` DSNs and trusts the self-signed certificate
  shipped by the local SQL Server test container.
- SQL Server 2022 integration coverage passes through the real `mssql` Compose
  service: connection, query execution, table DDL, inserts, and pagination.
- The shared platform conformance suite passes for SQL Server 2022.
- `PdoConnectionFactory` enables `PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE` when
  available so SQL Server numeric result values retain the package's cross-engine
  consumer-facing shape.
- The `php` Compose service has no dependency on any engine service. SQL Server
  is started explicitly with `docker compose up -d mssql mssql-init`.

## Oracle deferral

Oracle remains deferred for a future implementation. The Oracle platform, driver,
`pdo_oci` build, and Oracle integration/conformance coverage are intentionally not
claimed by this milestone. The local environment lacks the Instant Client Basic
and SDK archives required to build `pdo_oci`, and the Oracle Compose service stays
opt-in under the `oracle` profile.

The six-engine engine-swap guarantee remains open until Oracle support receives a
separate feasibility decision and implementation.
