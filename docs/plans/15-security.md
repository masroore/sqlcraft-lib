# 15 — Security Model

> **Status:** Design draft
> **Scope:** `SQLCraft\Security` namespace — identifier quoting, value binding, binary quoting, input validation and allowlisting, SQL injection threat model, credential handling, shared responsibility boundary, privilege awareness, secrets in logs/exceptions, DoS/resource limits, supply-chain
> **Depends on:** 05-domain-model.md (Identifier VO, exception hierarchy), 08-driver-architecture.md (QuotingInterface, PlatformInterface), 10-connection-layer.md (CredentialProvider), 12-query-engine.md (SelectQuery, operator allowlisting)
> **Namespace root:** `SQLCraft\Security`

---

## 1. Security Philosophy

SQLCraft is a library, not an application. It cannot enforce network-level security, authentication policies, or access controls. What it can do is ensure that every SQL it constructs or executes is safe from injection by architectural contract, not by developer diligence.

The core principle is: **trust nothing that arrives as a string; trust everything that has been validated into a typed VO.**

An `Identifier` VO (05-domain-model.md §3.1) is not a raw string — it is a validated, quote-ready name. A `WhereCondition` VO (12-query-engine.md §7) carries an allowlisted operator and a bound parameter, never an interpolated value. The type system is the first line of defence; quoting and binding are the second.

---

## 2. `IdentifierQuoter`

Identifier quoting is the mechanism that prevents SQL injection via object names (table names, column names, schema names injected as strings). Adminer uses `idf_escape()` as a free function. SQLCraft encapsulates this in `IdentifierQuoter`, which delegates to the platform.

```php
namespace SQLCraft\Security;

use SQLCraft\Contracts\Platform\QuotingInterface;
use SQLCraft\ValueObjects\{Identifier, QualifiedName};

final class IdentifierQuoter
{
    public function __construct(private readonly QuotingInterface $platform) {}

    /** Quote a single identifier using the platform's quoting character. */
    public function quote(Identifier $identifier): string
    {
        return $this->platform->quoteIdentifier($identifier);
    }

    /**
     * Quote a fully qualified name (catalog.schema.object).
     * Only includes non-null segments.
     */
    public function quoteQualified(QualifiedName $name): string
    {
        $parts = array_filter([
            $name->catalog?->name,
            $name->schema?->name,
            $name->object->name,
        ]);
        return implode('.', array_map(
            fn(string $p) => $this->platform->quoteIdentifier(new Identifier($p)),
            $parts,
        ));
    }
}
```

**Per-platform quoting rules (from 08-driver-architecture.md §3.1):**

| Engine | Quote character | Escape double-quote? | Notes |
|--------|----------------|---------------------|-------|
| MySQL / MariaDB | Backtick `` ` `` | Escape as ` `` ` | ANSI mode: also accepts `"` |
| PostgreSQL | Double-quote `"` | Escape as `""` | Case-folds unquoted identifiers to lowercase |
| SQLite | Double-quote `"` | Escape as `""` | Also accepts backtick (not used by SQLCraft) |
| MSSQL | Square brackets `[…]` | Escape `]` as `]]` | Adminer notes: escape `[` for array-style names |
| Oracle | Double-quote `"` | Escape as `""` | Unquoted = uppercase; quoted = case-sensitive |

The `Identifier` VO constructor rejects empty strings and null bytes at construction time (05-domain-model.md §3.1). The quoter only ever sees already-validated identifiers.

### 2.1 The `Identifier` VO as Quote-Safe Boundary

The architectural guarantee: **no SQL string leaves SQLCraft with an unquoted identifier that arrived from external input.** The flow is:

```
External string (user input / API param)
        ↓
  new Identifier($name)      ← validation: no empty string, no null byte
        ↓
  IdentifierQuoter::quote()  ← platform-specific escaping applied
        ↓
  Rendered SQL fragment       ← safe to embed in SQL
```

A raw `string` is never passed directly to SQL assembly. Any service method that accepts an identifier accepts `Identifier`, not `string`.

---

## 3. Value Binding — The Non-Negotiable Rule

**All data values are bound as parameters, never interpolated.** This is enforced by architecture:

- `ConnectionInterface::execute(string $sql, array $params)` always uses PDO prepared statements when `$params` is non-empty.
- `WhereCondition::$value` is never rendered into SQL text — it is always collected into the params array by `SelectQueryRenderer` (12-query-engine.md §7.1).
- There is no `raw()` escape hatch in any public API for value interpolation.

