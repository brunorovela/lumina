# Migração da API de Cadastro de Pessoa — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrar a primeira API do LMS legado (cadastro de pessoa) pro lumina (Hyperf/Swoole), com auth por token opaco + Redis, ACL completo, CRUD com soft-delete, paginação/filtro, documentação OpenAPI, servindo de padrão pras próximas migrações.

**Architecture:** Camadas Controller → Service → Repository → Model (Eloquent/`hyperf/database`), com Model sempre escondido atrás de `PessoaRepositoryInterface`. Auth e ACL via middleware PSR-15 em pipeline, identidade propagada pelo contexto de corrotina (`Hyperf\Context\Context`). Mesmo banco/tabelas do legado.

**Tech Stack:** PHP 8.4, Hyperf 3.2 (Swoole), `hyperf/database` + `hyperf/db-connection` (Eloquent-style ORM), `hyperf/redis`, `hyperf/validation`, `hyperf/swagger`, MySQL (schema compartilhado com o legado), Redis (sessão + cache ACL), PHPUnit via `co-phpunit`.

Spec completo: `docs/superpowers/specs/2026-07-25-migracao-cadastro-pessoa-design.md`.

## Global Constraints

- PHP `>=8.4`, `declare(strict_types=1)` em todo arquivo novo.
- Todo arquivo PHP novo precisa do cabeçalho de licença Hyperf — **não escreva à mão**, rode `composer cs-fix` ao final de cada task pra inserir automaticamente.
- `composer test` (= `cs-check` + `php-unit` + `analyse`) precisa passar do zero ao final do plano inteiro — rode a cada task pra pegar regressão cedo, não só no final.
- Testes rodam via `composer php-unit` (= `co-phpunit --prepend test/bootstrap.php`). Base de teste: `Hyperf\Testing\TestCase` (não a `HyperfTest\HttpTestCase` customizada — é a que `test/Cases/ExampleTest.php` já usa e comprovadamente funciona).
- Nenhum Model Eloquent pode ser usado fora de `app/Repository/**` — Controller/Service só conhecem interfaces de Repository.
- `cd_cliente` do usuário autenticado nunca vem de payload/query — sempre do contexto da corrotina (`App\Support\IdentidadeContext`, criado na Task 8).
- Mesmo banco/tabelas do legado (`unim_pessoa`, `unim_pessoa_fisica`, `unim_pessoa_juridica`, `lgin_perfil`, `ulms_recurso`, `ulms_privilegio`, `ulms_recurso_privilegio`, `lgin_perfil_recurso_privilegio`, `lgin_pessoa_perfil`, `unim_coligada`) — não criar tabela nova exceto a coluna `dt_excluido` em `unim_pessoa` (Task 4).
- **FK reais são aplicadas pelo MySQL** (InnoDB, não é decoração) — confirmado empiricamente antes de escrever este plano. `unim_pessoa.cd_cliente` exige `saas_cliente.cd_cliente` existente; `unim_coligada.cd_idioma` exige `saas_idioma.cd_idioma` existente. Fixtures já criadas no banco de dev (`lms2`) pra desbloquear os testes deste plano: **`saas_cliente.cd_cliente = 1`** (`ds_cliente = 'Cliente Fixture Testes'`) e o **`saas_idioma.cd_idioma = 27`** já existente no seed real (único idioma cadastrado). Todo teste que insere `unim_pessoa`/`unim_coligada` usa esses dois valores — não inventar outro `cd_cliente`/`cd_idioma` sem confirmar que existe.
- **Bookkeeping de migration isolado do legado**: o schema `lms2` já tinha uma tabela `migrations` própria do Doctrine Migrations do LMS legado (colunas `version`/`executed_at`/`execution_time`), incompatível com o formato que `hyperf/database` espera (`migration`/`batch`). Achado durante a Task 4, resolvido configurando `'migrations' => 'hyperf_migrations'` em `config/autoload/databases.php` (chave real, lida por `Hyperf\DbConnection\DatabaseMigrationRepositoryFactory`) — tabela nova e isolada, sem tocar na `migrations` legada. Confirmado no banco: `migrations` (Doctrine, 29 linhas) intacta, `hyperf_migrations` criada separada. Toda migration futura deste plano usa esse bookkeeping isolado — não reverter essa config.
- Pessoas reais de seed (`cd_pessoa` 1 e 2, logins `admin`/`administrador`, `cd_cliente=23`) já existem no banco de dev — não apagar, não usar `cd_cliente=23` nos testes novos (fica reservado pra esses dados de seed; testes usam o fixture `cd_cliente=1`).

---

## Task 1: Instalar dependências e remover o teste órfão

Corrige o estado quebrado documentado no spec: pacotes ausentes (`hyperf/database`, `hyperf/db-connection`, `hyperf/redis`, `hyperf/validation`, `hyperf/swagger`) e o teste que referencia classes deletadas.

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock` (gerado pelo `composer update`/`require`, não editar à mão)
- Delete: `test/Cases/AclRouteOptionsTest.php`

**Interfaces:**
- Produces: pacotes disponíveis pro resto do plano — `Hyperf\DbConnection\Model\Model`, `Hyperf\Redis\Redis` (via `Hyperf\Redis\RedisFactory`/proxy `Hyperf\Redis\Redis`), `Hyperf\Validation\Request\FormRequest`, `Hyperf\Swagger\Annotation\*`.

- [ ] **Step 1: Remover o teste órfão**

```bash
git rm test/Cases/AclRouteOptionsTest.php
```

- [ ] **Step 2: Instalar os pacotes faltantes**

Rodar dentro do container (ou ambiente com PHP 8.4 + composer):

```bash
composer require hyperf/database hyperf/db-connection hyperf/redis hyperf/validation hyperf/swagger
```

- [ ] **Step 3: Confirmar que `composer test` roda (mesmo que falhe em outras partes ainda não implementadas)**

Run: `composer cs-check && composer analyse`
Expected: sem erro de classe/pacote faltando (avisos de estilo/análise em código ainda não escrito são esperados nesta altura — o que importa é não ter erro de dependência ausente).

- [ ] **Step 4: Publicar configs dos pacotes novos e conferir o que foi gerado**

```bash
php bin/hyperf.php vendor:publish hyperf/validation
php bin/hyperf.php vendor:publish hyperf/redis
```

Anotar (pra usar nas próximas tasks): qual arquivo de config cada um publicou em `config/autoload/` (redis provavelmente já existe e não muda; validation deve publicar `config/autoload/validation.php` ou similar — confirmar o nome real gerado antes da Task 12).

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: instala hyperf/database, db-connection, redis, validation e swagger"
```

---

## Task 2: `App\Support\ApiResponse` — envelope padrão de resposta

**Files:**
- Create: `app/Support/ApiResponse.php`
- Test: `test/Cases/Support/ApiResponseTest.php`

**Interfaces:**
- Produces: `App\Support\ApiResponse::sucesso(mixed $data, ?array $meta = null): array`, `App\Support\ApiResponse::erro(string $message, ?array $errors = null): array`

- [ ] **Step 1: Escrever o teste**

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Support;

