# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Lumina — orquestrador de serviços e integrações. PHP 8.4 API on the **Hyperf** framework (Swoole coroutine runtime), backed by MySQL (via `hyperf/database`) and Redis (cache).

## Commands

```bash
# Run (inside container or with Swoole ext installed locally)
composer start              # php bin/hyperf.php start        — prod-style, port 9501
composer watch               # php bin/hyperf.php server:watch — hot reload (dev)

docker compose up            # runs the app + redis; app also serves Swagger UI on :9500

# Tests (PHPUnit via co-phpunit, coroutine-aware)
composer php-unit
composer php-unit -- --filter TestName          # single test
vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/ExampleTest.php  # single file

# Static analysis / style
composer analyse             # phpstan, forced to -l 0 via script flag even though phpstan.neon sets level: 9
composer cs-check            # php-cs-fixer --dry-run --diff
composer cs-fix              # php-cs-fixer fix

composer test                # runs cs-check + php-unit + analyse, in that order (matches CI expectations)
```

No `.env` present by default in a fresh checkout — copy `.env.example` to `.env` first (composer's `post-root-package-install` does this automatically on a fresh `composer create-project`, but not on a plain clone).

## Architecture

Standard Hyperf skeleton wiring — most "framework" behavior lives in vendor packages and is turned on/off via `config/autoload/*.php`, not in `app/`:

- **Bootstrap**: `bin/hyperf.php` → `config/container.php` builds the PSR-11 DI container from annotation/attribute scanning (`config/autoload/annotations.php` scans `app/`) → `Hyperf\Contract\ApplicationInterface`.
- **Routing**: `config/routes.php` (`Hyperf\HttpServer\Router\Router`), dispatched to controllers under `App\Controller`.
- **Controllers**: extend `App\Controller\AbstractController`, which injects `RequestInterface`/`ResponseInterface`/`ContainerInterface` via `#[Inject]` (Hyperf's attribute-based DI, not constructor injection).
- **Models**: extend `App\Model\Model` → `Hyperf\DbConnection\Model\Model` (Eloquent-style ORM). DB config in `config/autoload/databases.php` uses read/write split with `sticky => true` (same-request reads hit the write connection to dodge replica lag) and a large coroutine connection pool (32–512 connections) — this is tuned for Swoole's concurrency model, don't naively shrink it.
- **Listeners**: `#[Listener]`-attributed classes in `app/Listener` are auto-registered (no manual wiring beyond `config/autoload/listeners.php` for framework-provided ones). `DbQueryExecutedListener` logs every interpolated SQL query to the `sql` log channel. `ResumeExitCoordinatorListener` resumes the `WORKER_EXIT` coordinator after CLI command execution — required because Hyperf commands run inside a coroutine and won't exit cleanly without it.
- **Middleware pipeline**: `config/autoload/middlewares.php` (`http` key) — currently empty.
- **Swagger**: `hyperf/swagger`-style setup in `config/autoload/swagger.php`, scans `app/` for OpenAPI attributes, serves on a **separate port (9500)** from the main HTTP server (9501). The UI HTML is read from `storage/swagger/swagger-ui.html` instead of the CDN default, specifically to avoid depending on `unpkg.hyperf.wiki`.
- **Dev watcher**: `hyperf/watcher` watches `app/`, `config/`, and `.env` for changes (`config/autoload/watcher.php`), used by `composer watch` / `docker-compose.override.yml` (which swaps the container command to `server:watch`).
- Code generators (`gen:model`, `gen:request`, etc.) target namespaces set in `config/autoload/devtool.php` (`App\Command`, `App\Middleware`, `App\Job`, `App\Amqp\Consumer/Producer`, etc.) — none of those directories exist yet, they're created on first use.

## Code style

PHP-CS-Fixer (`.php-cs-fixer.php`) enforces `@PSR2` + `@Symfony` + `@DoctrineAnnotation` + `@PhpCsFixer` rule sets, short array/list syntax, and a mandatory Hyperf license PHPDoc header on every file (`cs-fix` inserts it automatically — don't hand-write it and don't drop it from new files).

## ⚠️ Known broken state

Commit `e9d4769` ("fix: removido rotas de teste") deleted the entire ACL/CRUD layer that previous commits had built: `AclMiddleware`, `AclRouteOptions`, `AbstractRepository`/`AbstractCrudService`/`AbstractCrudController`, the `Pessoa*` controllers/models/services/repositories, and the custom exception handlers. `app/` is currently back to a bare Hyperf skeleton (just `AbstractController`, `IndexController`, `Model`, and the two listeners).

**`test/Cases/AclRouteOptionsTest.php` was not removed** and still references `App\Controller\PessoaController`, `App\Middleware\AclMiddleware`, and `App\Support\AclRouteOptions` — none of which exist anymore. Running `composer php-unit` / `composer test` will fatal on class-not-found for that file. Confirm with the user whether the deletion was intentional before touching this area — either the test should go too, or the ACL/CRUD layer needs restoring.