```php
// Correct — always:
$executor->execute($conn, 'UPDATE users SET name = ? WHERE id = ?', [$newName, $userId]);

// No public API permits this:
// $executor->execute($conn, "UPDATE users SET name = '$newName' WHERE id = $userId");
// ^^^^^ impossible to express through SQLCraft's typed interfaces
```

**PDO `ATTR_EMULATE_PREPARES = false`** is set by all built-in drivers (see 10-connection-layer.md §12). This forces native prepared statements on the DB server, not client-side string substitution. Client-side emulation with certain drivers can be fooled with multibyte encoding attacks.

---

## 4. Binary / BLOB Quoting

Binary data cannot always be bound as PDO parameters — some engines require engine-specific literal syntax for BLOB/binary columns in certain contexts (e.g., `0x` hex literals in MySQL, `E'\\x...'` or `pg_escape_bytea` in PostgreSQL).

This is handled by `QuotingInterface::quoteBinary()` (08-driver-architecture.md §3.1):

| Engine | `quoteBinary()` output |
|--------|----------------------|
| MySQL / MariaDB | `0x` + bin2hex($bytes) |
| PostgreSQL | `E'` + pg_escape_bytea equivalent + `'` (or `\x` hex for PgSQL 9+) |
| SQLite | Same as `quoteValue()` — PDO handles binary transparently |
| MSSQL | `0x` + bin2hex($bytes) |
| Oracle | `HEXTORAW('...')` |

`quoteBinary()` is only used when direct binding is genuinely unavailable. In all other cases, PDO's binary binding (`PDO::PARAM_LOB`) is preferred.

---

## 5. Input Validation and Allowlisting

Beyond identifier quoting and value binding, several inputs require additional validation to prevent structural injection (where the threat is not in the value but in what structural SQL element is being controlled).

### 5.1 Operator Allowlisting

WHERE operators (`=`, `!=`, `LIKE`, `REGEXP`, `IN`, `IS NULL`, `BETWEEN`, etc.) are structural SQL tokens that cannot be bound as parameters. SQLCraft validates them against the platform's operator list at `WhereCondition` construction:

```php
namespace SQLCraft\Security;

final class OperatorValidator
{
    public function __construct(private readonly PlatformInterface $platform) {}

    public function validate(string $operator): string
    {
        $allowed = $this->platform->getOperators(); // list<string> from the platform
        if (!in_array($operator, $allowed, strict: true)) {
            throw new InvalidOperatorException(
                "Operator '{$operator}' is not permitted for platform '{$this->platform->getName()}'."
            );
        }
        return $operator;
    }
}
```

Adminer's `$drivers->operators` is a flat array per driver. SQLCraft makes this platform-supplied and validated at VO construction, so an invalid operator is a programming error caught at build time, not a runtime injection at execution time.

### 5.2 Sort Direction Allowlisting

`ORDER BY` direction is an enum, not a string. The `OrderByClause::$descending` field is a `bool`. No direction string ever touches SQL construction. The renderer outputs `ASC` or `DESC` from the boolean.

### 5.3 LIMIT / OFFSET Validation

`PaginationParams` validates that `$page >= 1` and `$limit >= 1` in its constructor (12-query-engine.md §6.1). The paginator additionally caps `$limit` at a configured maximum (default: 10,000) to prevent DoS via `LIMIT 999999999`. The cap is configurable at `Paginator` construction.

### 5.4 Data Type Allowlisting

When constructing `DataType` VOs for DDL operations, the `name` field (e.g., `'VARCHAR'`, `'INT'`) must be validated against the platform's `TypeMapperInterface::getSupportedTypes()`. Passing an arbitrary string as a type name and including it in a `CREATE TABLE` statement would be a structural injection vector. The `DdlBuilder` validates all type names before rendering.

### 5.5 Aggregate Function Allowlisting

`ColumnSelection::$aggregateFunction` (12-query-engine.md §7) must come from the platform's `grouping` list (equivalent to Adminer's `$driver->grouping` array). The `SelectQueryRenderer` validates this list before embedding function names in SQL.

---

## 6. SQL Injection Threat Model

### 6.1 Attack Surfaces and Mitigations