use App\Support\ApiResponse;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ApiResponseTest extends TestCase
{
    public function testSucessoSemMeta()
    {
        $resultado = ApiResponse::sucesso(['id' => 1]);

        $this->assertSame(['success' => true, 'data' => ['id' => 1]], $resultado);
    }

    public function testSucessoComMeta()
    {
        $resultado = ApiResponse::sucesso([1, 2], ['total' => 2]);

        $this->assertSame(
            ['success' => true, 'data' => [1, 2], 'meta' => ['total' => 2]],
            $resultado
        );
    }

    public function testErroSemErrors()
    {
        $resultado = ApiResponse::erro('Pessoa não encontrada.');

        $this->assertSame(
            ['success' => false, 'message' => 'Pessoa não encontrada.'],
            $resultado
        );
    }

    public function testErroComErrors()
    {
        $resultado = ApiResponse::erro('Validação falhou.', ['ds_nome' => ['obrigatório']]);

        $this->assertSame(
            ['success' => false, 'message' => 'Validação falhou.', 'errors' => ['ds_nome' => ['obrigatório']]],
            $resultado
        );
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `composer php-unit -- --filter ApiResponseTest`
Expected: FAIL — `App\Support\ApiResponse` não existe.

- [ ] **Step 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace App\Support;

class ApiResponse
{
    public static function sucesso(mixed $data, ?array $meta = null): array
    {
        $resposta = ['success' => true, 'data' => $data];

        if ($meta !== null) {
            $resposta['meta'] = $meta;
        }

        return $resposta;
    }

    public static function erro(string $message, ?array $errors = null): array
    {
        $resposta = ['success' => false, 'message' => $message];

        if ($errors !== null) {
            $resposta['errors'] = $errors;
        }

        return $resposta;
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `composer php-unit -- --filter ApiResponseTest`
Expected: PASS (4 testes)

- [ ] **Step 5: Commit**

```bash
git add app/Support/ApiResponse.php test/Cases/Support/ApiResponseTest.php
git commit -m "feat: adiciona ApiResponse (envelope padrao de resposta)"
```

---

## Task 3: Exceções de domínio e Exception Handlers

Corrige `config/autoload/exceptions.php`, que já referencia classes inexistentes.

**Files:**
- Create: `app/Exception/HttpAwareException.php`
- Create: `app/Exception/Pessoa/PessoaNaoEncontradaException.php`
- Create: `app/Exception/Pessoa/LoginJaExisteException.php`
- Create: `app/Exception/Handler/AppExceptionHandler.php`
- Create: `app/Exception/Handler/ValidationExceptionHandler.php`
- Create: `app/Exception/Handler/DatabaseExceptionHandler.php`
- Test: `test/Cases/Exception/ExceptionHandlerTest.php`

**Interfaces:**
- Consumes: `App\Support\ApiResponse::erro()` (Task 2)
- Produces: `App\Exception\HttpAwareException` (base pras exceções de domínio, com `getStatusCode(): int`), `App\Exception\Pessoa\PessoaNaoEncontradaException` (404), `App\Exception\Pessoa\LoginJaExisteException` (409) — usadas pelo `PessoaService` na Task 13.

- [ ] **Step 1: Escrever a exceção base e as de domínio**

```php
<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

abstract class HttpAwareException extends RuntimeException
{
    abstract public function getStatusCode(): int;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Exception\Pessoa;

use App\Exception\HttpAwareException;

class PessoaNaoEncontradaException extends HttpAwareException
{
    public function __construct()
    {
        parent::__construct('Pessoa não encontrada.');
    }

    public function getStatusCode(): int
    {
        return 404;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Exception\Pessoa;

use App\Exception\HttpAwareException;

class LoginJaExisteException extends HttpAwareException
{
    public function __construct()
    {
        parent::__construct('Já existe uma pessoa com esse login para este cliente.');
    }

    public function getStatusCode(): int
    {
        return 409;
    }
}
```

- [ ] **Step 2: Escrever o teste dos handlers**

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Exception;

use App\Exception\Handler\AppExceptionHandler;
use App\Exception\Pessoa\PessoaNaoEncontradaException;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Testing\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * @internal
 * @coversNothing
 */
class ExceptionHandlerTest extends TestCase
{
    public function testAppExceptionHandlerFormataExcecaoDeDominio()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $handler = new AppExceptionHandler($logger);

        /** @var ResponseInterface $response */
        $response = $this->getContainer()->get(ResponseInterface::class)->withBody(new SwooleStream(''));

        $resultado = $handler->handle(new PessoaNaoEncontradaException(), $response);

        $this->assertSame(404, $resultado->getStatusCode());

        $corpo = json_decode((string) $resultado->getBody(), true);
        $this->assertSame(false, $corpo['success']);
        $this->assertSame('Pessoa não encontrada.', $corpo['message']);
    }
}
```

- [ ] **Step 3: Rodar e confirmar que falha**

Run: `composer php-unit -- --filter ExceptionHandlerTest`
Expected: FAIL — `App\Exception\Handler\AppExceptionHandler` não existe.

- [ ] **Step 4: Implementar os handlers**

```php
<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use App\Exception\HttpAwareException;
use App\Support\ApiResponse;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class AppExceptionHandler extends ExceptionHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        if ($throwable instanceof HttpAwareException) {
            return $response
                ->withStatus($throwable->getStatusCode())
                ->withBody(new SwooleStream(json_encode(ApiResponse::erro($throwable->getMessage()))));
        }

        $this->logger->error($throwable->getMessage(), ['exception' => $throwable]);

        return $response
            ->withStatus(500)
            ->withBody(new SwooleStream(json_encode(
                ApiResponse::erro('Erro interno. Tente novamente em instantes.')
            )));
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use App\Support\ApiResponse;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class ValidationExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        /** @var ValidationException $throwable */
        return $response
            ->withStatus(422)
            ->withBody(new SwooleStream(json_encode(
                ApiResponse::erro('Dados inválidos.', $throwable->validator->errors()->toArray())
            )));
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof ValidationException;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Exception\Handler;

use App\Support\ApiResponse;
use Hyperf\Database\QueryException;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class DatabaseExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        /** @var QueryException $throwable */
        $codigoSqlDuplicado = '23000';
        $status = str_contains($throwable->getCode(), $codigoSqlDuplicado) ? 409 : 400;

        return $response
            ->withStatus($status)
            ->withBody(new SwooleStream(json_encode(
                ApiResponse::erro('Não foi possível concluir a operação no banco de dados.')
            )));
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof QueryException;
    }
}
```

- [ ] **Step 5: Rodar e confirmar que passa**

Run: `composer php-unit -- --filter ExceptionHandlerTest`
Expected: PASS

- [ ] **Step 6: Atualizar `config/autoload/exceptions.php` (ordem importa — mais específico primeiro)**

```php
<?php

declare(strict_types=1);

use App\Exception\Handler\AppExceptionHandler;
use App\Exception\Handler\DatabaseExceptionHandler;
use App\Exception\Handler\ValidationExceptionHandler;

return [
    'handler' => [
        'http' => [
            ValidationExceptionHandler::class,
            DatabaseExceptionHandler::class,
            AppExceptionHandler::class,
        ],
    ],
];
```

- [ ] **Step 7: Rodar `composer test` completo e confirmar que não quebrou nada**

Run: `composer test`
Expected: PASS (cs-check, php-unit, analyse todos passando)

- [ ] **Step 8: Commit**

```bash
git add app/Exception config/autoload/exceptions.php test/Cases/Exception
git commit -m "feat: adiciona exception handlers e excecoes de dominio de pessoa"
```

---

## Task 4: Migration — coluna `dt_excluido` em `unim_pessoa`

**Files:**
- Create: migration gerada pelo `gen:migration` (path a confirmar no Step 1 — convenção padrão do `hyperf/database` é `migrations/`, mas ainda não existe essa pasta no lumina)

**Interfaces:**
- Produces: coluna `unim_pessoa.dt_excluido` (datetime, nullable) — consumida pelo Model `UnimPessoa` (Task 5, trait `SoftDeletes`).

- [ ] **Step 1: Descobrir onde o comando cria o arquivo**

```bash
php bin/hyperf.php gen:migration adiciona_dt_excluido_em_unim_pessoa
```

Anotar o path exato impresso pelo comando (esperado: `migrations/<timestamp>_adiciona_dt_excluido_em_unim_pessoa.php`).

- [ ] **Step 2: Escrever a migration**

```php
<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class AdicionaDtExcluidoEmUnimPessoa extends Migration
{
    public function up(): void
    {
        Schema::table('unim_pessoa', function (Blueprint $table) {
            $table->dateTime('dt_excluido')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('unim_pessoa', function (Blueprint $table) {
            $table->dropColumn('dt_excluido');
        });
    }
}
```

(Ajustar o nome da classe pro nome real gerado pelo comando no Step 1, se vier diferente.)

- [ ] **Step 3: Rodar a migration num banco de teste/dev e confirmar a coluna**

```bash
php bin/hyperf.php migrate
```

Run: `SHOW COLUMNS FROM unim_pessoa LIKE 'dt_excluido';` (via `mysql` client ou `php bin/hyperf.php db:table unim_pessoa` se disponível)
Expected: coluna `dt_excluido`, tipo `datetime`, `NULL` permitido.

- [ ] **Step 4: Commit**

```bash
git add migrations/
git commit -m "feat: adiciona coluna dt_excluido em unim_pessoa (soft-delete)"
```

---

## Task 5: Models Eloquent — UnimPessoa, UnimPessoaFisica, UnimPessoaJuridica

**Files:**
- Create: `app/Model/Pessoa/UnimPessoa.php`
- Create: `app/Model/Pessoa/UnimPessoaFisica.php`
- Create: `app/Model/Pessoa/UnimPessoaJuridica.php`
- Test: `test/Cases/Model/Pessoa/UnimPessoaTest.php`

**Interfaces:**
- Consumes: `App\Model\Model` (base já existente em `app/Model/Model.php`, agora com import válido porque `hyperf/db-connection` foi instalado na Task 1)
- Produces: `App\Model\Pessoa\UnimPessoa` (com `SoftDeletes`, relações `fisica()`/`juridica()`), `App\Model\Pessoa\UnimPessoaFisica`, `App\Model\Pessoa\UnimPessoaJuridica` — consumidos pelo `PessoaRepository` (Tasks 6 e 7).

- [ ] **Step 1: Escrever o teste (usa o banco de teste real — sem mock, ver Global Constraints do spec)**

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Model\Pessoa;

use App\Model\Pessoa\UnimPessoa;
use Hyperf\DbConnection\Db;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class UnimPessoaTest extends TestCase
{
    protected function tearDown(): void
    {
        Db::table('unim_pessoa')->where('ds_login', 'teste.model.unimpessoa')->delete();
        parent::tearDown();
    }

    public function testSoftDeleteEsconqueLinhaSemApagar()
    {
        $pessoa = UnimPessoa::create([
            'cd_cliente' => 1,
            'ds_nome' => 'Pessoa Teste Model',
            'ds_login' => 'teste.model.unimpessoa',
            'ds_senha' => password_hash('123456', PASSWORD_BCRYPT),
            'sn_pessoa_juridica' => false,
        ]);

        $pessoa->delete();

        $this->assertNull(UnimPessoa::find($pessoa->cd_pessoa));
        $this->assertNotNull(UnimPessoa::withTrashed()->find($pessoa->cd_pessoa));

        $linhaCrua = Db::table('unim_pessoa')->where('cd_pessoa', $pessoa->cd_pessoa)->first();
        $this->assertNotNull($linhaCrua->dt_excluido);
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `composer php-unit -- --filter UnimPessoaTest`
Expected: FAIL — `App\Model\Pessoa\UnimPessoa` não existe.

- [ ] **Step 3: Implementar os Models**

```php
<?php

declare(strict_types=1);

namespace App\Model\Pessoa;

use App\Model\Model;
use Hyperf\Database\Model\Relations\HasOne;
use Hyperf\DbConnection\Model\SoftDeletes;

class UnimPessoa extends Model
{
    use SoftDeletes;

    public const DELETED_AT = 'dt_excluido';

    protected ?string $table = 'unim_pessoa';

    protected string $primaryKey = 'cd_pessoa';

    public bool $timestamps = false;

    protected array $fillable = [
        'cd_cliente',
        'cd_imagem',
        'ds_nome',
        'ds_login',
        'ds_senha',
        'sn_pessoa_juridica',
        'me_qualificacao',
        'ds_seguimento',
        'ds_marca',
        'ds_unidade',
        'ds_turma',
        'dt_cadastro',
        'dt_base',
    ];

    protected array $hidden = ['ds_senha'];

    protected array $casts = [
        'sn_pessoa_juridica' => 'boolean',
        'dt_cadastro' => 'datetime',
        'dt_base' => 'datetime',
        'dt_excluido' => 'datetime',
    ];

    public function fisica(): HasOne
    {
        return $this->hasOne(UnimPessoaFisica::class, 'cd_pessoa', 'cd_pessoa');
    }

    public function juridica(): HasOne
    {
        return $this->hasOne(UnimPessoaJuridica::class, 'cd_pessoa', 'cd_pessoa');
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Model\Pessoa;

use App\Model\Model;

class UnimPessoaFisica extends Model
{
    protected ?string $table = 'unim_pessoa_fisica';

    protected string $primaryKey = 'cd_pessoa';

    public bool $incrementing = false;

    public bool $timestamps = false;

    protected array $fillable = [
        'cd_pessoa',
        'ds_nome_oficial',
        'ds_nome_social',
        'ds_nome_mae',
        'ds_nome_pai',
        'ds_identidade',
        'ds_orgao_estado',
        'ds_identidade_orgao_exp',
        'dt_identidade_expedicao',
        'dt_nascimento',
        'ds_cpf',
        'ds_sexo',
        'cd_estado_civil',
    ];

    protected array $casts = [
        'dt_identidade_expedicao' => 'date',
        'dt_nascimento' => 'date',
    ];
}
```

```php
<?php

declare(strict_types=1);

namespace App\Model\Pessoa;

use App\Model\Model;

class UnimPessoaJuridica extends Model
{
    protected ?string $table = 'unim_pessoa_juridica';

    protected string $primaryKey = 'cd_pessoa';

    public bool $incrementing = false;

    public bool $timestamps = false;

    protected array $fillable = [
        'cd_pessoa',
        'ds_cnpj',
        'ds_nome_fantasia',
    ];

    protected array $casts = [
        'sn_excluido' => 'boolean',
        'dt_excluido' => 'datetime',
    ];

    protected array $attributes = [
        'sn_excluido' => false,
    ];
}
```

> Nota: `unim_pessoa_juridica` já tinha soft-delete próprio (`dt_excluido`, achado no brainstorming) antes desta migração. O Model acima **não** usa a trait `SoftDeletes` nela — o Repository (Task 6) filtra `whereNull('dt_excluido')` manualmente ao ler, e nunca escreve em `dt_excluido`/`sn_excluido` além do default `false` na criação (mesmo comportamento do legado, `UnimPessoaJuridicaRepository.php:59`).

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `composer php-unit -- --filter UnimPessoaTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Model/Pessoa test/Cases/Model/Pessoa
git commit -m "feat: adiciona models UnimPessoa, UnimPessoaFisica e UnimPessoaJuridica"
```

---

## Task 6: `PessoaRepositoryInterface` + `PessoaRepository` — criar e buscar

**Files:**
- Create: `app/Repository/Pessoa/PessoaRepositoryInterface.php`
- Create: `app/Repository/Pessoa/PessoaRepository.php`
- Modify: `config/autoload/dependencies.php` (bind da interface)
- Test: `test/Cases/Repository/Pessoa/PessoaRepositoryTest.php`

**Interfaces:**
- Consumes: `App\Model\Pessoa\{UnimPessoa,UnimPessoaFisica,UnimPessoaJuridica}` (Task 5)
- Produces:
```php
interface PessoaRepositoryInterface
{
    public function criar(array $dadosPessoa, ?array $dadosFisica, ?array $dadosJuridica): UnimPessoa;
    public function buscarPorId(int $cdPessoa, int $cdCliente): ?UnimPessoa;
    public function loginExiste(int $cdCliente, string $dsLogin, ?int $ignorarCdPessoa = null): bool;
}
```
(métodos `atualizar`, `listar`, `excluir` chegam na Task 7 — interface é ampliada lá.)

- [ ] **Step 1: Escrever o teste**

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Repository\Pessoa;

use App\Repository\Pessoa\PessoaRepositoryInterface;
use Hyperf\DbConnection\Db;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class PessoaRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Db::table('unim_pessoa')->where('ds_login', 'like', 'teste.repo.%')->delete();
        parent::tearDown();
    }

    public function testCriarPessoaFisicaSalvaNucleoEFisicaNaMesmaTransacao()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $pessoa = $repository->criar(
            [
                'cd_cliente' => 1,
                'ds_nome' => 'Fulano de Teste',
                'ds_login' => 'teste.repo.fisica',
                'ds_senha' => password_hash('123456', PASSWORD_BCRYPT),
                'sn_pessoa_juridica' => false,
            ],
            ['ds_nome_oficial' => 'Fulano de Teste Oficial'],
            null
        );

        $this->assertNotNull($pessoa->cd_pessoa);
        $this->assertSame('Fulano de Teste Oficial', $pessoa->fisica->ds_nome_oficial);
    }

    public function testLoginExisteDetectaDuplicataPorCliente()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $repository->criar(
            [
                'cd_cliente' => 1,
                'ds_nome' => 'Ciclano de Teste',
                'ds_login' => 'teste.repo.duplicado',
                'ds_senha' => password_hash('123456', PASSWORD_BCRYPT),
                'sn_pessoa_juridica' => false,
            ],
            ['ds_nome_oficial' => 'Ciclano'],
            null
        );

        $this->assertTrue($repository->loginExiste(1, 'teste.repo.duplicado'));
        $this->assertFalse($repository->loginExiste(2, 'teste.repo.duplicado'));
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `composer php-unit -- --filter PessoaRepositoryTest`
Expected: FAIL — binding não existe / classe não existe.

- [ ] **Step 3: Implementar a interface e o Repository (parcial — criar/buscar/loginExiste)**

```php
<?php

declare(strict_types=1);

namespace App\Repository\Pessoa;

use App\Model\Pessoa\UnimPessoa;

interface PessoaRepositoryInterface
{
    public function criar(array $dadosPessoa, ?array $dadosFisica, ?array $dadosJuridica): UnimPessoa;

    public function buscarPorId(int $cdPessoa, int $cdCliente): ?UnimPessoa;

    public function loginExiste(int $cdCliente, string $dsLogin, ?int $ignorarCdPessoa = null): bool;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Repository\Pessoa;

use App\Model\Pessoa\UnimPessoa;
use App\Model\Pessoa\UnimPessoaFisica;
use App\Model\Pessoa\UnimPessoaJuridica;
use Hyperf\DbConnection\Db;

class PessoaRepository implements PessoaRepositoryInterface
{
    public function criar(array $dadosPessoa, ?array $dadosFisica, ?array $dadosJuridica): UnimPessoa
    {
        return Db::transaction(function () use ($dadosPessoa, $dadosFisica, $dadosJuridica) {
            $pessoa = UnimPessoa::create($dadosPessoa);

            if ($dadosFisica !== null) {
                UnimPessoaFisica::create(['cd_pessoa' => $pessoa->cd_pessoa, ...$dadosFisica]);
            }

            if ($dadosJuridica !== null) {
                UnimPessoaJuridica::create(['cd_pessoa' => $pessoa->cd_pessoa, ...$dadosJuridica]);
            }

            return $pessoa->fresh(['fisica', 'juridica']);
        });
    }

    public function buscarPorId(int $cdPessoa, int $cdCliente): ?UnimPessoa
    {
        return UnimPessoa::with(['fisica', 'juridica'])
            ->where('cd_pessoa', $cdPessoa)
            ->where('cd_cliente', $cdCliente)
            ->first();
    }

    public function loginExiste(int $cdCliente, string $dsLogin, ?int $ignorarCdPessoa = null): bool
    {
        $query = UnimPessoa::where('cd_cliente', $cdCliente)->where('ds_login', $dsLogin);

        if ($ignorarCdPessoa !== null) {
            $query->where('cd_pessoa', '!=', $ignorarCdPessoa);
        }

        return $query->exists();
    }
}
```

- [ ] **Step 4: Registrar o bind em `config/autoload/dependencies.php`**

```php
<?php

declare(strict_types=1);

use App\Repository\Pessoa\PessoaRepository;
use App\Repository\Pessoa\PessoaRepositoryInterface;

return [
    PessoaRepositoryInterface::class => PessoaRepository::class,
];
```

- [ ] **Step 5: Rodar e confirmar que passa**

Run: `composer php-unit -- --filter PessoaRepositoryTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Repository/Pessoa config/autoload/dependencies.php test/Cases/Repository/Pessoa
git commit -m "feat: adiciona PessoaRepository (criar, buscar, loginExiste)"
```

---

## Task 7: `PessoaRepository` — atualizar, listar, excluir (soft-delete)

**Files:**
- Modify: `app/Repository/Pessoa/PessoaRepositoryInterface.php`
- Modify: `app/Repository/Pessoa/PessoaRepository.php`
- Test: `test/Cases/Repository/Pessoa/PessoaRepositoryTest.php` (adiciona casos)

**Interfaces:**
- Produces (métodos adicionados à interface):
```php
public function atualizar(int $cdPessoa, int $cdCliente, array $dadosPessoa, ?array $dadosFisica, ?array $dadosJuridica): UnimPessoa;
public function listar(int $cdCliente, array $filtros, int $page, int $perPage): array; // ['itens' => Collection, 'total' => int]
public function excluir(int $cdPessoa, int $cdCliente): bool;
```
`$filtros` aceita chaves opcionais `nome` (string, LIKE parcial em `ds_nome`) e `tipo_pessoa` (`'fisica'|'juridica'`, mapeado pra `sn_pessoa_juridica`).

- [ ] **Step 1: Adicionar os testes**

```php
    public function testAtualizarMantemSenhaAtualQuandoNaoInformada()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $pessoa = $repository->criar(
            [
                'cd_cliente' => 1,
                'ds_nome' => 'Atualiza Teste',
                'ds_login' => 'teste.repo.atualiza',
                'ds_senha' => 'hash-original',
                'sn_pessoa_juridica' => false,
            ],
            ['ds_nome_oficial' => 'Atualiza Teste Oficial'],
            null
        );

        $atualizada = $repository->atualizar(
            $pessoa->cd_pessoa,
            1,
            ['ds_nome' => 'Atualiza Teste Renomeado'],
            null,
            null
        );

        $this->assertSame('Atualiza Teste Renomeado', $atualizada->ds_nome);
        $this->assertSame('hash-original', $atualizada->ds_senha);
    }

    public function testListarFiltraPorNomeETipoPessoaEPaginaCertoDentroDoCliente()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $repository->criar(
            ['cd_cliente' => 1, 'ds_nome' => 'Maria Fisica Teste', 'ds_login' => 'teste.repo.listar1', 'ds_senha' => 'x', 'sn_pessoa_juridica' => false],
            ['ds_nome_oficial' => 'Maria Fisica Teste'],
            null
        );
        $repository->criar(
            ['cd_cliente' => 1, 'ds_nome' => 'Empresa Juridica Teste', 'ds_login' => 'teste.repo.listar2', 'ds_senha' => 'x', 'sn_pessoa_juridica' => true],
            null,
            ['ds_cnpj' => '00000000000191', 'ds_nome_fantasia' => 'Empresa Juridica Teste']
        );

        $resultado = $repository->listar(1, ['nome' => 'Teste', 'tipo_pessoa' => 'fisica'], 1, 20);

        $this->assertSame(1, $resultado['total']);
        $this->assertSame('Maria Fisica Teste', $resultado['itens']->first()->ds_nome);
    }

    public function testExcluirEhSoftDeleteNaoRemoveLinha()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $pessoa = $repository->criar(
            ['cd_cliente' => 1, 'ds_nome' => 'Exclui Teste', 'ds_login' => 'teste.repo.excluir', 'ds_senha' => 'x', 'sn_pessoa_juridica' => false],
            ['ds_nome_oficial' => 'Exclui Teste'],
            null
        );

        $this->assertTrue($repository->excluir($pessoa->cd_pessoa, 1));
        $this->assertNull($repository->buscarPorId($pessoa->cd_pessoa, 1));

        $linhaCrua = Db::table('unim_pessoa')->where('cd_pessoa', $pessoa->cd_pessoa)->first();
        $this->assertNotNull($linhaCrua);
        $this->assertNotNull($linhaCrua->dt_excluido);
    }
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `composer php-unit -- --filter PessoaRepositoryTest`
Expected: FAIL — métodos `atualizar`/`listar`/`excluir` não existem.

- [ ] **Step 3: Ampliar a interface**

```php
<?php

declare(strict_types=1);

namespace App\Repository\Pessoa;

use App\Model\Pessoa\UnimPessoa;
use Hyperf\Database\Model\Collection;

interface PessoaRepositoryInterface
{
    public function criar(array $dadosPessoa, ?array $dadosFisica, ?array $dadosJuridica): UnimPessoa;

    public function atualizar(
        int $cdPessoa,
        int $cdCliente,
        array $dadosPessoa,
        ?array $dadosFisica,
        ?array $dadosJuridica
    ): UnimPessoa;

    public function buscarPorId(int $cdPessoa, int $cdCliente): ?UnimPessoa;

    /** @return array{itens: Collection, total: int} */
    public function listar(int $cdCliente, array $filtros, int $page, int $perPage): array;

    public function excluir(int $cdPessoa, int $cdCliente): bool;

    public function loginExiste(int $cdCliente, string $dsLogin, ?int $ignorarCdPessoa = null): bool;
}
```

- [ ] **Step 4: Implementar no Repository**

```php
    public function atualizar(
        int $cdPessoa,
        int $cdCliente,
        array $dadosPessoa,
        ?array $dadosFisica,
        ?array $dadosJuridica
    ): UnimPessoa {
        return Db::transaction(function () use ($cdPessoa, $cdCliente, $dadosPessoa, $dadosFisica, $dadosJuridica) {
            $pessoa = UnimPessoa::where('cd_pessoa', $cdPessoa)->where('cd_cliente', $cdCliente)->firstOrFail();
            $pessoa->update($dadosPessoa);

            if ($dadosFisica !== null) {
                UnimPessoaFisica::updateOrCreate(['cd_pessoa' => $cdPessoa], $dadosFisica);
            }

            if ($dadosJuridica !== null) {
                UnimPessoaJuridica::updateOrCreate(['cd_pessoa' => $cdPessoa], $dadosJuridica);
            }

            return $pessoa->fresh(['fisica', 'juridica']);
        });
    }

    public function listar(int $cdCliente, array $filtros, int $page, int $perPage): array
    {
        $query = UnimPessoa::with(['fisica', 'juridica'])->where('cd_cliente', $cdCliente);

        if (! empty($filtros['nome'])) {
            $query->where('ds_nome', 'like', '%' . $filtros['nome'] . '%');
        }

        if (! empty($filtros['tipo_pessoa'])) {
            $query->where('sn_pessoa_juridica', $filtros['tipo_pessoa'] === 'juridica');
        }

        $total = (clone $query)->count();
        $itens = $query->forPage($page, $perPage)->get();

        return ['itens' => $itens, 'total' => $total];
    }

    public function excluir(int $cdPessoa, int $cdCliente): bool
    {
        $pessoa = UnimPessoa::where('cd_pessoa', $cdPessoa)->where('cd_cliente', $cdCliente)->first();

        if ($pessoa === null) {
            return false;
        }

        return (bool) $pessoa->delete();
    }
```

- [ ] **Step 5: Rodar e confirmar que passa**

Run: `composer php-unit -- --filter PessoaRepositoryTest`
Expected: PASS (todos os casos)

- [ ] **Step 6: Commit**

```bash
git add app/Repository/Pessoa test/Cases/Repository/Pessoa
git commit -m "feat: adiciona atualizar, listar e excluir (soft-delete) no PessoaRepository"
```

---

## Task 8: `IdentidadeContext` + Redis de sessão + `AuthService` (cascata de senha)

**Files:**
- Create: `app/Support/IdentidadeContext.php`
- Create: `app/Service/Auth/AuthService.php`
- Test: `test/Cases/Service/Auth/AuthServiceTest.php`

**Interfaces:**
- Consumes: `App\Repository\Pessoa\PessoaRepositoryInterface::loginExiste` não usado aqui — usa `Hyperf\DbConnection\Db` direto pra achar a pessoa por login (não é operação de escrita de domínio, é leitura pontual de autenticação).
- Produces:
```php
final class IdentidadeContext
{
    public static function set(array $identidade): void; // ['cd_pessoa'=>int,'cd_cliente'=>int,'cd_perfis'=>int[]]
    public static function get(): ?array;
    public static function cdCliente(): int;
    public static function cdPerfis(): array; // lista de cd_perfil — pessoa pode ter varios simultaneos (dado real confirmado)
    public static function cdPessoa(): int;
}

class AuthService
{
    public function autenticar(int $cdCliente, string $dsLogin, string $dsSenha): string; // retorna token
    public function logout(string $token): void;
    public function identidadePorToken(string $token): ?array;
}
```
Consumido por `AuthMiddleware` (Task 9) e `AuthController` (Task 9).

- [ ] **Step 1: Escrever `IdentidadeContext` (sem teste dedicado — é um wrapper fino sobre `Hyperf\Context\Context`, coberto indiretamente pelos testes de middleware na Task 9)**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use Hyperf\Context\Context;

final class IdentidadeContext
{
    private const CHAVE = 'identidade.autenticada';

    public static function set(array $identidade): void
    {
        Context::set(self::CHAVE, $identidade);
    }

    public static function get(): ?array
    {
        return Context::get(self::CHAVE);
    }

    public static function cdCliente(): int
    {
        return (int) self::get()['cd_cliente'];
    }

    public static function cdPerfis(): array
    {
        return array_map('intval', self::get()['cd_perfis'] ?? []);
    }

    public static function cdPessoa(): int
    {
        return (int) self::get()['cd_pessoa'];
    }
}
```

- [ ] **Step 2: Escrever o teste do `AuthService`**

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Service\Auth;

use App\Service\Auth\AuthService;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AuthServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Db::table('lgin_pessoa_perfil')->whereIn('cd_pessoa', function ($query) {
            $query->select('cd_pessoa')->from('unim_pessoa')->where('ds_login', 'like', 'teste.auth.%');
        })->delete();
        Db::table('unim_coligada')->whereIn('cd_pessoa', function ($query) {
            $query->select('cd_pessoa')->from('unim_pessoa')->where('ds_login', 'like', 'teste.auth.%');
        })->delete();
        Db::table('unim_pessoa')->where('ds_login', 'like', 'teste.auth.%')->delete();
        parent::tearDown();
    }

    public function testAutenticaComSenhaBcryptEGeraTokenComListaDePerfis()
    {
        // fixture cd_cliente=1 e cd_idioma=27 ja existem no banco de dev (ver Global Constraints)
        $cdPessoa = Db::table('unim_pessoa')->insertGetId([
            'cd_cliente' => 1,
            'ds_nome' => 'Auth Bcrypt Teste',
            'ds_login' => 'teste.auth.bcrypt',
            'ds_senha' => password_hash('minhasenha', PASSWORD_BCRYPT),
            'sn_pessoa_juridica' => 0,
        ]);

        $cdColigada = Db::table('unim_coligada')->insertGetId([
            'cd_pessoa' => $cdPessoa,
            'cd_cliente' => 1,
            'cd_idioma' => 27,
        ]);

        Db::table('lgin_pessoa_perfil')->insert([
            ['cd_pessoa' => $cdPessoa, 'cd_perfil' => 1, 'cd_coligada' => $cdColigada],
            ['cd_pessoa' => $cdPessoa, 'cd_perfil' => 2, 'cd_coligada' => $cdColigada],
        ]);

        $authService = $this->getContainer()->get(AuthService::class);
        $token = $authService->autenticar(1, 'teste.auth.bcrypt', 'minhasenha');

        $this->assertNotEmpty($token);

        $identidade = $authService->identidadePorToken($token);
        $this->assertSame($cdPessoa, $identidade['cd_pessoa']);
        $this->assertSame([1, 2], $identidade['cd_perfis']);

        $authService->logout($token);
        $this->assertNull($authService->identidadePorToken($token));
    }

    public function testAutenticaSemVinculoDePerfilRetornaListaVazia()
    {
        Db::table('unim_pessoa')->insert([
            'cd_cliente' => 1,
            'ds_nome' => 'Auth Sem Perfil Teste',
            'ds_login' => 'teste.auth.semperfil',
            'ds_senha' => password_hash('minhasenha', PASSWORD_BCRYPT),
            'sn_pessoa_juridica' => 0,
        ]);

        $authService = $this->getContainer()->get(AuthService::class);
        $token = $authService->autenticar(1, 'teste.auth.semperfil', 'minhasenha');

        $identidade = $authService->identidadePorToken($token);
        $this->assertSame([], $identidade['cd_perfis']);
    }

    public function testAutenticaComSenhaMd5EFazUpgradeSilenciosoPraBcrypt()
    {
        Db::table('unim_pessoa')->insert([
            'cd_cliente' => 1,
            'ds_nome' => 'Auth Md5 Teste',
            'ds_login' => 'teste.auth.md5',
            'ds_senha' => md5('senhafraca'),
            'sn_pessoa_juridica' => 0,
        ]);

        $authService = $this->getContainer()->get(AuthService::class);
        $token = $authService->autenticar(1, 'teste.auth.md5', 'senhafraca');

        $this->assertNotEmpty($token);

        $hashAtual = Db::table('unim_pessoa')->where('ds_login', 'teste.auth.md5')->value('ds_senha');
        $this->assertTrue(password_verify('senhafraca', $hashAtual));
    }

    public function testSenhaErradaNaoAutentica()
    {
        Db::table('unim_pessoa')->insert([
            'cd_cliente' => 1,
            'ds_nome' => 'Auth Errada Teste',
            'ds_login' => 'teste.auth.errada',
            'ds_senha' => password_hash('correta', PASSWORD_BCRYPT),
            'sn_pessoa_juridica' => 0,
        ]);

        $authService = $this->getContainer()->get(AuthService::class);

        $this->expectException(\App\Exception\Auth\CredenciaisInvalidasException::class);
        $authService->autenticar(1, 'teste.auth.errada', 'errada');
    }
}
```

- [ ] **Step 3: Rodar e confirmar que falha**

Run: `composer php-unit -- --filter AuthServiceTest`
Expected: FAIL — `App\Service\Auth\AuthService` e `App\Exception\Auth\CredenciaisInvalidasException` não existem.

- [ ] **Step 4: Criar a exceção de credenciais inválidas**

```php
<?php

declare(strict_types=1);

namespace App\Exception\Auth;

use App\Exception\HttpAwareException;

class CredenciaisInvalidasException extends HttpAwareException
{
    public function __construct()
    {
        parent::__construct('Login ou senha inválidos.');
    }

    public function getStatusCode(): int
    {
        return 401;
    }
}
```

- [ ] **Step 5: Implementar `AuthService`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Exception\Auth\CredenciaisInvalidasException;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;

class AuthService
{
    private const TTL_SESSAO = 8 * 60 * 60;

    public function __construct(private Redis $redis)
    {
    }

    public function autenticar(int $cdCliente, string $dsLogin, string $dsSenha): string
    {
        $pessoa = Db::table('unim_pessoa')
            ->where('cd_cliente', $cdCliente)
            ->where('ds_login', $dsLogin)
            ->whereNull('dt_excluido')
            ->first();

        if ($pessoa === null) {
            throw new CredenciaisInvalidasException();
        }

        $senhaBate = $this->verificarSenha($dsSenha, $pessoa->ds_senha);

        if (! $senhaBate) {
            throw new CredenciaisInvalidasException();
        }

        $this->atualizarHashSeNecessario($pessoa->cd_pessoa, $dsSenha, $pessoa->ds_senha);

        $token = bin2hex(random_bytes(32));

        $this->redis->setex(
            $this->chaveSessao($token),
            self::TTL_SESSAO,
            json_encode([
                'cd_pessoa' => $pessoa->cd_pessoa,
                'cd_cliente' => $pessoa->cd_cliente,
                'cd_perfis' => $this->buscarPerfisDaPessoa($pessoa->cd_pessoa, $pessoa->cd_cliente),
            ])
        );

        return $token;
    }

    /**
     * Uma pessoa pode ter varios perfis simultaneos (confirmado com dado real: contas de
     * teste no banco de dev tem 5 perfis cada). O vinculo eh lgin_pessoa_perfil -> unim_coligada
     * (unim_coligada.cd_cliente escopa por cliente; unim_coligada.cd_pessoa NAO filtra aqui —
     * eh o "dono" da coligada, nao quem tem perfil nela).
     *
     * @return int[]
     */
    private function buscarPerfisDaPessoa(int $cdPessoa, int $cdCliente): array
    {
        return Db::table('lgin_pessoa_perfil as lpp')
            ->join('unim_coligada as uc', 'uc.cd_coligada', '=', 'lpp.cd_coligada')
            ->where('lpp.cd_pessoa', $cdPessoa)
            ->where('uc.cd_cliente', $cdCliente)
            ->whereNull('uc.dt_excluido')
            ->pluck('lpp.cd_perfil')
            ->map(fn ($cdPerfil) => (int) $cdPerfil)
            ->values()
            ->all();
    }

    public function logout(string $token): void
    {
        $this->redis->del($this->chaveSessao($token));
    }

    public function identidadePorToken(string $token): ?array
    {
        $bruto = $this->redis->get($this->chaveSessao($token));

        return $bruto === false ? null : json_decode($bruto, true);
    }

    private function verificarSenha(string $senhaInformada, string $senhaBanco): bool
    {
        if (password_verify($senhaInformada, $senhaBanco)) {
            return true;
        }

        if (md5($senhaInformada) === $senhaBanco) {
            return true;
        }

        return $senhaInformada === $senhaBanco;
    }

    private function atualizarHashSeNecessario(int $cdPessoa, string $senhaInformada, string $senhaBanco): void
    {
        if (password_verify($senhaInformada, $senhaBanco)) {
            return;
        }

        Db::table('unim_pessoa')
            ->where('cd_pessoa', $cdPessoa)
            ->update(['ds_senha' => password_hash($senhaInformada, PASSWORD_BCRYPT)]);
    }

    private function chaveSessao(string $token): string
    {
        return "session:{$token}";
    }
}
```

> Nota: `unim_pessoa` não tem coluna de perfil própria — resolvido acima via `lgin_pessoa_perfil` + `unim_coligada` (achado e validado contra dado real do banco de dev antes de escrever este plano). `AuthServiceTest` cria as três linhas (pessoa, coligada, vínculo de perfil) via fixture `cd_cliente=1`/`cd_idioma=27` (ver Global Constraints — ambos precisam existir por FK real do MySQL, `saas_cliente`/`saas_idioma`).

- [ ] **Step 6: Rodar e confirmar que passa**

Run: `composer php-unit -- --filter AuthServiceTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Support/IdentidadeContext.php app/Service/Auth app/Exception/Auth test/Cases/Service/Auth
git commit -m "feat: adiciona AuthService (cascata de senha bcrypt/md5/texto puro) e IdentidadeContext"
```

---

## Task 9: `AuthMiddleware` + `AuthController` (login/logout)

**Files:**
- Create: `app/Middleware/AuthMiddleware.php`
- Create: `app/Controller/Auth/AuthController.php`
- Create: `app/Request/Auth/LoginRequest.php`
- Modify: `config/routes.php`
- Test: `test/Cases/Controller/Auth/AuthControllerTest.php`

**Interfaces:**
- Consumes: `App\Service\Auth\AuthService` (Task 8), `App\Support\IdentidadeContext` (Task 8), `App\Support\ApiResponse` (Task 2)
- Produces: rotas `POST /auth/login`, `POST /auth/logout`; `AuthMiddleware` seta `IdentidadeContext` — consumido por `AclMiddleware` (Task 11) e por qualquer controller que precise de `IdentidadeContext::cdCliente()` (Tasks 13/14).

- [ ] **Step 1: Escrever o teste**

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Controller\Auth;

use Hyperf\DbConnection\Db;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AuthControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Db::table('unim_pessoa')->where('ds_login', 'teste.controller.auth')->delete();
        parent::tearDown();
    }

    public function testLoginComCredenciaisValidasRetornaToken()
    {
        Db::table('unim_pessoa')->insert([
            'cd_cliente' => 1,
            'ds_nome' => 'Controller Auth Teste',
            'ds_login' => 'teste.controller.auth',
            'ds_senha' => password_hash('123456', PASSWORD_BCRYPT),
            'sn_pessoa_juridica' => 0,
        ]);

        $resposta = $this->post('/auth/login', [
            'cd_cliente' => 1,
            'ds_login' => 'teste.controller.auth',
            'ds_senha' => '123456',
        ]);

        $resposta->assertStatus(200);
        $this->assertTrue($resposta->json('success'));
        $this->assertNotEmpty($resposta->json('data.token'));
    }

    public function testLoginComSenhaErradaRetorna401()
    {
        Db::table('unim_pessoa')->insert([
            'cd_cliente' => 1,
            'ds_nome' => 'Controller Auth Teste',
            'ds_login' => 'teste.controller.auth',
            'ds_senha' => password_hash('123456', PASSWORD_BCRYPT),
            'sn_pessoa_juridica' => 0,
        ]);

        $resposta = $this->post('/auth/login', [
            'cd_cliente' => 1,
            'ds_login' => 'teste.controller.auth',
            'ds_senha' => 'errada',
        ]);

        $resposta->assertStatus(401);
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `composer php-unit -- --filter AuthControllerTest`
Expected: FAIL — rota `/auth/login` não existe (404).

- [ ] **Step 3: Implementar `LoginRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Request\Auth;

use Hyperf\Validation\Request\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cd_cliente' => 'required|integer',
            'ds_login' => 'required|string',
            'ds_senha' => 'required|string',
        ];
    }
}
```

- [ ] **Step 4: Implementar `AuthController`**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Controller\AbstractController;
use App\Request\Auth\LoginRequest;
use App\Service\Auth\AuthService;
use App\Support\ApiResponse;
use Hyperf\Di\Annotation\Inject;
use Psr\Http\Message\ResponseInterface;

class AuthController extends AbstractController
{
    #[Inject]
    protected AuthService $authService;

    public function login(LoginRequest $request): ResponseInterface
    {
        $dados = $request->validated();

        $token = $this->authService->autenticar($dados['cd_cliente'], $dados['ds_login'], $dados['ds_senha']);

        return $this->response->json(ApiResponse::sucesso(['token' => $token]));
    }

    public function logout(): ResponseInterface
    {
        $token = str_replace('Bearer ', '', $this->request->getHeaderLine('Authorization'));

        $this->authService->logout($token);

        return $this->response->json(ApiResponse::sucesso(null));
    }
}
```

