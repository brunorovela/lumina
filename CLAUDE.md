# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Lumina — orquestrador de serviços e integrações. PHP 8.4 API on the **Hyperf** framework (Swoole coroutine runtime), backed by MySQL (via `hyperf/database`) and Redis (cache).

The MySQL schema is **shared with the legacy LMS** (`lms2`), a Laminas application whose source lives outside this repo at `~/uni-docker-hub/apps/lms`. Tables named `unim_*`, `lgin_*`, `ulms_*` and `saas_*` are not ours to redesign — read the LMS to learn how a table is meant to be used before assuming.

## Regras deste projeto

Regras definidas pelo dono do projeto. Valem sempre, e não são sugestões.

### 1. Swagger acompanha o endpoint, sempre

Toda mudança que afete o contrato HTTP — rota nova, parâmetro novo, campo novo na resposta, status novo, mudança de default — **atualiza os atributos `#[OA\...]` no mesmo commit**. Swagger desatualizado é pior que ausente: o cliente confia nele.

Antes de dizer que uma implementação está pronta, **verifique** que atualizou, não presuma. `rtk proxy grep -n "OA\\\\" app/Controller/<...>` e confira contra o que você mudou. O Swagger é publicado em `:9500` e o container `lumina-docs` o serve — ele é lido de verdade.

### 2. Não crie migration para modificar o banco

Nada de `migrations/` novo para alterar schema, criar coluna, inserir dado de domínio ou conceder permissão. O schema é compartilhado com o LMS legado e a mudança não é nossa para fazer sozinhos.

Quando uma coluna, um privilégio ou um par recurso/privilégio faltar: **pare e diga o que falta**, com o SQL exato que resolveria, e deixe a decisão com quem tem a caneta do banco. Não aplique.

As três migrations em `migrations/` são históricas — já aplicadas em `lms2`. Não as apague (a tabela `migrations` tem registro delas) e não crie companheiras.

### 3. `sn_excluido` não existe para você — use `dt_excluido`

`dt_excluido` é a **única** fonte de verdade sobre exclusão. Nunca leia, filtre, ordene ou ramifique lógica por `sn_excluido`, mesmo onde a coluna existir. `SoftDeletes` nos models aponta para `dt_excluido` (`const DELETED_AT`), e é assim que fica.

Uma exceção, e só ela: **`unim_pessoa_juridica.sn_excluido` é `NOT NULL` sem default no banco**, então o INSERT é obrigado a mandar um valor. `UnimPessoaJuridica::$attributes` supre `false` unicamente para satisfazer a constraint — não é lógica de exclusão e nada deve ler aquilo. Consertar isso de verdade exigiria dar um default à coluna, ou seja migration, o que a regra 2 proíbe. Deixe como está.

### 4. Teste o que é necessário ou crítico — não tudo

Teste vale pelo bug que pega, não pela contagem. Escreva teste quando:

- a falha seria **silenciosa** (relação que vem `null` sem erro, chave de ACL que nega tudo, `select` vazio que gera SQL inválido);
- há **regra de negócio** própria (o que apagar ao trocar o tipo pessoa, login isento de física/jurídica, clamp de `per_page`);
- há **escopo de tenant ou PII** em jogo (`cd_cliente` no `WHERE`, campo que não pode vazar);
- é **regressão** de bug que já aconteceu.

Não escreva teste que só reafirma o que o tipo já garante, exercita getter trivial, ou repete pelo endpoint o que já está provado em unidade. Uma asserção que não falharia com o defeito presente não é teste — é ruído que custa manutenção.

## Commands

**PHP does not exist on the host — only inside the `lumina` container.** Prefix every PHP/composer command with `docker exec lumina`.

```bash
# Run
composer start               # php bin/hyperf.php start        — prod-style, port 9501
composer watch               # php bin/hyperf.php server:watch — hot reload (dev)

docker compose up            # app + redis; Swagger UI on :9500

# Tests (PHPUnit via co-phpunit, coroutine-aware)
docker exec lumina composer php-unit
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/ExampleTest.php --filter testName

# Static analysis / style
docker exec lumina composer analyse    # phpstan; the LEVEL is not on the command line — see below
docker exec lumina composer cs-check   # php-cs-fixer --dry-run --diff
docker exec lumina composer cs-fix     # php-cs-fixer fix

docker exec lumina composer test       # cs-check + php-unit + analyse, in that order (CI expectation)
```