| Surface | Injection vector | SQLCraft mitigation |
|---------|-----------------|---------------------|
| Column values in WHERE | Unbound string value | Bound parameters; no interpolation API |
| Table / column names | Untrusted string as identifier | `Identifier` VO + `IdentifierQuoter` |
| WHERE operators | Operator token injection | `OperatorValidator` allowlist |
| ORDER BY direction | `'; DROP TABLE` via direction | Boolean `$descending`, never string |
| LIMIT / OFFSET | Integer overflow or `;` injection | `PaginationParams` int validation |
| DDL type names | `INT; DROP TABLE` via type name | `TypeMapperInterface` allowlist |
| Aggregate functions | `COUNT(*); DROP` via function name | Platform `grouping` allowlist |
| Schema/database names | Path traversal or injection | `Identifier` VO validation |
| Stored procedure / function names | Injection via routine name | `Identifier` VO |

### 6.2 Residual Risks and Consumer Responsibilities

SQLCraft cannot prevent injection if the consumer:
- Builds raw SQL strings with untrusted input and passes them to `execute()` or `query()` directly.
- Uses the raw `PDO` instance obtained from somewhere outside SQLCraft.

These are consumer errors. SQLCraft provides the safe path; it does not block the unsafe path at runtime (doing so would require a SQL parser, which is out of scope). The API design makes the safe path the natural path — every structured builder uses VOs; only the escape-hatch `execute($sql)` accepts raw SQL, which the consumer must construct safely.

---

## 7. Credential Handling

Credentials are never stored, logged, or serialized by SQLCraft. The `CredentialProvider` interface (10-connection-layer.md §4) ensures this at the design level.

```php
final readonly class Credential
{
    public function __construct(
        public readonly string $username,
        #[\SensitiveParameter]
        public readonly string $password,
    ) {}
}
```

The `#[\SensitiveParameter]` attribute (PHP 8.2+) causes PHP's error handler to redact the password value in stack traces. SQLCraft additionally implements a custom exception formatter that:
- Never includes `Credential` object property values in exception messages.
- Replaces `password` fields in logged contexts with `[REDACTED]`.
- Ensures `ConnectionParams` stringification never includes credentials (it omits the `credential` field from any `__toString()` or serialisation output).

---

## 8. Secrets in Logs and Exception Messages

Exceptions must be informative but must not leak sensitive data. The policy:

1. **No credentials in exceptions.** `ConnectionFailedException` carries the DSN (host, port, database, driver) but never the password or username.
2. **No parameter values in query exceptions.** `SyntaxErrorException` carries the SQL template but not the bound parameter values (which may include PII).
3. **No raw PDOException forwarding.** `PdoExceptionTranslator` (10-connection-layer.md §9) wraps `PDOException` as a previous exception in the chain, but SQLCraft's own message is constructed without leaking the original message if it contains sensitive data. The original exception is preserved for debugging purposes.
4. **`#[\SensitiveParameter]` on all credential parameters** across the codebase.

```php
// Safe exception construction:
throw new ConnectionFailedException(
    sprintf('Connection to %s://%s:%d failed.', $params->driver, $params->host, $params->port),
    previous: $pdoException, // original preserved for debugging
);
// NOT: throw new ConnectionFailedException($pdoException->getMessage()); // may contain credentials
```

---

## 9. Shared Responsibility — What SQLCraft Deliberately Does Not Do

SQLCraft is a library. It handles SQL safety. The following concerns belong to the consumer application:

| Concern | Where it lives in Adminer | Why SQLCraft excludes it |
|---------|--------------------------|--------------------------|
| CSRF protection | `token()` + `verify_token()`, POST-redirect-GET | HTTP/web concern; SQLCraft has no HTTP layer |
| Session management | PHP `$_SESSION`; `$_COOKIE` | SQLCraft has no session/cookie concept |
| Permanent login (XXTEA cookie) | `permanentLogin()` in adminer.php | Cookie-based auth; consumer's concern |
| Brute-force throttle | Per-minute failed-attempt counter in session | Rate-limiting is infrastructure/middleware |
| IP allowlisting | `allowed_ip()` in adminer.php | Network/middleware concern |
| Output encoding (HTML escaping) | `h()` function | SQLCraft emits no HTML |
| HTTPS enforcement | Web-server concern | SQLCraft is not a web app |
| Multi-factor authentication | Not in Adminer; consumer adds | Auth flow is application-layer |