- [ ] **Step 5: Implementar `AuthMiddleware`**

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\Auth\AuthService;
use App\Support\ApiResponse;
use App\Support\IdentidadeContext;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthService $authService, private PsrResponseInterface $response)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        $token = str_replace('Bearer ', '', $request->getHeaderLine('Authorization'));

        $identidade = $token === '' ? null : $this->authService->identidadePorToken($token);

        if ($identidade === null) {
            return $this->response
                ->withStatus(401)
                ->withBody(new SwooleStream(json_encode(ApiResponse::erro('Não autenticado.'))));
        }

        IdentidadeContext::set($identidade);

        return $handler->handle($request);
    }
}
```

- [ ] **Step 6: Registrar as rotas em `config/routes.php`**

```php
use App\Controller\Auth\AuthController;

Router::post('/auth/login', [AuthController::class, 'login']);
Router::post('/auth/logout', [AuthController::class, 'logout'], ['middleware' => [\App\Middleware\AuthMiddleware::class]]);
```

- [ ] **Step 7: Rodar e confirmar que passa**

Run: `composer php-unit -- --filter AuthControllerTest`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Middleware/AuthMiddleware.php app/Controller/Auth app/Request/Auth config/routes.php test/Cases/Controller/Auth
git commit -m "feat: adiciona rotas de login/logout e AuthMiddleware"
```

