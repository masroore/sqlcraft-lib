# 19 — Package Structure

> **Status:** Design draft
> **Scope:** Concrete Composer package layout, `composer.json`, PSR-4 mapping, dependency policy, versioning mechanics, extension suggestions, single-package decision
> **Depends on terminology from:** `06-package-architecture.md` (bounded contexts), `07-module-breakdown.md` (module list), `18-public-api.md` (public vs `@internal` surface, BC promise)

---

## 1. Single Package vs Monorepo Split

**Decision: one Composer package, `vendor/sqlcraft`, for v1.** Considered and rejected alternatives:

| Option | Description | Verdict |
|---|---|---|
| **Single package (chosen)** | All bounded contexts (06 §3) in one `src/` tree, one `composer.json` | Chosen for v1 |
| **Split by driver** (`sqlcraft/core`, `sqlcraft/mysql`, `sqlcraft/pgsql`, ...) | Each engine driver+platform as its own installable package, à la `doctrine/dbal` splitting driver code less aggressively but similar to how e.g. `league/flysystem-aws-s3-v3` splits adapters from `league/flysystem` | Rejected for v1, revisit later |
| **Split by layer** (`sqlcraft/contracts`, `sqlcraft/metadata`, `sqlcraft/ddl`, ...) | One package per module in 07 | Rejected outright |

