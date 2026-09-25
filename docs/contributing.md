# Contributing

## Development environment

Everything runs in Docker (`compose.yaml`):

| Service | Image | Role |
|---|---|---|
| `php` | `.docker/php` (PHP 8.2 CLI + gmp, bcmath, pdo_mysql, pdo_pgsql, zip, pcov; curl, sodium and pdo_sqlite come with the base image) | PHPUnit and the quality tools; `WEB_PUSH_TEST_MYSQL_DSN` points to `mysql`, `WEB_PUSH_TEST_PGSQL_DSN` to `postgres` |
| `node` | `node:22-alpine`, working directory `assets/` | Yarn 4 (Corepack), Vitest, TypeScript, Vite |
| `mysql` | `mysql:8.4` on tmpfs | Contract tests of the Doctrine and Eloquent adapters on MySQL |
| `postgres` | `postgres:17-alpine` on tmpfs | Contract tests of the Doctrine and Eloquent adapters on PostgreSQL |

The MySQL and PostgreSQL variants of the contract tests are skipped when their DSN variable is
not set; outside Docker, export for example
`WEB_PUSH_TEST_PGSQL_DSN=pgsql://web_push:web_push@127.0.0.1:5432/web_push`. The repository
contract passes on SQLite, MySQL 8.4 and PostgreSQL 17.

```bash
make install    # build and start the containers, composer install, tools/* install, yarn install --immutable
make help       # every target
```

JavaScript commands always go through the `node` container, never the host:

```bash
docker compose exec node yarn test
docker compose exec node yarn add -D <package>
```

(The Makefile enables Corepack in `/tmp/corepack-bin` inside the container; `make vitest`,
`make typecheck` and `make js.build` wrap it.)

## Make targets

| Target | Runs |
|---|---|
| `make tests` | `phpunit` + `vitest` + `typecheck` |
| `make phpunit` | PHPUnit, suites `unit`, `contract`, `security`, `symfony`, `laravel` |
| `make vitest` | Vitest (`assets/tests`) |
| `make typecheck` | `tsc --noEmit` |
| `make js.build` | Rebuild `assets/dist` (commit the result) |
| `make quality` | `cs` + `phpstan` (level max) + `rector` (dry-run) + `deptrac` |
| `make cs.fix`, `make rector.fix` | Apply fixes |
| `make infection` | Mutation testing on `src/Domain` and `src/Application` (MSI ≥ 80) |

## Layout

```
src/Domain           pure PHP, no framework (symfony/string only)
src/Application      use cases, ports, payload contract, HTTP parsing
src/Infrastructure   Minishlink transport, network, crypto, persistence mapper, in-memory adapters
src/Bridge/Symfony   bundle, Doctrine DBAL adapter, controllers, Messenger, Notifier, Twig, commands
src/Bridge/Laravel   service provider, Eloquent adapter, controllers, job, channel, Blade, commands
src/Testing          SubscriptionRepositoryContract + TestBrowser, shipped for third-party adapters
assets/src           TypeScript sources (page client, controller, service worker)
assets/dist          built files, COMMITTED (the prebuilt worker is served by the PHP route)
assets/tests         Vitest
tests/Unit           Domain, Application, Infrastructure
tests/Contract       the in-memory adapter against src/Testing/SubscriptionRepositoryContract
tests/Security       SSRF, capability leak, encryption at rest
tests/Integration    Symfony (TestKernel, SQLite + MySQL + PostgreSQL) and Laravel (Testbench, same databases)
tests/Fixtures       payload fixtures shared with Vitest, stub worker
tools/<tool>         one isolated composer.json per quality tool
```

`tools/` holds `phpstan`, `php-cs-fixer`, `rector`, `infection` and `deptrac`, each with its own
`composer.json` / `composer.lock`, so their dependencies never mix with the package's.
Configurations are at the root (`phpstan.dist.neon`, `.php-cs-fixer.dist.php`, `rector.php`,
`infection.json5`, `deptrac.yaml`).

`src/Testing` is a deptrac layer of its own: it may depend on Domain, Application and PHPUnit,
and nothing in `src/` depends on it. `phpunit/phpunit` is only a `suggest` of the package: an
adapter author installs it anyway to run the contract.