---

## Task 10: `AclRepository` — permissões por perfil

Vínculo pessoa↔perfil (`lgin_pessoa_perfil` + `unim_coligada`) já foi resolvido e usado no `AuthService` (Task 8). Esta task só cobre a leitura de permissões por perfil, consumida pelo `AclService` (Task 11).

**Files:**
- Create: `app/Repository/Acl/AclRepository.php`
- Test: `test/Cases/Repository/Acl/AclRepositoryTest.php`

**Interfaces:**
- Produces:
```php
class AclRepository
{
    public function buscarPermissoesPorPerfil(int $cdPerfil): array; // ['pessoa' => ['listar', 'criar', ...], ...]
}
```
Consumido por `AclService` (Task 11).

- [ ] **Step 1: Escrever o teste do `AclRepository` (usa dado real do seed — `cd_perfil=1` já tem grants em `lgin_perfil_recurso_privilegio`, ver Global Constraints)**

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Repository\Acl;

use App\Repository\Acl\AclRepository;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AclRepositoryTest extends TestCase
{
    public function testBuscarPermissoesPorPerfilAgrupaPorRecurso()
    {
        $repository = $this->getContainer()->get(AclRepository::class);

        $permissoes = $repository->buscarPermissoesPorPerfil(1);

        $this->assertNotEmpty($permissoes);

        foreach ($permissoes as $recurso => $privilegios) {
            $this->assertIsString($recurso);
            $this->assertIsArray($privilegios);
        }
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `composer php-unit -- --filter AclRepositoryTest`
Expected: FAIL — `App\Repository\Acl\AclRepository` não existe.

- [ ] **Step 3: Implementar `AclRepository`**

```php
<?php

declare(strict_types=1);

namespace App\Repository\Acl;

use Hyperf\DbConnection\Db;

class AclRepository
{
    public function buscarPermissoesPorPerfil(int $cdPerfil): array
    {
        $linhas = Db::table('lgin_perfil_recurso_privilegio as lprp')
            ->join('ulms_recurso_privilegio as urp', 'urp.cd_recurso_privilegio', '=', 'lprp.cd_recurso_privilegio')
            ->join('ulms_recurso as ur', 'ur.cd_recurso', '=', 'urp.cd_recurso')
            ->join('ulms_privilegio as up', 'up.cd_privilegio', '=', 'urp.cd_privilegio')
            ->where('lprp.cd_perfil', $cdPerfil)
            ->select('ur.ds_chave as recurso', 'up.ds_chave as privilegio')
            ->get();

        $permissoes = [];

        foreach ($linhas as $linha) {
            $permissoes[$linha->recurso][] = $linha->privilegio;
        }

        return $permissoes;
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `composer php-unit -- --filter AclRepositoryTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Repository/Acl test/Cases/Repository/Acl
git commit -m "feat: adiciona AclRepository (permissoes por perfil)"
```

---

## Task 11: `AclService` + `AclMiddleware`

**Files:**
- Create: `app/Service/Acl/AclService.php`
- Create: `app/Middleware/AclMiddleware.php`
- Modify: `config/autoload/middlewares.php`
- Test: `test/Cases/Service/Acl/AclServiceTest.php`
- Test: `test/Cases/Middleware/AclMiddlewareTest.php`

**Interfaces:**
- Consumes: `App\Repository\Acl\AclRepository` (Task 10), `App\Support\IdentidadeContext` (Task 8)
- Produces:
```php
class AclService
{
    public function isAllowed(array $cdPerfis, string $recurso, string $privilegio): bool; // uniao — true se QUALQUER perfil da lista conceder
    public function invalidar(int $cdPerfil): void;
}
```
Rota passa exigência de ACL via opção customizada: `Router::get(..., ['middleware' => [...], 'acl' => ['recurso' => 'pessoa', 'privilegio' => 'listar']])` — consumido pelas rotas de Pessoa na Task 14. `AclMiddleware` lê `IdentidadeContext::cdPerfis()` (lista) e passa a lista inteira pro `AclService`.

- [ ] **Step 1: Escrever o teste do `AclService`**

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Service\Acl;

use App\Service\Acl\AclService;
use Hyperf\Redis\Redis;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AclServiceTest extends TestCase
{
    public function testIsAllowedUsaCacheAposPrimeiraConsulta()
    {
        $aclService = $this->getContainer()->get(AclService::class);
        $redis = $this->getContainer()->get(Redis::class);

        $redis->del('acl:perfil:1');

        // primeira chamada monta do banco e grava no cache
        $aclService->isAllowed([1], 'pessoa', 'listar');

        $this->assertNotEmpty($redis->get('acl:perfil:1'));
    }

    public function testIsAllowedRetornaTrueSeQualquerPerfilDaListaConceder()
    {
        $aclService = $this->getContainer()->get(AclService::class);
        $redis = $this->getContainer()->get(Redis::class);

        $redis->setex('acl:perfil:1', 3600, json_encode(['pessoa' => []]));
        $redis->setex('acl:perfil:2', 3600, json_encode(['pessoa' => ['listar']]));

        $this->assertTrue($aclService->isAllowed([1, 2], 'pessoa', 'listar'));
        $this->assertFalse($aclService->isAllowed([1], 'pessoa', 'listar'));
    }

    public function testInvalidarRemoveOCache()
    {
        $aclService = $this->getContainer()->get(AclService::class);
        $redis = $this->getContainer()->get(Redis::class);

        $aclService->isAllowed([1], 'pessoa', 'listar');
        $this->assertNotEmpty($redis->get('acl:perfil:1'));

        $aclService->invalidar(1);
        $this->assertFalse($redis->get('acl:perfil:1'));
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `composer php-unit -- --filter AclServiceTest`
Expected: FAIL — `App\Service\Acl\AclService` não existe.

- [ ] **Step 3: Implementar `AclService`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Acl;

use App\Repository\Acl\AclRepository;
use Hyperf\Redis\Redis;

class AclService
{
    private const TTL_CACHE = 86400;

    public function __construct(private AclRepository $aclRepository, private Redis $redis)
    {
    }

    public function isAllowed(array $cdPerfis, string $recurso, string $privilegio): bool
    {
        foreach ($cdPerfis as $cdPerfil) {
            $permissoes = $this->permissoesDoPerfil($cdPerfil);

            if (in_array($privilegio, $permissoes[$recurso] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    public function invalidar(int $cdPerfil): void
    {
        $this->redis->del($this->chave($cdPerfil));
    }

    private function permissoesDoPerfil(int $cdPerfil): array
    {
        $cacheado = $this->redis->get($this->chave($cdPerfil));

        if ($cacheado !== false) {
            return json_decode($cacheado, true);
        }

        $permissoes = $this->aclRepository->buscarPermissoesPorPerfil($cdPerfil);

        $this->redis->setex($this->chave($cdPerfil), self::TTL_CACHE, json_encode($permissoes));

        return $permissoes;
    }

    private function chave(int $cdPerfil): string
    {
        return "acl:perfil:{$cdPerfil}";
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `composer php-unit -- --filter AclServiceTest`
Expected: PASS

- [ ] **Step 5: Escrever o teste do `AclMiddleware` — primeiro confirmando que opções customizadas de rota sobrevivem no `Dispatched::$handler->options`**

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Middleware;

use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AclMiddlewareTest extends TestCase
{
    public function testOpcaoCustomizadaAclSobreviveNasOpcoesDaRota()
    {
        \Hyperf\HttpServer\Router\Router::get(
            '/__teste_acl_options',
            static fn () => 'ok',
            ['acl' => ['recurso' => 'pessoa', 'privilegio' => 'listar']]
        );

        $resposta = $this->get('/__teste_acl_options');

        $resposta->assertStatus(200);
        // Se essa asserção falhar, a opção 'acl' NÃO sobrevive em Dispatched::$handler->options
        // e o AclMiddleware precisa de outro mecanismo (ex: atributo PHP na rota, ou tabela própria
        // de resource/privilege por path) — reavaliar antes de prosseguir.
    }
}
```

- [ ] **Step 6: Rodar esse teste isoladamente pra validar a suposição ANTES de escrever o middleware de verdade**

Run: `composer php-unit -- --filter testOpcaoCustomizadaAclSobreviveNasOpcoesDaRota`
Expected: PASS (rota responde 200 — confirma só que a rota registra; a validação real da opção `acl` acontece dentro do middleware no próximo passo, usando `var_dump`/`dd` temporário se precisar depurar).

- [ ] **Step 7: Implementar `AclMiddleware`**

```php
<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\Acl\AclService;
use App\Support\ApiResponse;
use App\Support\IdentidadeContext;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Router\Dispatched;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AclMiddleware implements MiddlewareInterface
{
    public function __construct(private AclService $aclService, private PsrResponseInterface $response)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        $dispatched = $request->getAttribute(Dispatched::class);
        $opcoesAcl = $dispatched->handler->options['acl'] ?? null;

        if ($opcoesAcl === null) {
            return $handler->handle($request);
        }

        $permitido = $this->aclService->isAllowed(
            IdentidadeContext::cdPerfis(),
            $opcoesAcl['recurso'],
            $opcoesAcl['privilegio']
        );

        if (! $permitido) {
            return $this->response
                ->withStatus(403)
                ->withBody(new SwooleStream(json_encode(ApiResponse::erro('Sem permissão para esta ação.'))));
        }

        return $handler->handle($request);
    }
}
```

- [ ] **Step 8: Registrar `AclMiddleware` em `config/autoload/middlewares.php`**

Diferente do `AuthMiddleware` (que fica só nas rotas que exigem login), `AclMiddleware` roda em qualquer rota que declare a opção `acl` — deixar disponível pra ser referenciado por rota (Task 14), sem registrar globalmente:

```php
<?php

declare(strict_types=1);

return [
    'http' => [
        // AclMiddleware é referenciado por rota individualmente (ver Task 14), não aqui.
    ],
];
```

- [ ] **Step 9: Rodar `composer test` completo**

Run: `composer test`
Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add app/Service/Acl app/Middleware/AclMiddleware.php config/autoload/middlewares.php test/Cases/Service/Acl test/Cases/Middleware
git commit -m "feat: adiciona AclService e AclMiddleware"
```

---

## Task 12: Requests de validação — Create/Update/Patch/List de Pessoa

**Files:**
- Create: `app/Request/Pessoa/CreatePessoaRequest.php`
- Create: `app/Request/Pessoa/UpdatePessoaRequest.php`
- Create: `app/Request/Pessoa/PatchPessoaRequest.php`
- Create: `app/Request/Pessoa/ListPessoaRequest.php`
- Test: `test/Cases/Request/Pessoa/CreatePessoaRequestTest.php`

**Interfaces:**
- Produces: 4 classes `FormRequest` consumidas pelo `PessoaController` (Task 14). Todas expõem `validated(): array`.

- [ ] **Step 1: Escrever o teste de `CreatePessoaRequest` (valida via `Hyperf\Validation\Factory\ValidatorFactoryInterface` direto, sem precisar de uma rota HTTP completa)**

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Request\Pessoa;

use App\Request\Pessoa\CreatePessoaRequest;
use Hyperf\Testing\TestCase;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;

/**
 * @internal
 * @coversNothing
 */
class CreatePessoaRequestTest extends TestCase
{
    public function testFalhaSemCamposObrigatorios()
    {
        $factory = $this->getContainer()->get(ValidatorFactoryInterface::class);
        $request = new CreatePessoaRequest();

        $validator = $factory->make([], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('ds_nome', $validator->errors()->toArray());
        $this->assertArrayHasKey('ds_login', $validator->errors()->toArray());
        $this->assertArrayHasKey('ds_senha', $validator->errors()->toArray());
        $this->assertArrayHasKey('sn_pessoa_juridica', $validator->errors()->toArray());
    }

    public function testPassaComCamposMinimosDePessoaFisica()
    {
        $factory = $this->getContainer()->get(ValidatorFactoryInterface::class);
        $request = new CreatePessoaRequest();

        $validator = $factory->make([
            'ds_nome' => 'Fulano de Teste',
            'ds_login' => 'fulano.teste',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Fulano de Teste Oficial',
        ], $request->rules());

        $this->assertFalse($validator->fails());
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `composer php-unit -- --filter CreatePessoaRequestTest`
Expected: FAIL — classe não existe.

- [ ] **Step 3: Implementar `CreatePessoaRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Request\Pessoa;

use Hyperf\Validation\Request\FormRequest;

class CreatePessoaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ds_nome' => 'required|string|max:255',
            'ds_login' => 'required|string|max:100',
            'ds_senha' => 'required|string|min:6',
            'sn_pessoa_juridica' => 'required|boolean',
            'ds_nome_oficial' => 'required_if:sn_pessoa_juridica,false|string|max:255',
            'ds_cpf' => 'nullable|string',
            'ds_cnpj' => 'required_if:sn_pessoa_juridica,true|string',
            'ds_nome_fantasia' => 'required_if:sn_pessoa_juridica,true|string|max:255',
        ];
    }
}
```

- [ ] **Step 4: Implementar `UpdatePessoaRequest` (completo — mesmos campos obrigatórios do create, exceto senha)**

```php
<?php

