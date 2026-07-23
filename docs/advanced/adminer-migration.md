# Adminer migration guide

The Adminer 5.5.0 parity inventory in
`docs/other/plans/extensions-revised/02-adminer-5.5.0-hook-matrix.md` contains 79
unique public hooks. It is a capability inventory, not an API-compatibility promise.
SQLCraft intentionally excludes Adminer UI, HTTP, form, application-authentication,
and session hooks.

Use the matrix disposition to choose a SQLCraft seam:

- lifecycle observation or cancellation: PSR-14 events;
- SQL and parameter transformation: ordered `QueryInterceptorInterface` instances;
- connection setup: ordered `ConnectionInitializerInterface` instances;
- credentials: `CredentialProviderInterface` or `CredentialProviderChain`;
- engine behavior: `DriverDefinition` plus composed platform roles;
- metadata replacement: a per-connection `MetadataInspectorSet` decorator;
- import/export: named reader and writer factories plus caller-owned sinks;
- caching/history: explicit builder dependencies.

Do not translate an Adminer hook name into a SQLCraft method. For example,
`selectQuery()` is not a SQLCraft pre-execution rewriter: use a query interceptor when
SQL transformation is required, or a cancellable event when only policy-driven
cancellation is required. Credentials are not authorization; database visibility and
privilege decisions remain database/application responsibilities. SQL regexes are not
a security boundary.

The five Adminer append hooks remain documented in the matrix as append behavior:
`dumpFormat`, `dumpOutput`, `editRowPrint`, `editFunctions`, and `config`. Other Adminer
first-non-null behavior is described for migration context only; SQLCraft does not
promise Adminer dispatch semantics.
