# Nafinity deployment measurements, 6 October 2026

This is a dated acceptance record. It is not a latency promise or a production capacity estimate.
Results are in [benchmarks/2026-10-06](benchmarks/2026-10-06/); the portable harness is in
[tools/benchmarks](../tools/benchmarks/). Backups, cookies, certificates, environments and generated
fixture packages are excluded. AI measurements and model digests live in the Board package's
`tests/benchmarks/results/2026-10-06.json`.

## Environment and method

Apple M4 Pro, Mac16,8, 24 GiB RAM; OrbStack Linux arm64, PHP 8.5.10 and MariaDB 11.4.
CLI OPcache is disabled; the real nginx/PHP-FPM hosts have OPcache enabled.
Development mounts the working packages; production installs public Packagist distributions
inside the image, with no Composer or source symlinks in its final filesystem.

Core measurements use a fresh framework-only Composer project, not the Board's autoloader.
The harness generates explicitly synthetic chain-ordered plugins, six resource kinds per plugin,
and a bootstrap counter. Component samples have one warm-up plus 51 runs; actual constructor
boots use one discarded process plus 20 fresh PHP processes. Composer-autoloader startup is
outside the boot clock. First event dispatch excludes registration; warm dispatch excludes sorting.

HTTP uses verified localhost HTTPS, real PHP-FPM and ordinary authenticated requests. Each
host has ten warm-ups, 50 sequential samples and 120 requests across four independent sessions.
Four sessions avoid measuring PHP's serialization of a single session cookie. SQL counts are
MariaDB's per-session `Questions`, including connection setup; the instrumentation query itself
is excluded. Peak memory is PHP's allocated process peak. Response time includes TLS transport.

Move contention deliberately submits two requests with identical versions: one 200 and one 409
per pair. Its 50% conflict rate is a forced test, not a prediction of user behavior. A 250 ms
project-row lock holder isolates lock wait. Rebalancing uses the same three owned cards and
five repetitions; both versions operate on the same 5,004-card cell. Positions remain unique
and the requested order is checked. No hardware-specific millisecond assertion is added to CI.

## Reproduce

Run from a prepared local development installation with Docker running, installed development
dependencies, published assets and localhost certificates available. The
benchmark Compose project is named `nafinity-acceptance`; its schema is `nafinity_probe`.
Credentials in that file and the demo login are public disposable fixture values.
`NAF_BENCH_SOURCE_ROOT` may point to a development package workspace. Never use a production
schema, storage volume or remote URL. The ordinary installation's database is not a fixture.

```sh
mkdir -p work/evidence
make certificates
docker build --target production -f docker/Dockerfile -t nafinity:production-probe .
docker compose -f tools/benchmarks/compose.production.yaml up -d --wait db app dev
docker compose -f tools/benchmarks/compose.production.yaml exec -T app php vendor/bin/naf db:migrate up
docker compose -f tools/benchmarks/compose.production.yaml exec -T app php vendor/bin/naf rbac:sync
docker compose -f tools/benchmarks/compose.production.yaml exec -T -e APP_ENV=test app php vendor/bin/naf nafinity:seed
docker compose -f tools/benchmarks/compose.production.yaml exec -T -e APP_ENV=test app php < tools/benchmarks/reset-limits.php
python3 tools/benchmarks/http-benchmark.py > work/evidence/http-results.json
docker compose -f tools/benchmarks/compose.production.yaml exec -T -e APP_ENV=test app php < tools/benchmarks/bulk-data.php > work/evidence/bulk-data.json
docker compose -f tools/benchmarks/compose.production.yaml exec -T -e APP_ENV=test app php < tools/benchmarks/reset-limits.php
NAF_BENCH_DATASET='5,000 owned bulk cards plus seed' python3 tools/benchmarks/http-benchmark.py > work/evidence/http-5000-results.json
docker compose -f tools/benchmarks/compose.production.yaml exec -T -e APP_ENV=test app php < tools/benchmarks/reset-limits.php
python3 tools/benchmarks/moves-benchmark.py > work/evidence/moves-results.json
```