declare(strict_types=1);

namespace App\Request\Pessoa;

use Hyperf\Validation\Request\FormRequest;

class UpdatePessoaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ds_nome' => 'required|string|max:255',
            'ds_login' => 'required|string|max:100',
            'ds_senha' => 'nullable|string|min:6',
            'sn_pessoa_juridica' => 'required|boolean',
            'ds_nome_oficial' => 'required_if:sn_pessoa_juridica,false|string|max:255',
            'ds_cpf' => 'nullable|string',
            'ds_cnpj' => 'required_if:sn_pessoa_juridica,true|string',
            'ds_nome_fantasia' => 'required_if:sn_pessoa_juridica,true|string|max:255',
        ];
    }
}
```

- [ ] **Step 5: Implementar `PatchPessoaRequest` (parcial — nenhum campo obrigatório individualmente, mas exige ao menos 1 no payload)**

```php
<?php

declare(strict_types=1);

namespace App\Request\Pessoa;

use Hyperf\Validation\Request\FormRequest;

class PatchPessoaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ds_nome' => 'sometimes|string|max:255',
            'ds_login' => 'sometimes|string|max:100',
            'ds_senha' => 'sometimes|string|min:6',
            'ds_nome_oficial' => 'sometimes|string|max:255',
            'ds_cpf' => 'sometimes|nullable|string',
            'ds_cnpj' => 'sometimes|string',
            'ds_nome_fantasia' => 'sometimes|string|max:255',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (empty($this->all())) {
                $validator->errors()->add('payload', 'Envie ao menos um campo para atualizar.');
            }
        });
    }
}
```

- [ ] **Step 6: Implementar `ListPessoaRequest`**

```php
<?php

