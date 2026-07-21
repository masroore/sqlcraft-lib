# Phase 4 — Security write-side

> Depends on: Phase 2 (`SecurityGuardInterface`, `Credential`), Phase 3 (`DatabaseSession::security()`).
> Release-blocking: yes — this is the largest single gap and directly falsifies the M9 "green" claim.
> Closes audit findings: 07 §1.2.1/§1.2.2/§5/§6.5; 03 §1.2 (PrivilegeInspector); summary B1/B2/C1–C4.

`src/Security/` contains only `IdentifierQuoter` + `OperatorValidator`. Everything the
Security module was promised to own — the privilege guard, user/role management,
GRANT/REVOKE — is absent, yet M9 "Security & Events" is marked green. This phase builds the
write-side, capability-gated, for the five supported engines (no Oracle).

---

## 4.1 `SecurityGuardInterface` + `PrivilegeGuard`

**Problem:** `SecurityGuardInterface` is listed in the Contracts table (doc 07 §1) but was
never created; no `SecurityGuard` implementation exists. The "can this user do X on object Y?"
pathway is absent. (Audit 07 §1.2.1, Critical.)

**Work:**
1. Contract lands in Phase 2 §2.1. Here: `src/Security/PrivilegeGuard.php` implementing `SecurityGuardInterface`, delegating to `PrivilegeInspectorInterface` to answer `can()`; `require()` throws a security exception on denial.
2. Wire `DatabaseSession::security()` (Phase 3) to return the guard.

**Acceptance:** `$session->security()->can('DROP', $table)` returns a bool from real privilege
introspection; `require()` throws when denied. Unit test with a mocked inspector.

---

## 4.2 `PrivilegeInspector` (read-side, contract exists, no impl)

**Problem:** `PrivilegeInspectorInterface` exists in `Contracts/Metadata/` with zero
implementations and is not injected into `SchemaManager`. The privilege matrix introspection
the guard needs has no backend. (Audit 03 §1.2 / §7.1, Medium.)

**Work:**
1. `src/Metadata/PrivilegeInspector.php` implementing the interface, per-engine privilege-matrix SQL, capability-gated (Phase 1 §1.4 pattern).
2. Inject into `SchemaManager` constructor + `SchemaManagerFactory`.
3. Introduce `PrivilegeGrant` VO (doc 04 §18 promises grant-specific return type) if the generic `Privilege` VO is insufficient for object×privilege×grantee.

**Acceptance:** `getPrivileges()` returns a real grant collection on MySQL/PostgreSQL; guard
consumes it. Integration test on at least one engine.

---

## 4.3 User / role management (write-side)

**Problem:** 6 of 6 write operations from doc 04 §18 are absent: create/alter/drop user,
create/drop role, plus password handling. Only read-side `UserInspector` exists. (Audit 07
§1.2.2, High.)

**Work:**
1. `src/Contracts/Security/UserManagerInterface.php` — `createUser()`, `alterUser()`, `dropUser()`, `createRole()`, `dropRole()`, password set/change.
2. `src/Security/UserManager.php` generating per-engine DDL, capability-gated behind `Capability::UserManagement`. Engine coverage: **MySQL, MariaDB, PostgreSQL, SQL Server, SQLite**. SQLite has no users → the capability is false and the manager throws `CapabilityNotSupportedException` (correct). Password hashing per engine where applicable (`caching_sha2_password`, SCRAM-SHA-256, MSSQL policy). **No Oracle.**
3. Execution routes through `QueryExecutor` (events fire) — same rule as DDL (Phase 1 §1.2).
4. `#[\SensitiveParameter]` on every password parameter (Audit 07 §5).

**Acceptance:** create/drop user + role works on MySQL and PostgreSQL (integration-gated);
unsupported engines throw the capability exception; passwords never appear in events/exceptions.

---

## 4.4 Privilege management (GRANT / REVOKE)

**Problem:** GRANT/REVOKE across the object × privilege × grantee matrix is absent. (Audit 07
§1.2.2, High.)

**Work:**
1. `src/Contracts/Security/PrivilegeManagerInterface.php` — `grant(Privilege, QualifiedName $object, string $grantee)`, `revoke(...)`.
2. `src/Security/PrivilegeManager.php` with per-engine GRANT/REVOKE DDL, gated behind `Capability::PrivilegeManagement`. Engines: MySQL, MariaDB, PostgreSQL, SQL Server. No Oracle.
3. Route execution through `QueryExecutor`.

**Acceptance:** grant then revoke a table privilege on MySQL/PostgreSQL, verified by
re-introspecting the privilege matrix (4.2). Integration-gated test.

---

## 4.5 Credential redaction hardening

**Problem:** `#[\SensitiveParameter]` appears in exactly one place
(`ConnectionParameters::$password`). `SecretRedactor` is wired only into `PdoConnectionFactory`
DSN sanitization, not the general exception hierarchy. Query exception constructors were not
verified to exclude bound-parameter values. (Audit 07 §5, Medium.)

**Work:**
1. Apply `#[\SensitiveParameter]` to every `$password`/`$credential` parameter across constructors and factory methods (the new `UserManager` password methods included).
2. Wire `SecretRedactor` into the exception hierarchy so DSN/credential fragments are redacted from any exception message, not just connection-failure.
3. Audit query exception constructors (`SyntaxErrorException`, `QueryExecutionException`) to confirm bound-parameter values are not embedded in messages (doc 15 §8).

**Acceptance:** a forced connection/query failure produces an exception message with no password
or bound-parameter value present. Test asserts redaction.

---

## 4.6 `TableSearchService` with `$rowCap`

**Problem:** doc 15 §11.1 promises `TableSearchService::search()` with a per-table `$rowCap`
(default 1,000). The service is entirely absent. (Audit 07 §6.5, Medium.) Related to Phase 5
cross-table search — build the service here with the cap wired from the start, or defer jointly
with Phase 5 §5.2 and note it. Recommend building the row-cap into whichever phase lands the
search service so the DoS guard is never an afterthought.

**Acceptance:** cross-table search enforces a per-table row cap by default; test asserts the cap
truncates a large result.

---

## Phase 4 exit criteria

- `SecurityGuardInterface` + `PrivilegeGuard` answer real privilege questions.
- `PrivilegeInspector` implemented and injected.
- User/role create/alter/drop works on the four user-capable engines, capability-gated, no Oracle.
- GRANT/REVOKE works on the four privilege-capable engines.
- Credential redaction covers exceptions, not just DSN.
- Row cap present wherever cross-table search lands.
- `make build`/`make test` green; **M9 "Security & Events" is now actually true.**