The dev host needs its normal `make composer-install` and published assets before it is usable.
A new probe storage volume has no record of the dev host's previously published files: use the
normal installation's asset publication before testing development. The explicit reset helper
requires `APP_ENV=test` and `DB_DATABASE=nafinity_probe`, touches only that schema's counters
and keeps all limiter rules enabled. Alternatively wait for the ordinary ten-minute window.

`http-metrics.php` is mounted only by this disposable Compose configuration. It is never copied
into `app/src/` or the production image. After measurement, remove only the probe project:

```sh
docker compose -f tools/benchmarks/compose.production.yaml down -v
```

For the core-only series, install `naf/framework:^0.2.8` in a fresh temporary Composer project,
then pass its absolute autoloader and an empty scratch directory:

```sh
php -n tools/benchmarks/core-benchmark.php /absolute/core-project/vendor/autoload.php /tmp/naf-core-fixtures
```

Repeat with the same files on the dev mount and on the container filesystem. A framework-only
autoloader matters: loading another installed plugin would add its real bootstrap to this synthetic
catalog. `core-benchmark.php` refuses to overwrite a nonempty scratch directory without its marker.

## Decisions

The production filesystem and OPcache provide the main boot improvement. A persistent core
catalog is deferred with reasons in [optional-core-cache.md](optional-core-cache.md).
Rebalancing was the concrete SQL bottleneck and was optimized in the Board package. Query/card
payload limits and the existing project lock remain; ordinary moves already take around 11 ms.
Native Chrome/Firefox dragging still needs manual acceptance because the desktop input tool
cannot associate the visible window with a drag target. Keyboard moves and the mobile viewport
are verified. No physical touch-device acceptance or external LDAP/OIDC/SMTP acceptance is claimed.

## Recorded baseline

| Request/data | Production p50/p95 | Dev-mount p50/p95 | SQL | PHP peak |
|---|---:|---:|---:|---:|
| Seed board | 10.60 / 15.91 ms | 17.99 / 22.45 ms | 23 | 2 MiB |
| 5,000 additional cards, 300 returned | 28.03 / 30.27 ms | 41.48 / 43.26 ms | 23 | 4 MiB |

The four-session throughput was 297 versus 133 requests/s on the small board,
and 130 versus 73 requests/s on the large board. These are local synthetic workloads.
They establish the deployment/mount difference, not remote user capacity.

Before the rebalance patch, the same 5,004-card cell took 389.03 ms p50 and 394.57 ms p95
across five requests, each with 10,038 SQL statements. All twenty forced same-version
pairs produced exactly one success and one stale-version conflict.

Primary-action contrast was also checked: Classic light 5.10:1, Anthracite light 5.73:1
and Anthracite dark 9.41:1. The real Classic system-dark browser rendered white on violet
at 2.77:1; the corrected foreground rendered after reload gives 5.87:1. Explicit dark
uses the same foreground declaration. Exact colors are in `theme-contrast.json`.

## Verified public-release result

The production image resolves framework 0.2.8, Board 0.1.5 and RBAC 0.1.3 at their exact
verified release SHAs. Composer and NAF source symlinks are absent. The final five
rebalance requests on the same 5,004-card cell take 108.23 ms p50 and
119.51 ms p95, with exactly 72 SQL statements each. This reduces the
measured median by 72.18% and SQL statements by 99.28%.

The final series ran after the local documentation checks completed. Between repeated
benchmark runs, only the disposable probe schema's limiter counters were reset; its
limit rules remained enabled. Login, foreign-project/download 404, byte-exact private
download and filtered/truncated drag-lock flags passed. See `http-015-results.json` and
`rebalance-comparison.json` for the complete final data.