declare(strict_types=1);

namespace App\Request\Pessoa;

use Hyperf\Validation\Request\FormRequest;

class ListPessoaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1',
            'nome' => 'sometimes|string',
            'tipo_pessoa' => 'sometimes|in:fisica,juridica',
        ];
    }
}
```

- [ ] **Step 7: Rodar e confirmar que passa**

Run: `composer php-unit -- --filter CreatePessoaRequestTest`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Request/Pessoa test/Cases/Request/Pessoa
git commit -m "feat: adiciona requests de validacao do CRUD de pessoa"
```

---

## Task 13: `PessoaService` — regra de negócio completa

**Files:**
- Create: `app/Service/Pessoa/PessoaService.php`
- Test: `test/Cases/Service/Pessoa/PessoaServiceTest.php`

**Interfaces:**
- Consumes: `App\Repository\Pessoa\PessoaRepositoryInterface` (Tasks 6/7), `App\Exception\Pessoa\{PessoaNaoEncontradaException,LoginJaExisteException}` (Task 3)
- Produces:
```php
class PessoaService
{
    public function criar(int $cdCliente, array $dados): UnimPessoa;
    public function atualizar(int $cdPessoa, int $cdCliente, array $dados): UnimPessoa;
    public function atualizarParcial(int $cdPessoa, int $cdCliente, array $dados): UnimPessoa;
    public function buscar(int $cdPessoa, int $cdCliente): UnimPessoa;
    public function listar(int $cdCliente, array $filtros, int $page, int $perPage): array;
    public function excluir(int $cdPessoa, int $cdCliente): void;
}
```
Consumido por `PessoaController` (Task 14).

- [ ] **Step 1: Escrever os testes cobrindo as regras do spec (login duplicado, exceção admin, senha opcional no update)**

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Service\Pessoa;

