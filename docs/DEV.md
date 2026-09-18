# Development notes

## Requirements

PHP 8.2 or newer with `curl`, `json`, `mbstring` and `zlib`. Nothing else is
needed at runtime. PHPUnit is the only development dependency.

If the PHP on your machine is older, or was built without argon2 support, run
the tooling in a container instead. The `Makefile` does that for you:

```bash
make deps     # install PHPUnit with Composer
make check    # lint + unit tests
make build    # rebuild data/dashboard.json from data/history.json
make serve    # development server on http://127.0.0.1:8099
```

Most targets wrap a container invocation of this form:

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app -w /app php:8.3-cli vendor/bin/phpunit
```

Four targets deliberately do not: `serve` runs the host PHP, and the YouTube
ones — `voices-venv`, `voices` and `publish-voices` — need the host's Python
virtualenv for the caption fetcher and an SSH key to publish. That job never
runs on the VPS: YouTube refuses caption requests from datacenter addresses.

## Conventions

- `declare(strict_types=1);` in every file. Classes are `final` unless there is
  a reason not to be, dependencies arrive through the constructor, and there is
  no global or static mutable state.
- Identifiers, comments and documentation are in English. Italian appears only
  in strings a user reads: metric names, chart captions, the glossary.
- Comments explain *why* a piece of code exists, not *what* it does. No
  commented-out code and no leftover markers.
- Every write to disk goes through a temporary file and an atomic `rename()`
  under `flock`, with `0600` on files and `0700` on directories.
- Error suppression with `@` is limited to a single call whose result is
  checked immediately afterwards.
- Time, sleeping and HTTP are injected as callables or small interfaces. That
  is what keeps the test suite instant and free of network access.

## Tests

PHPUnit 11, one test class per production class, namespace
`ManorLedger\Tests\<Area>`, temporary directories created under
`sys_get_temp_dir()` and removed in `tearDown`. Fixtures live in
`tests/fixtures/`, are synthetic, and contain no real figures.
`tests/fixtures/legacy-cache/` is a generated 45-day dataset with weekend
seasonality and a planted anomaly; continuous integration imports it and builds
a dashboard from it, which exercises the whole pipeline without an API key.

Tests never reach the network and never read a credential.

## Working on the data pipeline

```bash
php bin/import-legacy tests/fixtures/legacy-cache --catalog=config/metrics.json
php bin/build --print | head -40
```

`bin/refresh` is the only command that talks to Roblox. It needs a key file at
`data/api-key` with mode 600, and it accepts `--dry-run`,
`--only=Metric1,Metric2` and `--if-older-than=HOURS`.

The data contracts, binding for anyone touching the pipeline or the frontend,
are in [ARCHITECTURE.md](ARCHITECTURE.md).