Migrations run with `docker exec lumina php /opt/www/bin/hyperf.php migrate --force`, but **do not write new ones** — see regra 2.

**PHPStan level lives in `phpstan.neon.dist`, and only there — currently `level: 10`, zero errors.** The `analyse` script deliberately passes no `-l`: when it did, the flag silently overrode the versioned template and the declared level meant nothing. `phpstan.neon` (no `.dist`) is gitignored and optional; PHPStan discovers the `.dist` on its own, so a clean clone works without copying anything.

Annotate array types on every new method (`array<string, mixed>`, `string[]`) — level 10 rejects bare `array`.

No `.env` in a fresh clone — copy `.env.example` first (composer's `post-root-package-install` does it on `create-project`, not on `git clone`).

## Architecture

Standard Hyperf skeleton wiring — most "framework" behavior lives in vendor packages and is turned on/off via `config/autoload/*.php`, not in `app/`:

- **Bootstrap**: `bin/hyperf.php` → `config/container.php` builds the PSR-11 DI container from annotation/attribute scanning (`config/autoload/annotations.php` scans `app/`) → `Hyperf\Contract\ApplicationInterface`.
- **Routing**: `config/routes.php` (`Hyperf\HttpServer\Router\Router`), dispatched to controllers under `App\Controller`.
- **Controllers**: extend `App\Controller\AbstractController`, which injects `RequestInterface`/`ResponseInterface`/`ContainerInterface` via `#[Inject]` (Hyperf's attribute-based DI, not constructor injection).
- **Models**: extend `App\Model\Model` → `Hyperf\DbConnection\Model\Model` (Eloquent-style ORM). DB config in `config/autoload/databases.php` uses read/write split with `sticky => true` (same-request reads hit the write connection to dodge replica lag) and a large coroutine connection pool (32–512 connections) — tuned for Swoole's concurrency model, don't naively shrink it.
  - `App\Model\Model` carries `@method static` shims for `create()`/`updateOrCreate()`/`where()`: Hyperf resolves those via `__callStatic`, so without the shims PHPStan sees them as undefined and treats the return as `mixed`, which then contaminates every chained call.
  - Models declare `@property` for their columns. Without it, every attribute access is `property.notFound` at level 10.
- **Listeners**: `#[Listener]`-attributed classes in `app/Listener` are auto-registered. `DbQueryExecutedListener` logs interpolated SQL to the `sql` channel and **stops when `APP_ENV=production`** — it would otherwise print bcrypt hashes and PII in plain text. `ResumeExitCoordinatorListener` resumes the `WORKER_EXIT` coordinator after CLI commands, which run inside a coroutine and won't exit cleanly without it.
- **Middleware pipeline**: `config/autoload/middlewares.php` (`http` key) is **deliberately empty**. A global middleware runs before every per-route one, so a global `ValidationMiddleware` let an unauthenticated client discover a route's validation contract before authenticating. It is declared per route in `config/routes.php`, always **after** `AuthMiddleware`/`AclMiddleware` in the same list. Read the comments in both files before moving anything.
- **Swagger**: `config/autoload/swagger.php` scans `app/` for OpenAPI attributes, serves on a **separate port (9500)**. The UI HTML comes from `storage/swagger/swagger-ui.html`, not the CDN, to avoid depending on `unpkg.hyperf.wiki`.
- **Dev watcher (hot reload)**: `hyperf/watcher` polls `app/` and `config/` plus `.env` every 2s (`ScanFileDriver`, `config/autoload/watcher.php`). **Not active until you activate it** — `docker-compose.override.yml` is gitignored, so a fresh clone runs the Dockerfile's `start` command and every code change needs a manual container restart. To enable:

  ```bash
  cp docker-compose.override.yml.dist docker-compose.override.yml
  docker compose up -d lumina    # only lumina — recreating everything drops redis, killing live sessions and the ACL cache
  ```

  Reload lands in ~3s. It does **not** pick up changes to `composer.json`/`vendor/`, to files outside `app/`+`config/` (e.g. `storage/languages/`), or to `watcher.php` itself — those still need a restart. Each reload kills the workers, so a long-running coroutine dies mid-flight.
- Code generators (`gen:model`, `gen:request`, …) target namespaces in `config/autoload/devtool.php`; the ones without a directory yet (`App\Command`, `App\Job`, `App\Amqp\*`) get created on first use.

## ACL — as chaves são as do LMS, não invente

`AclMiddleware` resolves permission by comparing **`ds_chave` exactly as stored** in `ulms_recurso` / `ulms_privilegio` — all uppercase, e.g. `GERENCIAR_PESSOA` + `ACESSAR`. A key that does not exist in those tables **denies everything without raising anything**: no error, no log, just 403 on every request. This already cost a full debugging session.

- Use `App\Enum\Recurso` and `App\Enum\Privilegio`. They mirror `Lms\Enum\UlmsRecurso` / `UlmsPrivilegio` in the legacy LMS.
- There is **no `listar` or `visualizar` privilege.** Reading is `ACESSAR` — see `Admin\Controller\GerenciarPessoaController::listarAction()` in the LMS.
- `HyperfTest\Cases\Enum\AclEnumParidadeTest` checks every enum case and every route's ACL pair against the live database. If it fails, a key does not exist — do not "fix" the test. E não crie migration para inserir a chave que falta: pare e reporte, conforme a regra 2.
- `AclService` caches permissions per profile in Redis (`acl:perfil:{cd_perfil}`, TTL 24h). After granting a permission in the database, **invalidate those keys** or the change only takes effect a day later.

## Sparse fieldsets (`?fields=`)

`GET /pessoas` and `GET /pessoas/{id}` accept `fields` (comma-separated, dot for relations, `fisica.*` and `*` wildcards). The selection reaches the SQL: partial `SELECT` plus conditional eager load.

- **`App\Resource\Pessoa\MapaDeCamposPessoa` is the single source of truth** for what the API exposes. A column absent from the map is unreachable — that is why there is no blacklist and why `ds_senha` can never be selected.
- Defaults differ **on purpose**: the list returns a lean set, the item returns everything, writes ignore `fields`. Documented in the Swagger of both read endpoints.
- Adding a field takes **two** edits: the map, and the `#[OA\Parameter(name: 'fields')]` description in both read endpoints. PHP attributes require constant expressions, so the Swagger list cannot be derived from the map.
- The `select` inside an eager load **must** carry the foreign key. Without it Eloquent cannot match child to parent and returns the relation as `null` with no error. `SelecaoDeCampos::relacoes()` injects it; `PessoaRepositoryTest::testEagerLoadParcialTrazAFkEPortantoCasaPaiEFilho` guards it.
- `PessoaResource` checks `relationLoaded()` before touching a relation. Touching an unloaded one triggers lazy load — one query per row (N+1).

## Testing

- **`cd_cliente = 1` and `cd_perfil = 1` do not exist** in this database (`saas_cliente` starts at 20, `lgin_perfil` at 79). Use `HyperfTest\Support\TenantDeTeste`, which creates a disposable tenant and cleans it up in `test/bootstrap.php` before and after the run.
- A FK violation surfaces as SQLSTATE **23000**, which `DatabaseExceptionHandler` maps to **409** — the same status as "login already exists". A 409 you did not expect is probably a bad FK, not a duplicate. Check `runtime/logs/hyperf.log`.
- The suite reports many **risky** tests (`did not remove its own error handlers`). That is the Hyperf harness — `ErrorExceptionHandler` registers a handler at boot and never removes it. Not a regression; only errors and failures count.
- Assertions must prove the **value**, not the shape, wherever the failure mode is silent. `relationLoaded() === true` stays true even when the relation failed to match; only `assertNotNull($pessoa->fisica)` catches that.

## Code style

PHP-CS-Fixer (`.php-cs-fixer.php`) enforces `@PSR2` + `@Symfony` + `@DoctrineAnnotation` + `@PhpCsFixer`, short array/list syntax, and a mandatory Hyperf license PHPDoc header on every file. **Never hand-write the header — run `cs-fix`** and let it insert it.

One trap worth knowing: cs-fixer rewrites a standalone `/** @var X $y */` into `/* @var X $y */`, and PHPStan **ignores that form**, so the type is never narrowed. Use a real `instanceof` guard instead of a `@var` annotation.

## `storage/languages` is production code

Validation messages live in `storage/languages/en/validation.php` and are **versioned**. The `.gitignore` there used to exclude `*.php`, which meant a clean checkout had no messages at all and every 422 answered with the raw key (`validation.required`) instead of a sentence. `'locale' => 'en'` in `config/autoload/translation.php` picks the language; it does not create the messages. Publish with `php bin/hyperf.php vendor:publish hyperf/validation` and commit the result.