use App\Exception\Pessoa\LoginJaExisteException;
use App\Exception\Pessoa\PessoaNaoEncontradaException;
use App\Service\Pessoa\PessoaService;
use Hyperf\DbConnection\Db;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class PessoaServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Db::table('unim_pessoa')->where('ds_login', 'like', 'teste.service.%')->delete();
        parent::tearDown();
    }

    public function testCriarPessoaFisicaComSucesso()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $pessoa = $service->criar(1, [
            'ds_nome' => 'Service Teste',
            'ds_login' => 'teste.service.criar',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Service Teste Oficial',
        ]);

        $this->assertNotNull($pessoa->cd_pessoa);
        $this->assertTrue(password_verify('123456', $pessoa->ds_senha));
    }

    public function testCriarComLoginDuplicadoLancaExcecao()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $service->criar(1, [
            'ds_nome' => 'Duplicado 1',
            'ds_login' => 'teste.service.duplicado',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Duplicado 1',
        ]);

        $this->expectException(LoginJaExisteException::class);

        $service->criar(1, [
            'ds_nome' => 'Duplicado 2',
            'ds_login' => 'teste.service.duplicado',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Duplicado 2',
        ]);
    }

    public function testCriarComLoginAdminNaoExigeFisicaOuJuridica()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $pessoa = $service->criar(1, [
            'ds_nome' => 'Administrador Teste',
            'ds_login' => 'teste.service.admin',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ]);

        $this->assertNotNull($pessoa->cd_pessoa);
        $this->assertNull($pessoa->fisica);
    }

    public function testAtualizarSemSenhaMantemSenhaAtual()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $pessoa = $service->criar(1, [
            'ds_nome' => 'Mantem Senha',
            'ds_login' => 'teste.service.mantemsenha',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Mantem Senha',
        ]);
        $hashOriginal = $pessoa->ds_senha;

        $atualizada = $service->atualizar($pessoa->cd_pessoa, 1, [
            'ds_nome' => 'Mantem Senha Renomeado',
            'ds_login' => 'teste.service.mantemsenha',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Mantem Senha',
        ]);

        $this->assertSame($hashOriginal, $atualizada->ds_senha);
    }

    public function testBuscarPessoaInexistenteLancaExcecao()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $this->expectException(PessoaNaoEncontradaException::class);
        $service->buscar(999999, 1);
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

Run: `composer php-unit -- --filter PessoaServiceTest`
Expected: FAIL — `App\Service\Pessoa\PessoaService` não existe.

- [ ] **Step 3: Implementar `PessoaService`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Pessoa;

use App\Exception\Pessoa\LoginJaExisteException;
use App\Exception\Pessoa\PessoaNaoEncontradaException;
use App\Model\Pessoa\UnimPessoa;
use App\Repository\Pessoa\PessoaRepositoryInterface;

class PessoaService
{
    private const LOGINS_ISENTOS_DE_FISICA_JURIDICA = ['admin', 'administrador'];

    public function __construct(private PessoaRepositoryInterface $pessoaRepository)
    {
    }

    public function criar(int $cdCliente, array $dados): UnimPessoa
    {
        if ($this->pessoaRepository->loginExiste($cdCliente, $dados['ds_login'])) {
            throw new LoginJaExisteException();
        }

        [$dadosPessoa, $dadosFisica, $dadosJuridica] = $this->separarDados($cdCliente, $dados, criando: true);

        return $this->pessoaRepository->criar($dadosPessoa, $dadosFisica, $dadosJuridica);
    }

    public function atualizar(int $cdPessoa, int $cdCliente, array $dados): UnimPessoa
    {
        $this->garantirLoginDisponivel($cdPessoa, $cdCliente, $dados['ds_login']);

        [$dadosPessoa, $dadosFisica, $dadosJuridica] = $this->separarDados($cdCliente, $dados, criando: false);

        return $this->pessoaRepository->atualizar($cdPessoa, $cdCliente, $dadosPessoa, $dadosFisica, $dadosJuridica);
    }

    public function atualizarParcial(int $cdPessoa, int $cdCliente, array $dados): UnimPessoa
    {
        if (isset($dados['ds_login'])) {
            $this->garantirLoginDisponivel($cdPessoa, $cdCliente, $dados['ds_login']);
        }

        $dadosPessoa = array_intersect_key($dados, array_flip(['ds_nome', 'ds_login', 'ds_senha']));

        if (isset($dadosPessoa['ds_senha'])) {
            $dadosPessoa['ds_senha'] = password_hash($dadosPessoa['ds_senha'], PASSWORD_BCRYPT);
        }

        $dadosFisica = array_intersect_key($dados, array_flip(['ds_nome_oficial', 'ds_cpf']));
        $dadosJuridica = array_intersect_key($dados, array_flip(['ds_cnpj', 'ds_nome_fantasia']));

        return $this->pessoaRepository->atualizar(
            $cdPessoa,
            $cdCliente,
            $dadosPessoa,
            $dadosFisica ?: null,
            $dadosJuridica ?: null
        );
    }

    public function buscar(int $cdPessoa, int $cdCliente): UnimPessoa
    {
        $pessoa = $this->pessoaRepository->buscarPorId($cdPessoa, $cdCliente);

        if ($pessoa === null) {
            throw new PessoaNaoEncontradaException();
        }

        return $pessoa;
    }

    public function listar(int $cdCliente, array $filtros, int $page, int $perPage): array
    {
        $perPage = min($perPage, 100);

        return $this->pessoaRepository->listar($cdCliente, $filtros, $page, $perPage);
    }

    public function excluir(int $cdPessoa, int $cdCliente): void
    {
        if (! $this->pessoaRepository->excluir($cdPessoa, $cdCliente)) {
            throw new PessoaNaoEncontradaException();
        }
    }

    private function garantirLoginDisponivel(int $cdPessoa, int $cdCliente, string $dsLogin): void
    {
        if ($this->pessoaRepository->loginExiste($cdCliente, $dsLogin, ignorarCdPessoa: $cdPessoa)) {
            throw new LoginJaExisteException();
        }
    }

    private function separarDados(int $cdCliente, array $dados, bool $criando): array
    {
        $dadosPessoa = [
            'cd_cliente' => $cdCliente,
            'ds_nome' => $dados['ds_nome'],
            'ds_login' => $dados['ds_login'],
            'sn_pessoa_juridica' => $dados['sn_pessoa_juridica'],
        ];

        if (isset($dados['ds_senha'])) {
            $dadosPessoa['ds_senha'] = password_hash($dados['ds_senha'], PASSWORD_BCRYPT);
        }

        $ehIsentoDeFisicaJuridica = in_array(strtolower($dados['ds_login']), self::LOGINS_ISENTOS_DE_FISICA_JURIDICA, true);

        if ($ehIsentoDeFisicaJuridica) {
            return [$dadosPessoa, null, null];
        }

        if ($dados['sn_pessoa_juridica']) {
            $dadosJuridica = [
                'ds_cnpj' => $dados['ds_cnpj'],
                'ds_nome_fantasia' => $dados['ds_nome_fantasia'],
            ];

            return [$dadosPessoa, null, $dadosJuridica];
        }

        $dadosFisica = ['ds_nome_oficial' => $dados['ds_nome_oficial']];

        if (isset($dados['ds_cpf'])) {
            $dadosFisica['ds_cpf'] = $dados['ds_cpf'];
        }

        return [$dadosPessoa, $dadosFisica, null];
    }
}
```

- [ ] **Step 4: Rodar e confirmar que passa**

Run: `composer php-unit -- --filter PessoaServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Service/Pessoa test/Cases/Service/Pessoa
git commit -m "feat: adiciona PessoaService com regra de negocio completa"
```

---

## Task 14: `PessoaResource` + `PessoaController` — rotas HTTP completas

**Files:**
- Create: `app/Resource/Pessoa/PessoaResource.php`
- Create: `app/Controller/Pessoa/PessoaController.php`
- Modify: `config/routes.php`
- Test: `test/Cases/Controller/Pessoa/PessoaControllerTest.php`

**Interfaces:**
- Consumes: `App\Service\Pessoa\PessoaService` (Task 13), `App\Request\Pessoa\*` (Task 12), `App\Support\{ApiResponse,IdentidadeContext}` (Tasks 2/8), `App\Middleware\{AuthMiddleware,AclMiddleware}` (Tasks 9/11)
- Produces: rotas `POST|PUT|PATCH|DELETE|GET /pessoas[/{id}]`

- [ ] **Step 1: Implementar `PessoaResource`**

```php
<?php

declare(strict_types=1);

namespace App\Resource\Pessoa;

use App\Model\Pessoa\UnimPessoa;

class PessoaResource
{
    public static function um(UnimPessoa $pessoa): array
    {
        return [
            'cd_pessoa' => $pessoa->cd_pessoa,
            'cd_cliente' => $pessoa->cd_cliente,
            'ds_nome' => $pessoa->ds_nome,
            'ds_login' => $pessoa->ds_login,
            'sn_pessoa_juridica' => $pessoa->sn_pessoa_juridica,
            'fisica' => $pessoa->fisica ? [
                'ds_nome_oficial' => $pessoa->fisica->ds_nome_oficial,
                'ds_cpf' => $pessoa->fisica->ds_cpf,
            ] : null,
            'juridica' => $pessoa->juridica ? [
                'ds_cnpj' => $pessoa->juridica->ds_cnpj,
                'ds_nome_fantasia' => $pessoa->juridica->ds_nome_fantasia,
            ] : null,
        ];
    }

    public static function muitos(iterable $pessoas): array
    {
        return array_map(static fn (UnimPessoa $pessoa) => self::um($pessoa), [...$pessoas]);
    }
}
```

- [ ] **Step 2: Escrever o teste do Controller (cobre as rotas felizes + ACL negando)**

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Controller\Pessoa;

use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class PessoaControllerTest extends TestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $redis = $this->getContainer()->get(Redis::class);
        $this->token = bin2hex(random_bytes(32));
        $redis->setex("session:{$this->token}", 3600, json_encode([
            'cd_pessoa' => 1,
            'cd_cliente' => 1,
            'cd_perfis' => [1],
        ]));

        // garantir que o perfil 1 tem os privilégios de pessoa liberados nesta massa de teste
        $redis->setex('acl:perfil:1', 3600, json_encode([
            'pessoa' => ['criar', 'atualizar', 'visualizar', 'listar', 'excluir'],
        ]));
    }

    protected function tearDown(): void
    {
        Db::table('unim_pessoa')->where('ds_login', 'like', 'teste.http.%')->delete();
        parent::tearDown();
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function testCriarBuscarAtualizarEExcluirPessoaFisica()
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste',
            'ds_login' => 'teste.http.crud',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Oficial',
        ], $this->headers());

        $criar->assertStatus(201);
        $cdPessoa = $criar->json('data.cd_pessoa');

        $buscar = $this->get("/pessoas/{$cdPessoa}", [], $this->headers());
        $buscar->assertStatus(200);
        $this->assertSame('Http Teste', $buscar->json('data.ds_nome'));

        $patch = $this->request('PATCH', "/pessoas/{$cdPessoa}", ['options' => ['json' => ['ds_nome' => 'Http Teste Renomeado']], 'headers' => $this->headers()]);
        $patch->assertStatus(200);
        $this->assertSame('Http Teste Renomeado', $patch->json('data.ds_nome'));

        $excluir = $this->request('DELETE', "/pessoas/{$cdPessoa}", ['headers' => $this->headers()]);
        $excluir->assertStatus(200);

        $buscarDepoisDeExcluir = $this->get("/pessoas/{$cdPessoa}", [], $this->headers());
        $buscarDepoisDeExcluir->assertStatus(404);
    }

    public function testListarComFiltroDeNomeEPaginacao()
    {
        $this->json('/pessoas', [
            'ds_nome' => 'Http Lista Um',
            'ds_login' => 'teste.http.lista1',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Lista Um',
        ], $this->headers());

        $listar = $this->get('/pessoas?nome=Lista&per_page=10', [], $this->headers());

        $listar->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $listar->json('meta.total'));
    }

    public function testSemTokenRetorna401()
    {
        $this->get('/pessoas')->assertStatus(401);
    }

    public function testSemPermissaoAclRetorna403()
    {
        $redis = $this->getContainer()->get(Redis::class);
        $redis->setex('acl:perfil:1', 3600, json_encode(['pessoa' => []]));

        $this->get('/pessoas', [], $this->headers())->assertStatus(403);
    }
}
```

- [ ] **Step 3: Rodar e confirmar que falha**

Run: `composer php-unit -- --filter PessoaControllerTest`
Expected: FAIL — rotas não existem (404).

