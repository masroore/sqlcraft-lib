# M10 Release Verification Blocker

Date: 2026-07-21

M10 documentation and API audit work is complete. Release verification remains
blocked by the required Infection threshold.

## Green checks

- `docker compose run --rm php composer run ci` — green.
- `docker compose run --rm php composer audit` — green; no security advisories.
- `docker compose run --rm php composer run cs:fix` — no formatting changes.
- All eight `examples/*/run.php` scripts — green against in-memory SQLite.
- SQL Server contract tests — green after starting the non-Oracle engine services.
- `php` has no `depends_on` entry; engine services remain independently started.

Release verification also replaced abandoned `qossmic/deptrac` with maintained
`deptrac/deptrac` 4.7 without changing `deptrac.yaml` dependency rules. The
streaming importer now splits oversized statement batches at the documented
1,000-statement safety limit. SQL Server's healthcheck uses explicit IPv4 to
avoid a localhost/IPv6 false-negative.

## Failing gate

Command:

```bash
docker compose run --rm php vendor/bin/infection \
  --min-msi=80 --min-covered-msi=90 \
  --initial-tests-php-options='-d pcov.enabled=1'
```

With MySQL, MariaDB, PostgreSQL, and SQL Server running, Infection completed its
full run but reported:

- 4,843 mutants generated
- 2,786 killed
- 1,157 uncovered
- 886 covered but survived
- 4 errors and 10 timeouts
- Mutation Score Indicator: **57%** (required: 80%)
- Covered Code MSI: **75%** (required: 90%)

The earlier randomized run exposed a real importer defect (a 20,000-statement
batch exceeded the 1,000-statement limit); that defect is fixed and the full CI
suite remains green. The remaining failure is the project's existing mutation
coverage gap across the broad source tree, not a transient engine failure.

Do not create the `v1.0.0` tag until a dedicated mutation-coverage task adds or
refines tests and reruns Infection at the required thresholds.