## Conventions

- Tests: one behaviour per method, named `it_should_*`, with the `#[Test]` attribute:

  ```php
  #[Test]
  public function it_should_refuse_an_endpoint_carrying_user_information(): void
  ```

- Every class `final` unless a framework imposes inheritance; readonly value objects with named
  constructors; strings through `symfony/string` (`u()`, `b()`); no framework in `src/Domain`
  and `src/Application` (deptrac fails otherwise).
- Never put an endpoint, a key or a secret in an exception message, a log context or a test
  failure message; mark such parameters `#[\SensitiveParameter]`.
- A change to the payload means changing `schema/v1.json`, `tests/Fixtures/payload/*.json`, the
  encoder and the worker together (see [payload-contract.md](payload-contract.md)).
- A change to `assets/src` means running `make js.build` and committing `assets/dist`: CI fails
  otherwise. Bump `SW_VERSION` in `assets/src/contract.ts` for any behavioural change of the worker.
- Code, comments and documentation in English.

## CI (`.github/workflows/ci.yml`)

| Job | Content |
|---|---|
| `php-tests` | Matrix PHP 8.2 with Symfony 6.4 without Laravel (`--prefer-lowest`, suites `unit`, `contract`, `security`, `symfony`: Laravel 12 needs Symfony ≥ 7.2 components), PHP 8.2 with Symfony 7.4 + Laravel 12 (`--prefer-lowest`), 8.2 / 8.3 / 8.4 / 8.5 with Symfony 7.4 and Laravel 12 (highest); MySQL 8.4 and PostgreSQL 17 services; `composer audit`; PHPUnit. The Symfony 6.4 job is the only one exercising the `_web_push_expires` path of `SymfonyActionUrlSigner` |
| `symfony-8` | PHP 8.4, Symfony 8.0 without Laravel (Laravel 12 needs Symfony 7 components); suites `unit`, `contract`, `security`, `symfony` |
| `php-quality` | `composer validate --strict`, PHP-CS-Fixer, PHPStan, Rector, deptrac, Infection (`--min-msi=80 --min-covered-msi=80`) |
| `frontend` | `yarn install --immutable`, `yarn npm audit --all --recursive --severity high`, typecheck, Vitest, rebuild of `dist/` + `git diff --exit-code -- dist` |

PHPUnit fails on risky tests and deprecations, so a few `require-dev` lower bounds only exist for
the `--prefer-lowest` jobs:

- `symfony/error-handler` `^6.4.44 || ^7.4.17 || ^8.1.5`: older releases leave the exception
  handler registered by the kernel behind, every kernel test is then risky.
- `masterminds/html5` `^2.7.5` (pulled by `symfony/dom-crawler`): older releases trigger a PHP
  deprecation in `DOMImplementation::createDocument()`.
- `doctrine/doctrine-bundle` `^3.0` is allowed for Symfony 8, which DoctrineBundle 2 does not support.
- On PHP ≥ 8.4 the test kernel enables `doctrine.orm.enable_native_lazy_objects`:
  `symfony/var-exporter` 8 no longer ships the LazyGhost proxies Doctrine ORM relies on otherwise.

Actions are pinned by commit SHA, the workflow runs with `permissions: contents: read` and never
uses `pull_request_target`.

## Releasing

1. Update `CHANGELOG.md` and the `version` of `assets/package.json`; run `make js.build`,
   `make tests`, `make quality`.
2. Commit, tag `vX.Y.Z`, push the tag. Packagist picks the tag up through the GitHub hook (the
   Composer archive excludes `tests/`, `tools/`, `docs/`, `art/`, `assets/src`, `assets/tests`;
   `src/`, including `src/Testing`, and `assets/dist` are always shipped).
3. Nothing to publish on npm: the JS client ships in `assets/dist` inside the Composer archive
   and applications install it with `file:vendor/romainmillan/web-push-notification/assets`.
   `assets/package.json` is `"private": true` so it cannot be published by accident.
4. Enable 2FA on the GitHub and Packagist accounts.