**Consumer checklist:** An application that uses SQLCraft to build a web-based DB admin UI is responsible for:
- Authenticating the user before calling any SQLCraft service.
- Protecting forms with CSRF tokens.
- Enforcing HTTPS.
- Rate-limiting login attempts.
- Authorizing which databases/tables a user may access (SQLCraft executes as the connected DB user; it applies no additional ACL layer on top of DB privileges).

---

## 10. Privilege Awareness

SQLCraft executes as the DB user specified in `ConnectionParams`. It does not escalate privileges, hold a privileged connection, or apply any SQLCraft-level ACL.

Privilege introspection is available via `PrivilegeInspectorInterface` (11-schema-services.md §3.12) on engines where it is supported (`Capability::Privileges`). The returned `PrivilegeCollection` reflects the DB-level GRANT structure. A consumer application can use this to pre-filter the UI (hide tables the user cannot SELECT), but SQLCraft itself makes no enforcement decision based on privileges — the DB engine enforces its own ACL when SQLCraft executes the query.

**Principle:** SQLCraft surfaces privilege information; it does not enforce it. The DB is the enforcement point.

---

## 11. DoS and Resource Limits

### 11.1 Row Caps

Unbounded result sets can exhaust memory. The query engine applies configurable caps:

- `Paginator` has a `$maxLimit` (default: 10,000 rows per page).
- `TableSearchService::search()` has a `$rowCap` per table (default: 1,000).
- `BatchExecutor` has a `$maxStatements` limit (default: 1,000 statements per batch).

These are soft limits configurable at service construction. They default to conservative values that protect against accidental DoS; high-trust use cases (import/export) can raise them.

### 11.2 Statement Timeouts

Per-query timeouts via `QueryExecutor::queryWithTimeout()` (12-query-engine.md §10) delegate to engine-specific mechanisms. On engines without native timeout support (older SQLite), the `$timeoutMs` parameter has no effect and the method documents this explicitly.

### 11.3 Binary / Large Object Limits

Import and export operations (outside the scope of this document, see future import/export design doc) must stream binary data rather than loading it into memory. `ResultInterface::isStreaming()` + generator iteration ensures constant memory per row.

---

## 12. Dependency and Supply-Chain Security

SQLCraft follows a minimal-dependency policy:

1. **Zero required runtime dependencies** beyond PHP 8.4 core and PDO extensions. No third-party packages are required to use SQLCraft.
2. **Optional integrations** (PSR-14 event dispatcher, PSR-6/16 cache) are expressed as interfaces, not `require` dependencies. The consumer wires in their preferred implementation.
3. **Dev dependencies** (PHPStan, Psalm, Rector, PHPUnit) are pinned to exact versions in `composer.lock`. No floating `*` or `^` constraints in `require-dev`.
4. **No `eval()`, no dynamic class loading, no `include` of untrusted content.** SQLCraft never evaluates strings as code.
5. **No HTTP client in the library.** No outbound network calls other than the DB connection the consumer configures.

**Composer audit:** The build pipeline runs `composer audit` on every commit to detect known CVEs in the dev dependency chain. This is a CI concern, not a runtime concern; the zero-runtime-dep policy means the production attack surface is purely PHP core + PDO.

---

## 13. Summary of Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Identifier safety | `Identifier` VO as typed boundary | Catch invalid names at construction; quote-safe by type contract |
| Value binding | Prepared statements always | No interpolation API exists; PDO native prepare (`EMULATE_PREPARES=false`) |
| Operator safety | Platform-supplied allowlist; validated at VO construction | Structural injection prevention; engine-specific list |
| Sort direction | Boolean `$descending`, not a string | Eliminates entire injection class |
| Credential storage | `CredentialProvider` interface; no library storage | Library is not an application; no session/cookie layer |
| CSRF / sessions | Excluded — consumer responsibility | Clearly documented boundary; Adminer conflates app+lib |
| Privilege enforcement | Surface info; DB enforces | Principle of least surprise; DB ACL is authoritative |
| Secrets in logs | Redaction policy + `#[\SensitiveParameter]` | Defence-in-depth against credential leakage |
| Row caps | Configurable soft limits with safe defaults | Memory protection without breaking power users |
| Dependencies | Zero runtime deps | Minimal attack surface; no transitive CVE risk |