**Rationale for a single package now:**
1. **Version-lockstep risk.** The five built-in v1 drivers and the core are developed together during v1; forcing consumers to pin compatible versions of 7 packages (`core` + 6 drivers) before the API has stabilized adds friction for no present benefit. Doctrine DBAL itself does **not** split drivers into packages (each driver lives in `Doctrine\DBAL\Driver\*` inside the single `doctrine/dbal` package) — the prompt's own framing that "doctrine/dbal does NOT [split drivers]" is accurate and is the precedent SQLCraft follows.
2. **Capability matrix coherence.** The capability model (09) is validated as a whole matrix across all five v1 engines in one CI run (20 §8's contract-test suite). Splitting drivers into separate repos/packages would fragment that guarantee and require a separate compatibility-matrix package just to re-glue it.
3. **ext-pdo_* extensions are already the real per-engine dependency boundary.** A consumer who never uses PostgreSQL never loads `PostgreSQLDriver`/`PostgreSQLPlatform` classes into their working set in any meaningful resource sense (PHP autoloading is lazy per-class); there is no filesystem/network download cost being saved by a package split, unlike, say, npm packages with large `node_modules` footprints.
4. **Low package-count is itself a usability feature** for a library whose whole pitch (01) is "just `composer require` one thing and it works everywhere."

**When a future split would make sense:** if a future driver (for example, Oracle, which is deferred from v1 and requires the relatively rare `pdo_oci` extension) develops independent release cadence needs, or if a third-party wants to contribute a driver without going through the core repo's release process, a `sqlcraft/sqlcraft-oracle`-style *satellite* package can be split out later — this is exactly the `$registry->register()` extension point (08 §8, 18 §9) already designed to support out-of-tree drivers with zero core changes. No architectural rework is needed to do this later; it is purely a repository/release-process decision deferred until there's real demand.

---

## 2. Full Directory Tree

```
sqlcraft/
├── composer.json
├── composer.lock                      # committed (library-level lock for CI reproducibility; consumers'
│                                       #   own lock files govern their installs — Composer ignores a
│                                       #   dependency's lock file when it is itself required as a library)
├── CHANGELOG.md
├── LICENSE                            # MIT (see 02-guiding-principles.md licensing stance)
├── README.md
├── phpunit.xml.dist
├── phpstan.neon.dist
├── psalm.xml
├── rector.php
├── .php-cs-fixer.dist.php
├── infection.json.dist
├── deptrac.yaml                       # enforces 06 §4 dependency rules in CI
├── .gitattributes                     # export-ignore, see §9
├── .github/
│   └── workflows/
│       ├── ci.yml                     # unit + static analysis, every push (20 §8)
│       ├── integration.yml            # Testcontainers matrix, scheduled + on-demand (20 §8)
│       └── release.yml                # tag-triggered changelog + packagist notify
├── src/
│   ├── Connection/                    # 07 §5
│   ├── Driver/                        # 07 §6
│   │   ├── MySQL/
│   │   ├── PostgreSQL/
│   │   ├── SQLite/
│   │   ├── SqlServer/
│   ├── Platform/                      # 07 §7
│   │   ├── MySQL/
│   │   ├── PostgreSQL/
│   │   ├── SQLite/
│   │   ├── SqlServer/
│   ├── Metadata/                      # 07 §8
│   ├── Schema/                        # 07 §9
│   ├── DDL/                           # 07 §9
│   ├── Query/                         # 07 §9
│   ├── Execution/                     # 07 §9
│   ├── Import/                        # 07 §10
│   ├── Export/                        # 07 §10
│   ├── Security/                      # 07 §10
│   ├── Events/                        # 07 §10
│   ├── Contracts/                     # 07 §1 — mirrors the module tree above, interfaces only
│   │   ├── Connection/
│   │   ├── Driver/
│   │   ├── Platform/
│   │   ├── Metadata/
│   │   ├── Schema/
│   │   ├── DDL/
│   │   ├── Query/
│   │   ├── Execution/
│   │   ├── Import/
│   │   ├── Export/
│   │   ├── Security/
│   │   ├── Capabilities/
│   │   └── Events/
│   ├── Exceptions/                    # 07 §10 / 05 §9
│   ├── Support/                       # 07 §10
│   ├── Collections/                   # 07 §4
│   ├── DTO/                           # 07 §3
│   ├── ValueObjects/                  # 07 §2
│   ├── Capabilities/                  # 09
│   ├── Utilities/                     # 07 §10
│   ├── SQLCraftFactory.php            # 18 §2.2 — composition root
│   └── DatabaseSession.php            # 18 §2.2 — root consumer-facing aggregate
├── tests/
│   ├── Unit/                          # mirrors src/ 1:1, no DB (20 §2)
│   ├── Integration/                   # per-engine, Testcontainers (20 §3)
│   ├── Contract/                      # PlatformInterface conformance suite (20 §4)
│   ├── Golden/                        # snapshot fixtures for generated SQL (20 §5)
│   │   └── fixtures/
│   ├── Fixtures/                      # shared schema/data fixtures per engine (20 §7)
│   └── bootstrap.php
├── docs/
│   └── plans/                         # this document set (00-21)
├── examples/
│   ├── 01-basic-connection/
│   ├── 02-schema-introspection/
│   ├── 03-ddl-create-table/
│   ├── 04-query-and-paginate/
│   ├── 05-import-export/
│   ├── 06-laravel-integration/
│   ├── 07-symfony-integration/
│   └── 08-multi-engine-comparison/
├── resources/
│   └── capabilities/
│       └── matrix.php                 # see §8 — argued to live in code, not data files
└── tools/
    ├── composer.json                  # isolated tool-dependency lockfile, see §6
    ├── generate-contract-report.php
    └── rector-dry-run.sh
```

---

## 3. `composer.json`

```json
{
    "name": "vendor/sqlcraft",
    "description": "Framework-independent, PDO-based, capability-driven database administration SDK for PHP 8.4+.",
    "type": "library",
    "license": "MIT",
    "keywords": ["database", "dbal", "sql", "schema", "introspection", "ddl", "pdo", "mysql", "postgresql", "sqlite", "mssql", "oracle"],
    "homepage": "https://github.com/vendor/sqlcraft",
    "authors": [
        { "name": "SQLCraft Contributors", "homepage": "https://github.com/vendor/sqlcraft/graphs/contributors" }
    ],
    "support": {
        "issues": "https://github.com/vendor/sqlcraft/issues",
        "source": "https://github.com/vendor/sqlcraft"
    },
    "require": {
        "php": "^8.4",
        "ext-pdo": "*"
    },
    "suggest": {
        "ext-pdo_mysql": "Required to connect to MySQL/MariaDB servers.",
        "ext-pdo_pgsql": "Required to connect to PostgreSQL servers.",
        "ext-pdo_sqlite": "Required to connect to SQLite databases.",
        "ext-pdo_sqlsrv": "Required to connect to Microsoft SQL Server (Microsoft driver).",
        "ext-pdo_dblib": "Alternative FreeTDS-based driver for Microsoft SQL Server on non-Windows.",
        "psr/simple-cache-implementation": "Enables metadata caching (21-performance.md §4). Any PSR-16 implementation.",
        "psr/event-dispatcher-implementation": "Enables domain event dispatch (QueryExecutedEvent, DdlExecutedEvent, etc; 05 §9).",
        "psr/log-implementation": "Enables query/deprecation logging. Any PSR-3 implementation."
    },
    "require-dev": {
        "phpunit/phpunit": "^11.5",
        "phpstan/phpstan": "^2.1",
        "phpstan/phpstan-strict-rules": "^2.0",
        "vimeo/psalm": "^6.5",
        "rector/rector": "^2.0",
        "friendsofphp/php-cs-fixer": "^3.68",
        "infection/infection": "^0.29",
        "qossmic/deptrac": "^2.0",
        "testcontainers/testcontainers": "^0.4",
        "psr/simple-cache": "^3.0",
        "psr/event-dispatcher": "^1.0",
        "psr/log": "^3.0"
    },
    "autoload": {
        "psr-4": {
            "SQLCraft\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "SQLCraft\\Tests\\": "tests/"
        }
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": false,
        "preferred-install": "dist"
    },
    "extra": {
        "branch-alias": {
            "dev-main": "1.x-dev"
        }
    },
    "scripts": {
        "test": "phpunit --testsuite=unit",
        "test:integration": "phpunit --testsuite=integration",
        "test:contract": "phpunit --testsuite=contract",
        "test:all": "phpunit",
        "stan": "phpstan analyse --memory-limit=1G",
        "psalm": "psalm --show-info=false",
        "rector": "rector process --dry-run",
        "rector:fix": "rector process",
        "cs": "php-cs-fixer fix --dry-run --diff",
        "cs:fix": "php-cs-fixer fix",
        "deptrac": "deptrac analyse",
        "infection": "infection --min-msi=80 --min-covered-msi=90",
        "ci": [
            "@stan",
            "@psalm",
            "@cs",
            "@deptrac",
            "@rector",
            "@test"
        ]
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

**Notable decisions embedded in this file:**

- **`require` lists only `php` and `ext-pdo`.** No `ext-pdo_mysql` etc. as hard requirements — a consumer who only ever talks to SQLite should not be forced to install MySQL client libraries. This is what "capability-driven, not hard-required" means at the packaging level, mirroring how capability *gating* (09) works at the API level.
- **No runtime `require` on any PSR package.** All PSR interfaces (`psr/simple-cache`, `psr/event-dispatcher`, `psr/log`) are `suggest`-only and type-hinted as `?Interface` with `null` defaults in constructors (18 §2.2) — SQLCraft functions with zero optional features if none are wired, and gains caching/eventing/logging only when a consumer supplies an implementation. This is stricter than most libraries, which `require` the PSR interface packages outright (interface packages are tiny, so requiring them is common and low-cost) — SQLCraft could reasonably do the same; the choice here to keep even interface packages as `suggest` is a deliberate maximalist reading of "zero or near-zero runtime deps," traded off explicitly in §4.
- **Dev dependencies use caret constraints, not exact pins**, despite the general project guidance elsewhere in this doc set toward conservative pinning — see §4 for why dev-only tooling is treated differently from runtime deps.
- **`allow-plugins: false`** — no Composer plugins run during install, reducing supply-chain surface for a library that will be embedded in security-sensitive consumer applications (e.g., Composer plugins for tools like Rector run inside `require-dev`'s own transitive tree, but disallowing plugins repo-wide is the safer default; tool-specific plugin needs are isolated into `tools/composer.json`, §6).

---

## 4. PSR-4 Namespace-to-Directory Map

| Namespace | Directory | Bounded context (06 §3) |
|---|---|---|
| `SQLCraft\` | `src/` | (root) |
| `SQLCraft\Connection\` | `src/Connection/` | Connection |
| `SQLCraft\Driver\` | `src/Driver/` | Driver |
| `SQLCraft\Platform\` | `src/Platform/` | Platform |
| `SQLCraft\Metadata\` | `src/Metadata/` | Metadata |
| `SQLCraft\Schema\` | `src/Schema/` | Schema |
| `SQLCraft\DDL\` | `src/DDL/` | DDL |
| `SQLCraft\Query\` | `src/Query/` | Query |
| `SQLCraft\Execution\` | `src/Execution/` | Execution |
| `SQLCraft\Import\` | `src/Import/` | Import |
| `SQLCraft\Export\` | `src/Export/` | Export |
| `SQLCraft\Security\` | `src/Security/` | Security |
| `SQLCraft\Events\` | `src/Events/` | Events |
| `SQLCraft\Contracts\` | `src/Contracts/` | Contracts |
| `SQLCraft\Exceptions\` | `src/Exceptions/` | Exceptions |
| `SQLCraft\Support\` | `src/Support/` | Support |
| `SQLCraft\Collections\` | `src/Collections/` | Collections |
| `SQLCraft\DTO\` | `src/DTO/` | DTO |
| `SQLCraft\ValueObjects\` | `src/ValueObjects/` | ValueObjects |
| `SQLCraft\Capabilities\` | `src/Capabilities/` | Capabilities |
| `SQLCraft\Utilities\` | `src/Utilities/` | Utilities |
| `SQLCraft\Tests\` | `tests/` | (test double of the above, mirrored 1:1) |

One namespace segment per bounded context, exactly matching `06-package-architecture.md` §3's table and `07-module-breakdown.md`'s per-module namespace roots — no bounded context spans multiple top-level namespaces, and no top-level namespace spans multiple bounded contexts. `Contracts\` internally subdivides by the same module names (`Contracts\Platform\`, `Contracts\Metadata\`, etc.) purely for file organization; this sub-structure is not itself a bounded-context boundary (06 §3 treats all of `Contracts` as one context).

---

## 5. Dependency Policy

**Runtime dependencies: `ext-pdo` and nothing else, by requirement.** Rationale:

1. **A library embedded in arbitrary host applications must not impose its own dependency tree onto every consumer.** Every runtime `require` SQLCraft adds is a version constraint every consumer's `composer.json` must remain compatible with, forever, across every framework SQLCraft might be used in (18 §0's Laravel/Symfony/Slim/Laminas/Mezzio/CLI/desktop/REST/GraphQL/AI-agent/IDE-extension list). Doctrine DBAL, Flysystem, and the League packages all converge on this same near-zero-runtime-deps posture for exactly this reason, and it is the explicit comparison class named in this project's brief (00-01).
2. **PSR interface packages are the one acceptable exception in principle** (they are tiny, stable, near-universally already present transitively in any modern PHP app) **but are kept `suggest`-only here** (§3) rather than `require`d, because SQLCraft's optional-feature design (cache/events/log all nullable) means it functions correctly with zero of them installed — requiring the interface package for a feature a given consumer may never use is an avoidable constraint, however small.
3. **Dev dependencies are unpinned (caret ranges) deliberately, contrary to the general "pin dependencies" guidance for application code:** a *library's* `require-dev` never ships to consumers (Composer does not install a dependency's `require-dev`), so there is no supply-chain exposure transmitted downstream — pinning tightly here would only slow down the SQLCraft maintainers' own ability to pick up PHPStan/Psalm/Rector bugfixes, and CI (`composer.lock`, committed per §2) still gives full reproducibility for the maintainers' own builds.
4. **No dependency is "typosquatting-risk" here** — every listed package (`phpunit/phpunit`, `phpstan/phpstan`, `vimeo/psalm`, `rector/rector`, `friendsofphp/php-cs-fixer`, `infection/infection`, `qossmic/deptrac`) is a well-known, actively maintained, high-download package; this was checked against each package's canonical vendor name before inclusion.

---

## 6. `tools/` — Isolated Dev Tooling

Some dev tools (notably Rector and PHP-CS-Fixer) have historically had version-compatibility friction with PHPUnit/PHPStan when required in the same `composer.json` (transitive constraint conflicts). SQLCraft avoids this with the well-established **"tools directory with its own `composer.json`"** pattern:

```
tools/
├── composer.json          # requires only rector/rector, isolated from src' require-dev
├── rector-dry-run.sh      # `cd tools && composer install && vendor/bin/rector process --dry-run ../src`
└── generate-contract-report.php   # produces the human-readable contract-test coverage report (20 §4)
```

This directory is excluded from the package's autoload and from Packagist dist archives (§9). It exists purely to let CI run tools whose own dependency trees would otherwise fight with `phpunit`/`phpstan` version constraints in the root `composer.json`. If no such conflict materializes during actual development, this directory may end up holding only standalone scripts (`generate-contract-report.php`) with tools declared in the root `require-dev` after all — the isolated-`composer.json` pattern is a contingency, not a mandate, and is only exercised for a specific tool if a genuine constraint conflict is found.

---

## 7. Versioning

- **SemVer**, scoped to the public API surface defined in `18-public-api.md` §7.
- **`@internal`** docblock tag is the enforcement marker. A CI step (`tools/generate-contract-report.php` or a dedicated PHPStan rule) fails the build if a PR's diff shows a *removed or signature-changed* public (non-`@internal`) class/method without a corresponding major-version bump label on the PR.
- **Deprecation workflow:** mark with `@deprecated since 1.4, use X instead`, log via injected PSR-3 logger at `notice` level on first call per process (not per call, to avoid log flooding), keep for the remainder of the major version, remove at the next major. Tracked in `CHANGELOG.md` under an "Unreleased / Deprecated" heading as it happens, not batched at release time.
- **Branch alias** (`dev-main` → `1.x-dev` in `composer.json` `extra`) lets early adopters track main before the first tagged release without a floating, ambiguous version string.
- **Tagging:** annotated git tags `vX.Y.Z`; `release.yml` (§2) validates the tag matches `CHANGELOG.md`'s latest heading before publishing.

---

## 8. `resources/` — Data vs Code for the Capability Matrix

**Considered:** externalizing the capability matrix (09 §6's table) as a `resources/capabilities/matrix.json` or `.yaml` file, loaded at runtime.

**Decision: keep it in code** (`AbstractPlatform::buildCapabilityMatrix()`, per 08 §4/§7), **not** as an external data file. Reasons:

1. **Version predicates need executable logic, not flat data.** `$v->isAtLeast(8, 0, 16)` (08 §7) is a method call against a `ServerVersion` VO — encoding this in JSON/YAML would require inventing a small expression language and an interpreter for it, purely to avoid writing PHP, which is a net complexity increase for a library whose implementation language already is PHP.
2. **Static analysis coverage.** PHPStan/Psalm can verify `Capability::CheckConstraints` is a valid enum case at the call site when it's PHP code; a string key in a YAML file gets zero such checking until parsed at runtime.
3. **No legitimate reason for a *consumer* to edit the matrix without also changing platform behavior** — unlike, e.g., a translations file or a config a deploying team tunes, the capability matrix is a statement of engine *fact* ("MySQL supports CHECK from 8.0.16"), not a tunable. Data-file externalization is the right call when non-developers or the *host application's deployment* need to change values; that does not apply here.

The `resources/` directory therefore ships nearly empty in v1 — a placeholder for genuinely data-shaped future assets (e.g., a canonical list of reserved-word lists per platform for `getKeywordList()`, 08 §3.4, if that list grows large enough that embedding it as a PHP array literal becomes unwieldy). It is kept in the tree now because PSR-4 packages conventionally reserve `resources/` for this purpose, and its presence signals the intended seam without forcing a premature YAML/JSON format decision.

---

## 9. `.gitattributes` — Dist Export Hygiene

```
/tests            export-ignore
/examples         export-ignore
/tools            export-ignore
/docs             export-ignore
/.github          export-ignore
/.php-cs-fixer.dist.php  export-ignore
/phpstan.neon.dist       export-ignore
/psalm.xml               export-ignore
/rector.php              export-ignore
/infection.json.dist     export-ignore
/deptrac.yaml            export-ignore
.gitattributes           export-ignore
.gitignore               export-ignore
CHANGELOG.md             export-ignore
```

`export-ignore` keeps `composer install --prefer-dist` archives (what most production deploys actually pull) limited to `src/`, `composer.json`, `LICENSE`, and `README.md`. Test suites, tooling config, docs, and examples remain fully available via `git clone` or `--prefer-source`, and are never stripped from the *repository* — only from the distributed release archive. This shrinks the on-disk footprint of every production `vendor/vendor/sqlcraft/` install, which matters at the scale of "installed into every Laravel/Symfony/CLI/etc. app that adopts it" (18 §0).

---

## 10. `examples/` Layout

Each numbered directory under `examples/` (§2) is a **standalone, runnable** script with its own minimal inline setup (an in-memory SQLite connection wherever the example doesn't specifically demonstrate a different engine), corresponding 1:1 to the ten workflows demonstrated in `18-public-api.md` §3:

```
examples/
├── 01-basic-connection/run.php          # 18 §3.1
├── 02-schema-introspection/run.php      # 18 §3.2 + §3.3
├── 03-ddl-create-table/run.php          # 18 §3.4 + §3.5
├── 04-query-and-paginate/run.php        # 18 §3.6
├── 05-import-export/run.php             # 18 §3.8 + §3.9
├── 06-laravel-integration/               # full mini Laravel app skeleton, 18 §8.3
├── 07-symfony-integration/               # full mini Symfony app skeleton, 18 §8.4
└── 08-multi-engine-comparison/run.php   # 18 §3.11 — same code, 3 engines side by side
```

Examples are excluded from the package autoload (`autoload-dev` does not include `examples/`) and are excluded from dist archives (§9) — they are documentation-by-code for people browsing the repository, not a runtime asset.