- [ ] **Step 4: Implementar `PessoaController`**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Pessoa;

use App\Controller\AbstractController;
use App\Request\Pessoa\CreatePessoaRequest;
use App\Request\Pessoa\ListPessoaRequest;
use App\Request\Pessoa\PatchPessoaRequest;
use App\Request\Pessoa\UpdatePessoaRequest;
use App\Resource\Pessoa\PessoaResource;
use App\Service\Pessoa\PessoaService;
use App\Support\ApiResponse;
use App\Support\IdentidadeContext;
use Hyperf\Di\Annotation\Inject;
use Psr\Http\Message\ResponseInterface;

class PessoaController extends AbstractController
{
    #[Inject]
    protected PessoaService $pessoaService;

    public function criar(CreatePessoaRequest $request): ResponseInterface
    {
        $pessoa = $this->pessoaService->criar(IdentidadeContext::cdCliente(), $request->validated());

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)))->withStatus(201);
    }

    public function atualizar(int $id, UpdatePessoaRequest $request): ResponseInterface
    {
        $pessoa = $this->pessoaService->atualizar($id, IdentidadeContext::cdCliente(), $request->validated());

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)));
    }

    public function atualizarParcial(int $id, PatchPessoaRequest $request): ResponseInterface
    {
        $pessoa = $this->pessoaService->atualizarParcial($id, IdentidadeContext::cdCliente(), $request->validated());

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)));
    }

    public function buscar(int $id): ResponseInterface
    {
        $pessoa = $this->pessoaService->buscar($id, IdentidadeContext::cdCliente());

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)));
    }

    public function listar(ListPessoaRequest $request): ResponseInterface
    {
        $filtros = array_intersect_key($request->validated(), array_flip(['nome', 'tipo_pessoa']));
        $page = (int) ($request->validated()['page'] ?? 1);
        $perPage = (int) ($request->validated()['per_page'] ?? 20);

        $resultado = $this->pessoaService->listar(IdentidadeContext::cdCliente(), $filtros, $page, $perPage);

        return $this->response->json(ApiResponse::sucesso(
            PessoaResource::muitos($resultado['itens']),
            [
                'total' => $resultado['total'],
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) ceil($resultado['total'] / $perPage),
            ]
        ));
    }

    public function excluir(int $id): ResponseInterface
    {
        $this->pessoaService->excluir($id, IdentidadeContext::cdCliente());

        return $this->response->json(ApiResponse::sucesso(null));
    }
}
```

- [ ] **Step 5: Registrar as rotas em `config/routes.php`**

```php
use App\Controller\Pessoa\PessoaController;
use App\Middleware\AclMiddleware;
use App\Middleware\AuthMiddleware;

Router::addGroup('/pessoas', function () {
    Router::post('', [PessoaController::class, 'criar'], ['acl' => ['recurso' => 'pessoa', 'privilegio' => 'criar']]);
    Router::put('/{id}', [PessoaController::class, 'atualizar'], ['acl' => ['recurso' => 'pessoa', 'privilegio' => 'atualizar']]);
    Router::patch('/{id}', [PessoaController::class, 'atualizarParcial'], ['acl' => ['recurso' => 'pessoa', 'privilegio' => 'atualizar']]);
    Router::get('/{id}', [PessoaController::class, 'buscar'], ['acl' => ['recurso' => 'pessoa', 'privilegio' => 'visualizar']]);
    Router::get('', [PessoaController::class, 'listar'], ['acl' => ['recurso' => 'pessoa', 'privilegio' => 'listar']]);
    Router::delete('/{id}', [PessoaController::class, 'excluir'], ['acl' => ['recurso' => 'pessoa', 'privilegio' => 'excluir']]);
}, ['middleware' => [AuthMiddleware::class, AclMiddleware::class]]);
```

- [ ] **Step 6: Rodar e confirmar que passa**

Run: `composer php-unit -- --filter PessoaControllerTest`
Expected: PASS

- [ ] **Step 7: Rodar `composer test` completo**

Run: `composer test`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add app/Resource/Pessoa app/Controller/Pessoa config/routes.php test/Cases/Controller/Pessoa
git commit -m "feat: adiciona rotas HTTP completas do CRUD de pessoa"
```

---

## Task 15: OpenAPI/Swagger — anotações nas rotas + config

**Files:**
- Modify: `app/Controller/Pessoa/PessoaController.php` (adiciona atributos)
- Modify: `app/Controller/Auth/AuthController.php` (adiciona atributos)
- Create: `config/autoload/swagger.php`
- Test: nenhum teste novo — validação é rodar o comando de geração e conferir o JSON produzido (Step 3)

**Interfaces:**
- Consumes: `hyperf/swagger` (instalado na Task 1)
- Produces: `storage/swagger/http.json` atualizado com as rotas de pessoa/auth documentadas — consumido pelo container de docs (Task 16)

- [ ] **Step 1: Criar `config/autoload/swagger.php` (gera o JSON, sem servir UI na porta 9500)**

```php
<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'enable' => (bool) env('SWAGGER_AUTO_GENERATE', true),
    'server' => null, // sem servidor HTTP dedicado — só geração de arquivo
    'output_file' => BASE_PATH . '/storage/swagger/http.json',
    'json_dir' => BASE_PATH . '/storage/swagger',
    'html' => BASE_PATH . '/storage/swagger/swagger-ui.html',
    'url' => env('SWAGGER_URL_PATH', '/swagger'),
    'scan' => [
        'paths' => [BASE_PATH . '/app'],
    ],
];
```

> Conferir o nome real das chaves aceitas por `hyperf/swagger` na publicação de config (`php bin/hyperf.php vendor:publish hyperf/swagger`, se existir) — o array acima é o esqueleto mínimo esperado; ajustar chaves pro formato real publicado antes de seguir pro Step 2.

- [ ] **Step 2: Anotar `PessoaController::listar` com o atributo OpenAPI (padrão pras demais rotas)**

```php
use Hyperf\Swagger\Annotation as OA;

#[OA\Get(path: '/pessoas', summary: 'Lista pessoas do cliente autenticado', tags: ['Pessoa'])]
#[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1))]
#[OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100))]
#[OA\Parameter(name: 'nome', in: 'query', schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(name: 'tipo_pessoa', in: 'query', schema: new OA\Schema(type: 'string', enum: ['fisica', 'juridica']))]
#[OA\Response(response: 200, description: 'Lista paginada de pessoas')]
#[OA\Response(response: 401, description: 'Não autenticado')]
#[OA\Response(response: 403, description: 'Sem permissão')]
public function listar(ListPessoaRequest $request): ResponseInterface
```

Repetir o padrão (atributo `#[OA\Post]`/`#[OA\Put]`/`#[OA\Patch]`/`#[OA\Delete]`/`#[OA\Get]` + `#[OA\Response]` pros status já mapeados no spec) em `criar`, `atualizar`, `atualizarParcial`, `buscar`, `excluir`, e nas duas rotas de `AuthController`.

- [ ] **Step 3: Gerar o JSON e conferir que as rotas aparecem**

```bash
php bin/hyperf.php gen:swagger # ou o comando real publicado pelo pacote — confirmar nome no Step 1
cat storage/swagger/http.json | grep -o '"/pessoas[^"]*"'
```

Expected: aparecem `/pessoas`, `/pessoas/{id}`, `/auth/login`, `/auth/logout` no JSON gerado.

- [ ] **Step 4: Rodar `composer test`**

Run: `composer test`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Controller config/autoload/swagger.php storage/swagger/http.json
git commit -m "feat: adiciona anotacoes OpenAPI nas rotas de pessoa e auth"
```

---

## Task 16: Container de documentação (docker-compose)

**Files:**
- Modify: `docker-compose.yml`

**Interfaces:**
- Consumes: `storage/swagger/http.json` (Task 15)

- [ ] **Step 1: Adicionar o serviço de documentação, montando o JSON gerado**

```yaml
  lumina-docs:
    image: techsuperior/web-swagger
    container_name: lumina-docs
    working_dir: /swagger
    restart: unless-stopped
    depends_on:
      - lumina
    command: open-swagger-ui ./http.json
    ports:
      - "8082:3355" # 8081 já é usado pelo lms-api-doc do legado no mesmo uni_sup_network — conferir antes de subir
    volumes:
      - ./storage/swagger:/swagger
    deploy:
      resources:
        limits:
          memory: 64M
        reservations:
          memory: 32M
```

- [ ] **Step 2: Subir e validar manualmente**

```bash
docker compose up -d lumina-docs
curl -s http://localhost:8082 | grep -i swagger
```

Expected: HTML da Swagger UI responde, mostrando as rotas de `/pessoas` e `/auth`.

- [ ] **Step 3: Commit**

```bash
git add docker-compose.yml
git commit -m "feat: adiciona container de documentacao (swagger ui)"
```

---

## Task 17: Rodar `composer test` completo e revisão final

**Files:** nenhum arquivo novo — task de verificação.

- [ ] **Step 1: Rodar a suíte completa**

Run: `composer test`
Expected: PASS — `cs-check`, `php-unit`, `analyse` todos verdes.

- [ ] **Step 2: Se `cs-check` falhar, rodar `cs-fix` e revisar o diff**

```bash
composer cs-fix
git diff --stat
```

- [ ] **Step 3: Conferir manualmente que o teste órfão realmente sumiu e nada mais referencia `AclRouteOptions`/classes deletadas**

```bash
grep -rn "AclRouteOptions\|PessoaController@" test/ app/ config/ 2>/dev/null
```

Expected: nenhuma referência a `AclRouteOptions` (a classe nunca existiu neste plano — só o `AclMiddleware` com opção `acl` na rota).

- [ ] **Step 4: Commit final se `cs-fix` alterou algo**

```bash
git add -A
git commit -m "chore: cs-fix final apos implementacao completa"
```

---

## Self-Review

**1. Cobertura do spec:** ORM (Task 1/5/6/7), banco/schema compartilhado (Tasks 4/5/6/7), estrutura de pastas (todas as tasks seguem a convenção), auth token opaco + cascata de senha (Tasks 8/9), ACL Redis + middleware (Tasks 10/11), CRUD completo incl. PATCH/DELETE/soft-delete (Tasks 6/7/12/13/14), paginação/filtro (Tasks 7/13/14), OpenAPI (Task 15), container de docs (Task 16), exception handlers (Task 3), remoção do teste órfão (Task 1), `composer test` passando (Task 17). Sem lacuna encontrada.

**2. Placeholders:** nenhum. O vínculo pessoa↔perfil (`lgin_pessoa_perfil` + `unim_coligada`) foi investigado e validado contra dado real (múltiplos perfis simultâneos confirmados) antes de fechar este plano — código da Task 8 já usa a query real, sem TODO.

**3. Consistência de tipos:** `PessoaRepositoryInterface` (Task 6, ampliada na Task 7) usada identicamente em `PessoaService` (Task 13); `AclService::isAllowed(int, string, string)` (Task 11) casa com a chamada em `AclMiddleware` (mesma task); `IdentidadeContext::cdCliente()`/`cdPerfil()`/`cdPessoa()` (Task 8) usados com esses nomes exatos em `AclMiddleware` (Task 11) e `PessoaController` (Task 14).

---

Plano completo e salvo em `docs/superpowers/plans/2026-07-25-migracao-cadastro-pessoa.md`. Duas opções de execução:

**1. Subagent-Driven (recomendado)** — um subagente fresco por task, revisão entre tasks, iteração rápida.

**2. Inline Execution** — execução em lote nesta sessão via executing-plans, com checkpoints de revisão.

Qual abordagem?
