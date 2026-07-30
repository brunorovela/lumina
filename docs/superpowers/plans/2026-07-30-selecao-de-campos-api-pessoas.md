# Seleção de campos na API de pessoas (sparse fieldsets) — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que o cliente escolha quais campos `GET /pessoas` e `GET /pessoas/{id}` devolvem, com a seleção chegando ao SQL (select parcial + eager load condicional), não apenas filtrando a saída.

**Architecture:** Um mapa declarativo (`MapaDeCamposPessoa`) é a única fonte de verdade do schema exposto. Dele derivam as três necessidades: validação da query string, o `select()`/`with()` do Repository e o recorte do Resource. Duas classes genéricas (`Campo`, `SelecaoDeCampos`) fazem o trabalho; o mapa é o único arquivo específico de pessoa.

**Tech Stack:** PHP 8.4, Hyperf (Swoole), `hyperf/database` (Eloquent-like), `hyperf/validation`, PHPUnit 11 via `co-phpunit`, PHPStan nível 10, php-cs-fixer.

**Spec:** `docs/superpowers/specs/2026-07-30-selecao-de-campos-api-pessoas-design.md`

## Global Constraints

- **Todo comando de shell passa por `rtk`.** Wrapper quando existir (`rtk git`, `rtk composer`, `rtk grep`), `rtk proxy <cmd>` quando não existir. Comandos dentro do container: `docker exec lumina composer ...` — prefixe a chamada externa com `rtk proxy` quando não houver wrapper.
- **`composer test` tem de ficar verde ao fim de cada task**: `cs-check` sem arquivo a corrigir, PHPUnit sem erro nem falha, `analyse` com `[OK] No errors`. Hoje: 70 testes, 295 asserções, 66 "risky" (artefato do harness do Hyperf, não regressão — ignore).
- **PHPStan nível 10, zero erro.** O nível vive em `phpstan.neon.dist`; o script `analyse` não passa `-l`. Anote tipo de array (`array<string, mixed>`, `string[]`) em todo método novo.
- **Header de licença Hyperf obrigatório em todo arquivo novo.** Não escreva à mão: rode `docker exec lumina composer cs-fix` e ele insere.
- **Nomes de identificador em português** (`SelecaoDeCampos`, `colunas()`, `relacoes()`), seguindo o resto de `app/`. Termos técnicos consolidados ficam em inglês.
- **Testes de pessoa usam `HyperfTest\Support\TenantDeTeste`** — nunca `cd_cliente` ou `cd_perfil` fixos. `cd_cliente = 1` e `cd_perfil = 1` não existem no banco.
- **Nunca interpole nome de coluna vindo do cliente no SQL.** O cliente escolhe chaves do mapa; a coluna sai do `Campo`, escrita no código.
- **`ds_senha` não entra no mapa em nenhuma hipótese.**
- Rodar teste individual: `docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php <caminho> --filter <nome>`

---

## Estrutura de arquivos

| Arquivo | Responsabilidade | Task |
|---|---|---|
| `app/Support/Campos/Campo.php` (criar) | Objeto de valor: coluna direta, ou (relação, coluna, FK), mais a flag `noPadrao` | 1 |
| `app/Support/Campos/SelecaoDeCampos.php` (criar) | Interpreta a string crua contra um mapa; deriva `campos()`, `colunas()`, `relacoes()` | 1 |
| `app/Resource/Pessoa/MapaDeCamposPessoa.php` (criar) | Declara o schema exposto de pessoa e encapsula a chave local | 2 |
| `app/Request/Pessoa/Concerns/ValidaCamposDePessoa.php` (criar) | Trait com a checagem de `fields` compartilhada entre os dois Requests de leitura | 2 |
| `app/Request/Pessoa/ListPessoaRequest.php` (modificar) | Aceita e valida `fields` | 2 |
| `app/Request/Pessoa/BuscarPessoaRequest.php` (criar) | Idem para o item — hoje a rota não tem FormRequest | 6 |
| `app/Resource/Pessoa/PessoaResource.php` (modificar) | Recorta a saída sem nunca tocar relação não carregada | 3 |
| `app/Repository/Pessoa/PessoaRepository.php` (modificar) | `select()` parcial e `with()` condicional | 4 |
| `app/Repository/Pessoa/PessoaRepositoryInterface.php` (modificar) | Assinaturas acompanham a implementação | 4 |
| `app/Service/Pessoa/PessoaService.php` (modificar) | Repassa a seleção sem decidir nada sobre ela | 5, 6 |
| `app/Controller/Pessoa/PessoaController.php` (modificar) | Monta a seleção; define o default por endpoint; Swagger | 5, 6 |
| `config/routes.php` (modificar) | `ValidationMiddleware` em `GET /pessoas/{id}` | 6 |

Testes:

| Arquivo | Task |
|---|---|
| `test/Cases/Support/Campos/SelecaoDeCamposTest.php` (criar) | 1 |
| `test/Cases/Resource/Pessoa/PessoaResourceTest.php` (criar) | 3 |
| `test/Cases/Repository/Pessoa/PessoaRepositoryTest.php` (modificar) | 4 |
| `test/Cases/Controller/Pessoa/PessoaControllerTest.php` (modificar) | 2, 5, 6 |

---

### Task 1: Objetos genéricos `Campo` e `SelecaoDeCampos`

Núcleo puro, sem banco e sem nada de pessoa. É onde vive toda a lógica de interpretar `fields`.

**Files:**
- Create: `app/Support/Campos/Campo.php`
- Create: `app/Support/Campos/SelecaoDeCampos.php`
- Test: `test/Cases/Support/Campos/SelecaoDeCamposTest.php`

**Interfaces:**
- Consumes: nada.
- Produces:
  - `Campo::coluna(string $coluna, bool $noPadrao = false): Campo`
  - `Campo::relacao(string $relacao, string $coluna, string $chaveEstrangeira, bool $noPadrao = false): Campo`
  - `Campo->coluna: string`, `Campo->relacao: ?string`, `Campo->chaveEstrangeira: ?string`, `Campo->noPadrao: bool`, `Campo->ehDeRelacao(): bool`
  - `SelecaoDeCampos::de(?string $fields, array $mapa, string $chaveLocal, bool $padraoEhTudo = false): SelecaoDeCampos`
  - `SelecaoDeCampos::invalidos(?string $fields, array $mapa): string[]`
  - `SelecaoDeCampos->campos(): string[]` — chaves do mapa a devolver na resposta
  - `SelecaoDeCampos->colunas(): string[]` — colunas do `select()` do pai
  - `SelecaoDeCampos->relacoes(): array<string, string[]>` — relação => colunas, FK incluída
  - `SelecaoDeCampos->tudo(): bool`, `->inclui(string $campo): bool`, `->campo(string $chave): Campo`

- [ ] **Step 1: Escrever o teste que falha**

Criar `test/Cases/Support/Campos/SelecaoDeCamposTest.php`:

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Support\Campos;

use App\Support\Campos\Campo;
use App\Support\Campos\SelecaoDeCampos;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SelecaoDeCamposTest extends TestCase
{
    /**
     * @return array<string, Campo>
     */
    private function mapa(): array
    {
        return [
            'cd_pessoa' => Campo::coluna('cd_pessoa', noPadrao: true),
            'ds_nome' => Campo::coluna('ds_nome', noPadrao: true),
            'cd_cliente' => Campo::coluna('cd_cliente'),
            'fisica.ds_cpf' => Campo::relacao('fisica', 'ds_cpf', 'cd_pessoa'),
            'fisica.ds_nome_oficial' => Campo::relacao('fisica', 'ds_nome_oficial', 'cd_pessoa'),
        ];
    }

    private function selecao(?string $fields, bool $padraoEhTudo = false): SelecaoDeCampos
    {
        return SelecaoDeCampos::de($fields, $this->mapa(), 'cd_pessoa', $padraoEhTudo);
    }

    public function testSemFieldsUsaSomenteOsCamposMarcadosComoPadrao()
    {
        $selecao = $this->selecao(null);

        $this->assertEqualsCanonicalizing(['cd_pessoa', 'ds_nome'], $selecao->campos());
        $this->assertEqualsCanonicalizing(['cd_pessoa', 'ds_nome'], $selecao->colunas());
        $this->assertSame([], $selecao->relacoes());
        $this->assertFalse($selecao->tudo());
    }

    public function testSemFieldsComPadraoEhTudoDevolveOMapaInteiro()
    {
        $selecao = $this->selecao(null, padraoEhTudo: true);

        $this->assertTrue($selecao->tudo());
        $this->assertEqualsCanonicalizing(array_keys($this->mapa()), $selecao->campos());
    }

    public function testCuringaAsteriscoDevolveTudoEVenceOsOutrosTokens()
    {
        $this->assertTrue($this->selecao('*')->tudo());
        $this->assertTrue($this->selecao('ds_nome,*')->tudo());
    }

    public function testRelacaoPedidaInjetaChaveEstrangeiraEChaveLocal()
    {
        $selecao = $this->selecao('ds_nome,fisica.ds_cpf');

        // A resposta respeita fields ao pé da letra: cd_pessoa NÃO entra em campos()...
        $this->assertEqualsCanonicalizing(['ds_nome', 'fisica.ds_cpf'], $selecao->campos());
        // ...mas entra no SELECT, senão o eager load não tem como casar pai e filho.
        $this->assertEqualsCanonicalizing(['ds_nome', 'cd_pessoa'], $selecao->colunas());
        $this->assertSame(['fisica' => ['cd_pessoa', 'ds_cpf']], $selecao->relacoes());
    }

    public function testSemRelacaoNaoInjetaChaveLocal()
    {
        $this->assertSame(['ds_nome'], $this->selecao('ds_nome')->colunas());
    }

    public function testCuringaDeRelacaoExpandePeloMapa()
    {
        $selecao = $this->selecao('fisica.*');

        $this->assertEqualsCanonicalizing(
            ['fisica.ds_cpf', 'fisica.ds_nome_oficial'],
            $selecao->campos()
        );
        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'ds_cpf', 'ds_nome_oficial'],
            $selecao->relacoes()['fisica']
        );
    }

    public function testAparaEspacosEDeduplicaTokens()
    {
        $selecao = $this->selecao(' ds_nome , ds_nome ,cd_cliente ');

        $this->assertEqualsCanonicalizing(['ds_nome', 'cd_cliente'], $selecao->campos());
    }

    public function testStringVaziaCaiNoPadrao()
    {
        $this->assertEqualsCanonicalizing(['cd_pessoa', 'ds_nome'], $this->selecao('')->campos());
    }

    public function testInvalidosListaSomenteOsTokensForaDoMapa()
    {
        $invalidos = SelecaoDeCampos::invalidos('ds_nome,ds_nomee,ds_senha,fisica.*,*', $this->mapa());

        $this->assertEqualsCanonicalizing(['ds_nomee', 'ds_senha'], $invalidos);
    }

    public function testCuringaDeRelacaoInexistenteEhInvalido()
    {
        $this->assertSame(['contatos.*'], SelecaoDeCampos::invalidos('contatos.*', $this->mapa()));
    }

    public function testIncluiEhVerdadeiroSomenteParaCampoSelecionado()
    {
        $selecao = $this->selecao('ds_nome');

        $this->assertTrue($selecao->inclui('ds_nome'));
        $this->assertFalse($selecao->inclui('cd_cliente'));
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

```bash
rtk proxy docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Support/Campos/SelecaoDeCamposTest.php
```

Esperado: FAIL — `Class "App\Support\Campos\Campo" not found`.

- [ ] **Step 3: Implementar `Campo`**

Criar `app/Support/Campos/Campo.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Campos;

/**
 * Um campo exposto pela API e para onde ele aponta no banco.
 *
 * Coluna direta: Campo::coluna('ds_nome').
 * Coluna de relação: Campo::relacao('fisica', 'ds_cpf', 'cd_pessoa') — a chave estrangeira
 * é obrigatória porque sem ela o eager load parcial não casa pai e filho, e o Eloquent
 * falha em silêncio nesse caso (a relação vem null, sem erro).
 */
final class Campo
{
    private function __construct(
        public readonly string $coluna,
        public readonly ?string $relacao,
        public readonly ?string $chaveEstrangeira,
        public readonly bool $noPadrao,
    ) {
    }

    public static function coluna(string $coluna, bool $noPadrao = false): self
    {
        return new self($coluna, null, null, $noPadrao);
    }

    public static function relacao(
        string $relacao,
        string $coluna,
        string $chaveEstrangeira,
        bool $noPadrao = false
    ): self {
        return new self($coluna, $relacao, $chaveEstrangeira, $noPadrao);
    }

    public function ehDeRelacao(): bool
    {
        return $this->relacao !== null;
    }
}
```

- [ ] **Step 4: Implementar `SelecaoDeCampos`**

Criar `app/Support/Campos/SelecaoDeCampos.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Campos;

use LogicException;

/**
 * Traduz o parâmetro `fields` da query string em três coisas diferentes: o que devolver na
 * resposta (campos()), o que pedir no SELECT do pai (colunas()) e quais relações carregar
 * com quais colunas (relacoes()).
 *
 * colunas() e relacoes() podem conter mais do que campos(): a chave local e a chave
 * estrangeira entram no SQL por necessidade do eager load e são removidas da resposta,
 * para o contrato não vazar detalhe do ORM.
 */
final class SelecaoDeCampos
{
    /**
     * @param array<string, Campo> $mapa
     * @param string[]             $campos
     */
    private function __construct(
        private array $mapa,
        private array $campos,
        private string $chaveLocal,
        private bool $tudo,
    ) {
    }

    /**
     * @param array<string, Campo> $mapa
     * @param bool $padraoEhTudo quando `fields` está ausente, define se o default é o mapa
     *                           inteiro (item) ou só os campos marcados noPadrao (lista)
     */
    public static function de(
        ?string $fields,
        array $mapa,
        string $chaveLocal,
        bool $padraoEhTudo = false
    ): self {
        $tokens = self::tokens($fields);

        if ($tokens === []) {
            return $padraoEhTudo
                ? new self($mapa, array_keys($mapa), $chaveLocal, true)
                : new self($mapa, self::doPadrao($mapa), $chaveLocal, false);
        }

        if (in_array('*', $tokens, true)) {
            return new self($mapa, array_keys($mapa), $chaveLocal, true);
        }

        $campos = [];

        foreach ($tokens as $token) {
            foreach (self::expandir($token, $mapa) as $campo) {
                $campos[$campo] = true;
            }
        }

        return new self($mapa, array_keys($campos), $chaveLocal, false);
    }

    /**
     * Tokens que não existem no mapa. Curinga válido não é reportado; curinga de relação
     * inexistente é.
     *
     * @param array<string, Campo> $mapa
     *
     * @return string[]
     */
    public static function invalidos(?string $fields, array $mapa): array
    {
        $invalidos = [];

        foreach (self::tokens($fields) as $token) {
            if ($token === '*' || isset($mapa[$token]) || self::expandir($token, $mapa) !== []) {
                continue;
            }

            $invalidos[$token] = true;
        }

        return array_keys($invalidos);
    }

    /**
     * @return string[]
     */
    public function campos(): array
    {
        return $this->campos;
    }

    /**
     * @return string[]
     */
    public function colunas(): array
    {
        $colunas = [];

        foreach ($this->campos as $campo) {
            $definicao = $this->campo($campo);

            if (! $definicao->ehDeRelacao()) {
                $colunas[$definicao->coluna] = true;
            }
        }

        // Relação pedida exige a chave local no SELECT do pai: sem ela o Eloquent não tem
        // com o que montar o `where <fk> in (...)` do eager load.
        if ($this->relacoes() !== []) {
            $colunas[$this->chaveLocal] = true;
        }

        return array_keys($colunas);
    }

    /**
     * @return array<string, string[]>
     */
    public function relacoes(): array
    {
        $relacoes = [];

        foreach ($this->campos as $campo) {
            $definicao = $this->campo($campo);

            if (! $definicao->ehDeRelacao() || $definicao->relacao === null || $definicao->chaveEstrangeira === null) {
                continue;
            }

            $relacoes[$definicao->relacao][$definicao->chaveEstrangeira] = true;
            $relacoes[$definicao->relacao][$definicao->coluna] = true;
        }

        return array_map(static fn (array $colunas): array => array_keys($colunas), $relacoes);
    }

    public function tudo(): bool
    {
        return $this->tudo;
    }

    public function inclui(string $campo): bool
    {
        return in_array($campo, $this->campos, true);
    }

    public function campo(string $chave): Campo
    {
        if (! isset($this->mapa[$chave])) {
            throw new LogicException("Campo '{$chave}' não existe no mapa.");
        }

        return $this->mapa[$chave];
    }

    /**
     * @return string[]
     */
    private static function tokens(?string $fields): array
    {
        if ($fields === null) {
            return [];
        }

        $tokens = array_map('trim', explode(',', $fields));

        return array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
    }

    /**
     * @param array<string, Campo> $mapa
     *
     * @return string[]
     */
    private static function expandir(string $token, array $mapa): array
    {
        if (isset($mapa[$token])) {
            return [$token];
        }

        if (! str_ends_with($token, '.*')) {
            return [];
        }

        // 'fisica.*' -> prefixo 'fisica.'
        $prefixo = substr($token, 0, -1);

        return array_values(array_filter(
            array_keys($mapa),
            static fn (string $chave): bool => str_starts_with($chave, $prefixo)
        ));
    }

    /**
     * @param array<string, Campo> $mapa
     *
     * @return string[]
     */
    private static function doPadrao(array $mapa): array
    {
        $padrao = [];

        foreach ($mapa as $chave => $campo) {
            if ($campo->noPadrao) {
                $padrao[] = $chave;
            }
        }

        return $padrao;
    }
}
```

- [ ] **Step 5: Rodar o teste e confirmar que passa**

```bash
rtk proxy docker exec lumina composer cs-fix
rtk proxy docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Support/Campos/SelecaoDeCamposTest.php
```

Esperado: PASS, 11 testes.

- [ ] **Step 6: Rodar `composer test` inteiro**

```bash
rtk proxy docker exec lumina composer test
```

Esperado: `cs-check` sem arquivo a corrigir, PHPUnit sem erro nem falha, `analyse` com `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
rtk proxy git add app/Support/Campos test/Cases/Support/Campos
rtk proxy git commit -m "feat: Campo e SelecaoDeCampos, nucleo de sparse fieldsets"
```

---

### Task 2: Mapa de pessoa e validação de `fields` na lista

Traz o mapa e faz a query string ser rejeitada com 422 antes de qualquer coisa tocar o banco.

**Files:**
- Create: `app/Resource/Pessoa/MapaDeCamposPessoa.php`
- Create: `app/Request/Pessoa/Concerns/ValidaCamposDePessoa.php`
- Modify: `app/Request/Pessoa/ListPessoaRequest.php:24-40`
- Test: `test/Cases/Controller/Pessoa/PessoaControllerTest.php` (acrescentar casos)

**Interfaces:**
- Consumes: `Campo`, `SelecaoDeCampos` da Task 1.
- Produces:
  - `MapaDeCamposPessoa::CHAVE_LOCAL` = `'cd_pessoa'`
  - `MapaDeCamposPessoa::mapa(): array<string, Campo>`
  - `MapaDeCamposPessoa::selecao(?string $fields, bool $padraoEhTudo = false): SelecaoDeCampos`
  - `MapaDeCamposPessoa::invalidos(?string $fields): string[]`
  - trait `ValidaCamposDePessoa` com `protected function validarCampos(ValidatorInterface $validator): void`

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar em `test/Cases/Controller/Pessoa/PessoaControllerTest.php` (a classe já tem `$this->token`, `$this->cdPerfil` e `headers()` no `setUp`):

```php
    public function testFieldsComCampoInexistenteRetorna422()
    {
        $resposta = $this->get('/pessoas?fields=ds_nome,ds_nomee', [], $this->headers());

        $resposta->assertStatus(422);
        $this->assertContains('Campo não permitido: ds_nomee.', $resposta->json('errors.fields'));
    }

    /**
     * ds_senha não está no mapa, então recebe exatamente a mesma mensagem de um typo — a
     * resposta não pode confirmar que a coluna existe.
     */
    public function testFieldsComDsSenhaRetorna422ComAMesmaMensagemDeUmTypo()
    {
        $resposta = $this->get('/pessoas?fields=ds_senha', [], $this->headers());

        $resposta->assertStatus(422);
        $this->assertSame(['Campo não permitido: ds_senha.'], $resposta->json('errors.fields'));
    }

    public function testFieldsValidoNaoCaiNa422()
    {
        $this->get('/pessoas?fields=ds_nome,fisica.ds_cpf', [], $this->headers())->assertStatus(200);
        $this->get('/pessoas?fields=*', [], $this->headers())->assertStatus(200);
        $this->get('/pessoas?fields=fisica.*', [], $this->headers())->assertStatus(200);
        $this->get('/pessoas?fields=', [], $this->headers())->assertStatus(200);
    }
```

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
rtk proxy docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Controller/Pessoa/PessoaControllerTest.php --filter testFieldsCom
```

Esperado: FAIL — as requisições devolvem 200 em vez de 422, porque `fields` hoje é simplesmente ignorado.

- [ ] **Step 3: Implementar o mapa**

Criar `app/Resource/Pessoa/MapaDeCamposPessoa.php`:

```php
<?php

declare(strict_types=1);

namespace App\Resource\Pessoa;

use App\Support\Campos\Campo;
use App\Support\Campos\SelecaoDeCampos;

/**
 * Fonte de verdade do schema exposto de pessoa. Um arquivo responde três perguntas: o que
 * a API expõe, para onde cada campo aponta no banco, e o que entra no default enxuto da
 * listagem (noPadrao: true).
 *
 * ds_senha NÃO está aqui, e é por isso que não existe blacklist a manter: o que não está
 * no mapa é inalcançável por construção.
 */
final class MapaDeCamposPessoa
{
    public const CHAVE_LOCAL = 'cd_pessoa';

    /**
     * @return array<string, Campo>
     */
    public static function mapa(): array
    {
        return [
            'cd_pessoa' => Campo::coluna('cd_pessoa', noPadrao: true),
            'ds_nome' => Campo::coluna('ds_nome', noPadrao: true),
            'ds_login' => Campo::coluna('ds_login', noPadrao: true),
            'sn_pessoa_juridica' => Campo::coluna('sn_pessoa_juridica', noPadrao: true),
            'cd_cliente' => Campo::coluna('cd_cliente'),
            'fisica.ds_nome_oficial' => Campo::relacao('fisica', 'ds_nome_oficial', self::CHAVE_LOCAL),
            'fisica.ds_cpf' => Campo::relacao('fisica', 'ds_cpf', self::CHAVE_LOCAL),
            'juridica.ds_cnpj' => Campo::relacao('juridica', 'ds_cnpj', self::CHAVE_LOCAL),
            'juridica.ds_nome_fantasia' => Campo::relacao('juridica', 'ds_nome_fantasia', self::CHAVE_LOCAL),
        ];
    }

    public static function selecao(?string $fields, bool $padraoEhTudo = false): SelecaoDeCampos
    {
        return SelecaoDeCampos::de($fields, self::mapa(), self::CHAVE_LOCAL, $padraoEhTudo);
    }

    /**
     * @return string[]
     */
    public static function invalidos(?string $fields): array
    {
        return SelecaoDeCampos::invalidos($fields, self::mapa());
    }
}
```

- [ ] **Step 4: Implementar a trait de validação**

Criar `app/Request/Pessoa/Concerns/ValidaCamposDePessoa.php`:

```php
<?php

declare(strict_types=1);

namespace App\Request\Pessoa\Concerns;

use App\Resource\Pessoa\MapaDeCamposPessoa;
use Hyperf\Contract\ValidatorInterface;

/**
 * Checagem de `fields` compartilhada entre ListPessoaRequest e BuscarPessoaRequest — a
 * regra é a mesma nos dois, e duplicá-la deixaria os endpoints divergirem com o tempo.
 */
trait ValidaCamposDePessoa
{
    protected function validarCampos(ValidatorInterface $validator): void
    {
        $fields = $this->input('fields');

        if ($fields !== null && ! is_string($fields)) {
            $validator->errors()->add(
                'fields',
                'O parâmetro fields precisa ser uma lista de campos separada por vírgula.'
            );

            return;
        }

        foreach (MapaDeCamposPessoa::invalidos($fields) as $campo) {
            $validator->errors()->add('fields', "Campo não permitido: {$campo}.");
        }
    }
}
```

- [ ] **Step 5: Ligar a trait no `ListPessoaRequest`**

Modificar `app/Request/Pessoa/ListPessoaRequest.php` — acrescentar `fields` às regras e o `withValidator`:

```php
namespace App\Request\Pessoa;

use App\Request\Pessoa\Concerns\ValidaCamposDePessoa;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\Validation\Request\FormRequest;

class ListPessoaRequest extends FormRequest
{
    use ValidaCamposDePessoa;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1',
            'nome' => 'sometimes|string',
            'tipo_pessoa' => 'sometimes|in:fisica,juridica',
            'fields' => 'sometimes|string',
        ];
    }

    public function withValidator(ValidatorInterface $validator): void
    {
        $this->validarCampos($validator);
    }
}
```

- [ ] **Step 6: Rodar os testes novos**

```bash
rtk proxy docker exec lumina composer cs-fix
rtk proxy docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Controller/Pessoa/PessoaControllerTest.php
```

Esperado: PASS, incluindo os três testes novos.

- [ ] **Step 7: Rodar `composer test` inteiro**

```bash
rtk proxy docker exec lumina composer test
```

Esperado: tudo verde.

- [ ] **Step 8: Commit**

```bash
rtk proxy git add app/Resource/Pessoa/MapaDeCamposPessoa.php app/Request/Pessoa test/Cases/Controller/Pessoa/PessoaControllerTest.php
rtk proxy git commit -m "feat: mapa de campos de pessoa e validacao 422 de fields na listagem"
```

---

### Task 3: `PessoaResource` recorta a saída sem disparar lazy load

O ponto delicado: se o Resource montar tudo e filtrar depois, `$pessoa->fisica` numa relação não carregada dispara **uma query por linha** (N+1) — trocaria o problema de banda por um problema de banco pior que o original.

**Files:**
- Modify: `app/Resource/Pessoa/PessoaResource.php:17-56`
- Test: `test/Cases/Resource/Pessoa/PessoaResourceTest.php` (criar)

**Interfaces:**
- Consumes: `SelecaoDeCampos` (Task 1), `MapaDeCamposPessoa` (Task 2).
- Produces:
  - `PessoaResource::um(UnimPessoa $pessoa, ?SelecaoDeCampos $selecao = null): array<string, mixed>` — `null` significa contrato completo
  - `PessoaResource::muitos(iterable $pessoas, ?SelecaoDeCampos $selecao = null): array<int, array<string, mixed>>`

- [ ] **Step 1: Escrever o teste que falha**

Criar `test/Cases/Resource/Pessoa/PessoaResourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Resource\Pessoa;

use App\Model\Pessoa\UnimPessoa;
use App\Model\Pessoa\UnimPessoaFisica;
use App\Resource\Pessoa\MapaDeCamposPessoa;
use App\Resource\Pessoa\PessoaResource;
use PHPUnit\Framework\TestCase;

/**
 * Sem banco de propósito: os models são montados em memória com setRawAttributes(), o que
 * permite afirmar que o Resource NÃO toca relação não carregada (tocar dispararia uma
 * query, e aqui não há conexão para atender).
 *
 * @internal
 * @coversNothing
 */
class PessoaResourceTest extends TestCase
{
    private function pessoa(): UnimPessoa
    {
        $pessoa = new UnimPessoa();
        $pessoa->setRawAttributes([
            'cd_pessoa' => 7,
            'cd_cliente' => 20,
            'ds_nome' => 'Ana',
            'ds_login' => 'ana.teste',
            'sn_pessoa_juridica' => 0,
        ], true);

        return $pessoa;
    }

    public function testSelecaoNulaDevolveOContratoCompleto()
    {
        $pessoa = $this->pessoa();
        $fisica = new UnimPessoaFisica();
        $fisica->setRawAttributes(['cd_pessoa' => 7, 'ds_nome_oficial' => 'Ana Oficial', 'ds_cpf' => '123'], true);
        $pessoa->setRelation('fisica', $fisica);
        $pessoa->setRelation('juridica', null);

        $saida = PessoaResource::um($pessoa);

        $this->assertEquals([
            'cd_pessoa' => 7,
            'cd_cliente' => 20,
            'ds_nome' => 'Ana',
            'ds_login' => 'ana.teste',
            'sn_pessoa_juridica' => false,
            'fisica' => ['ds_nome_oficial' => 'Ana Oficial', 'ds_cpf' => '123'],
            'juridica' => null,
        ], $saida);
    }

    public function testRecortaExatamenteOsCamposPedidos()
    {
        $saida = PessoaResource::um($this->pessoa(), MapaDeCamposPessoa::selecao('ds_nome'));

        $this->assertSame(['ds_nome' => 'Ana'], $saida);
    }

    /**
     * cd_pessoa entra no SELECT quando há relação pedida, mas não pode aparecer na
     * resposta: o contrato respeita fields ao pé da letra.
     */
    public function testChaveDeJoinNaoVazaParaAResposta()
    {
        $pessoa = $this->pessoa();
        $fisica = new UnimPessoaFisica();
        $fisica->setRawAttributes(['cd_pessoa' => 7, 'ds_cpf' => '123'], true);
        $pessoa->setRelation('fisica', $fisica);

        $saida = PessoaResource::um($pessoa, MapaDeCamposPessoa::selecao('ds_nome,fisica.ds_cpf'));

        $this->assertSame(['ds_nome' => 'Ana', 'fisica' => ['ds_cpf' => '123']], $saida);
        $this->assertArrayNotHasKey('cd_pessoa', $saida);
    }

    public function testRelacaoPedidaEmPessoaDoOutroTipoVemNulaComAChavePresente()
    {
        $pessoa = $this->pessoa();
        $pessoa->setRelation('fisica', null);

        $saida = PessoaResource::um($pessoa, MapaDeCamposPessoa::selecao('ds_nome,fisica.ds_cpf'));

        $this->assertArrayHasKey('fisica', $saida);
        $this->assertNull($saida['fisica']);
    }

    /**
     * O caso que impede o N+1: relação NÃO pedida não pode ser tocada, senão o Eloquent
     * faz lazy load de uma query por linha da listagem.
     */
    public function testNaoTocaRelacaoQueNaoFoiPedida()
    {
        $pessoa = $this->pessoa();

        PessoaResource::um($pessoa, MapaDeCamposPessoa::selecao('ds_nome'));

        $this->assertFalse($pessoa->relationLoaded('fisica'));
        $this->assertFalse($pessoa->relationLoaded('juridica'));
    }

    public function testMuitosAplicaAMesmaSelecaoEmTodosOsItens()
    {
        $saida = PessoaResource::muitos([$this->pessoa(), $this->pessoa()], MapaDeCamposPessoa::selecao('ds_nome'));

        $this->assertSame([['ds_nome' => 'Ana'], ['ds_nome' => 'Ana']], $saida);
    }
}
```

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
rtk proxy docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Resource/Pessoa/PessoaResourceTest.php
```

Esperado: FAIL — `PessoaResource::um()` ainda não aceita o segundo parâmetro (`ArgumentCountError` / retorno completo onde se espera recorte).

- [ ] **Step 3: Reescrever o `PessoaResource`**

Substituir o corpo de `app/Resource/Pessoa/PessoaResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Resource\Pessoa;

use App\Model\Pessoa\UnimPessoa;
use App\Support\Campos\SelecaoDeCampos;
use Hyperf\Database\Model\Model;

class PessoaResource
{
    /**
     * @param null|SelecaoDeCampos $selecao null significa contrato completo — é o que
     *                                      POST/PUT/PATCH usam, porque resposta de escrita
     *                                      filtrada esconderia o que o servidor gravou
     *
     * @return array<string, mixed>
     */
    public static function um(UnimPessoa $pessoa, ?SelecaoDeCampos $selecao = null): array
    {
        $selecao ??= MapaDeCamposPessoa::selecao(null, padraoEhTudo: true);

        $saida = [];

        foreach ($selecao->campos() as $chave) {
            $campo = $selecao->campo($chave);

            if (! $campo->ehDeRelacao()) {
                $saida[$chave] = $pessoa->getAttribute($campo->coluna);

                continue;
            }

            $relacao = (string) $campo->relacao;

            // A chave existe sempre que foi pedida; o valor é que pode ser nulo (pessoa do
            // outro tipo). Isso mantém a forma da resposta estável para o cliente.
            if (! array_key_exists($relacao, $saida)) {
                $saida[$relacao] = null;
            }

            // relationLoaded() antes de getRelation(): tocar uma relação não carregada
            // dispararia lazy load, uma query por linha da listagem (N+1).
            $filho = $pessoa->relationLoaded($relacao) ? $pessoa->getRelation($relacao) : null;

            if (! $filho instanceof Model) {
                continue;
            }

            $valores = is_array($saida[$relacao]) ? $saida[$relacao] : [];
            $valores[$campo->coluna] = $filho->getAttribute($campo->coluna);
            $saida[$relacao] = $valores;
        }

        return $saida;
    }

    /**
     * @param iterable<UnimPessoa> $pessoas
     *
     * @return array<int, array<string, mixed>>
     */
    public static function muitos(iterable $pessoas, ?SelecaoDeCampos $selecao = null): array
    {
        $itens = [];

        foreach ($pessoas as $pessoa) {
            $itens[] = self::um($pessoa, $selecao);
        }

        return $itens;
    }
}
```

- [ ] **Step 4: Rodar o teste e confirmar que passa**

```bash
rtk proxy docker exec lumina composer cs-fix
rtk proxy docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Resource/Pessoa/PessoaResourceTest.php
```

Esperado: PASS, 6 testes.

- [ ] **Step 5: Rodar `composer test` inteiro**

```bash
rtk proxy docker exec lumina composer test
```

Esperado: tudo verde. Os testes de controller e serviço continuam passando porque `um()` sem segundo argumento mantém o contrato completo.

- [ ] **Step 6: Commit**

```bash
rtk proxy git add app/Resource/Pessoa/PessoaResource.php test/Cases/Resource
rtk proxy git commit -m "feat: PessoaResource recorta saida sem tocar relacao nao carregada"
```

---

### Task 4: Repository com `select()` parcial e `with()` condicional

Aqui o ganho de banco acontece: 3 queries por página viram 1 quando ninguém pede relação.

**Files:**
- Modify: `app/Repository/Pessoa/PessoaRepository.php:127-156`
- Modify: `app/Repository/Pessoa/PessoaRepositoryInterface.php:45-52`
- Test: `test/Cases/Repository/Pessoa/PessoaRepositoryTest.php` (acrescentar casos)

**Interfaces:**
- Consumes: `SelecaoDeCampos` (Task 1), `MapaDeCamposPessoa` (Task 2).
- Produces:
  - `PessoaRepositoryInterface::listar(int $cdCliente, array $filtros, int $page, int $perPage, ?SelecaoDeCampos $selecao = null): array{itens: Collection<int, UnimPessoa>, total: int}`
  - `PessoaRepositoryInterface::buscarPorId(int $cdPessoa, int $cdCliente, ?SelecaoDeCampos $selecao = null): ?UnimPessoa`

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar em `test/Cases/Repository/Pessoa/PessoaRepositoryTest.php` (usar o `use HyperfTest\Support\TenantDeTeste;` que já existe no arquivo; acrescentar `use App\Resource\Pessoa\MapaDeCamposPessoa;`):

```php
    public function testListarSemSelecaoMantemOContratoCompleto()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $repository->criar(
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Selecao Padrao', 'ds_login' => 'teste.repo.selpadrao', 'ds_senha' => 'x', 'sn_pessoa_juridica' => false],
            ['ds_nome_oficial' => 'Selecao Padrao Oficial', 'ds_cpf' => '111'],
            null
        );

        $resultado = $repository->listar(TenantDeTeste::cdCliente(), [], 1, 20);
        $pessoa = $resultado['itens']->first();

        $this->assertNotNull($pessoa);
        $this->assertTrue($pessoa->relationLoaded('fisica'));
    }

    /**
     * O ganho de banco: sem relação pedida, o eager load não roda. relationLoaded() === false
     * prova isso de forma determinística, sem depender de contar queries (o pool de conexões
     * por corrotina torna query log intermitente).
     */
    public function testListarSemRelacaoPedidaNaoCarregaRelacaoNemColunaExtra()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $repository->criar(
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Selecao Enxuta', 'ds_login' => 'teste.repo.selenxuta', 'ds_senha' => 'x', 'sn_pessoa_juridica' => false],
            ['ds_nome_oficial' => 'Selecao Enxuta Oficial', 'ds_cpf' => '222'],
            null
        );

        $resultado = $repository->listar(
            TenantDeTeste::cdCliente(),
            [],
            1,
            20,
            MapaDeCamposPessoa::selecao('ds_nome')
        );

        $pessoa = $resultado['itens']->first();

        $this->assertNotNull($pessoa);
        $this->assertFalse($pessoa->relationLoaded('fisica'));
        $this->assertFalse($pessoa->relationLoaded('juridica'));
        $this->assertSame(['ds_nome'], array_keys($pessoa->getAttributes()));
    }

    /**
     * A armadilha do eager load parcial: sem a FK no select do filho, o Eloquent não casa
     * pai e filho e devolve null SEM erro. Se este teste falhar com fisica null, a FK
     * sumiu de SelecaoDeCampos::relacoes().
     */
    public function testEagerLoadParcialTrazAFkEPortantoCasaPaiEFilho()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $repository->criar(
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Selecao Com Fk', 'ds_login' => 'teste.repo.selfk', 'ds_senha' => 'x', 'sn_pessoa_juridica' => false],
            ['ds_nome_oficial' => 'Selecao Com Fk Oficial', 'ds_cpf' => '333'],
            null
        );

        $resultado = $repository->listar(
            TenantDeTeste::cdCliente(),
            [],
            1,
            20,
            MapaDeCamposPessoa::selecao('ds_nome,fisica.ds_cpf')
        );

        $pessoa = $resultado['itens']->first();

        $this->assertNotNull($pessoa);
        $this->assertTrue($pessoa->relationLoaded('fisica'));
        $this->assertNotNull($pessoa->fisica, 'fisica veio null: a chave estrangeira caiu do select do eager load.');
        $this->assertSame('333', $pessoa->fisica->ds_cpf);
        // cd_pessoa entra no select do pai porque há relação pedida
        $this->assertEqualsCanonicalizing(['cd_pessoa', 'ds_nome'], array_keys($pessoa->getAttributes()));
    }

    public function testBuscarPorIdRespeitaASelecao()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $pessoa = $repository->criar(
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Selecao Item', 'ds_login' => 'teste.repo.selitem', 'ds_senha' => 'x', 'sn_pessoa_juridica' => false],
            ['ds_nome_oficial' => 'Selecao Item Oficial', 'ds_cpf' => '444'],
            null
        );

        $encontrada = $repository->buscarPorId(
            $pessoa->cd_pessoa,
            TenantDeTeste::cdCliente(),
            MapaDeCamposPessoa::selecao('ds_nome')
        );

        $this->assertNotNull($encontrada);
        $this->assertSame(['ds_nome'], array_keys($encontrada->getAttributes()));
        $this->assertFalse($encontrada->relationLoaded('fisica'));
    }
```

**Limpeza: nada a fazer.** O `tearDown` da classe apaga por `ds_login like 'teste.repo.%'` (filhos antes do núcleo, por causa da FK `ON DELETE RESTRICT`), e os logins novos — `teste.repo.selpadrao`, `teste.repo.selenxuta`, `teste.repo.selfk`, `teste.repo.selitem` — caem todos nesse padrão.

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
rtk proxy docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Repository/Pessoa/PessoaRepositoryTest.php --filter "Selecao|EagerLoad|testBuscarPorIdRespeita"
```

Esperado: FAIL — `listar()` ainda não aceita o 5º parâmetro.

- [ ] **Step 3: Atualizar a interface**

Modificar `app/Repository/Pessoa/PessoaRepositoryInterface.php` — trocar as duas assinaturas de leitura e acrescentar o import:

```php
use App\Support\Campos\SelecaoDeCampos;
```

```php
    /**
     * @param null|SelecaoDeCampos $selecao null significa contrato completo
     */
    public function buscarPorId(int $cdPessoa, int $cdCliente, ?SelecaoDeCampos $selecao = null): ?UnimPessoa;

    /**
     * @param array<string, mixed> $filtros
     * @param null|SelecaoDeCampos $selecao null significa contrato completo
     *
     * @return array{itens: Collection<int, UnimPessoa>, total: int}
     */
    public function listar(int $cdCliente, array $filtros, int $page, int $perPage, ?SelecaoDeCampos $selecao = null): array;
```

- [ ] **Step 4: Implementar no Repository**

Modificar `app/Repository/Pessoa/PessoaRepository.php`. Acrescentar imports:

```php
use App\Resource\Pessoa\MapaDeCamposPessoa;
use App\Support\Campos\SelecaoDeCampos;
use Closure;
use Hyperf\Database\Model\Builder;
```

Substituir `buscarPorId()` e `listar()`:

```php
    /**
     * @param null|SelecaoDeCampos $selecao null significa contrato completo
     */
    public function buscarPorId(int $cdPessoa, int $cdCliente, ?SelecaoDeCampos $selecao = null): ?UnimPessoa
    {
        $selecao ??= MapaDeCamposPessoa::selecao(null, padraoEhTudo: true);

        return self::consulta($selecao)
            ->where('cd_pessoa', $cdPessoa)
            ->where('cd_cliente', $cdCliente)
            ->first();
    }

    /**
     * @param array<string, mixed> $filtros
     * @param null|SelecaoDeCampos $selecao null significa contrato completo
     *
     * @return array{itens: Collection<int, UnimPessoa>, total: int}
     */
    public function listar(int $cdCliente, array $filtros, int $page, int $perPage, ?SelecaoDeCampos $selecao = null): array
    {
        $selecao ??= MapaDeCamposPessoa::selecao(null, padraoEhTudo: true);

        $query = self::consulta($selecao)->where('cd_cliente', $cdCliente);

        if (! empty($filtros['nome'])) {
            $query->where('ds_nome', 'like', '%' . Tipo::texto($filtros['nome']) . '%');
        }

        if (! empty($filtros['tipo_pessoa'])) {
            $query->where('sn_pessoa_juridica', $filtros['tipo_pessoa'] === 'juridica');
        }

        $total = (clone $query)->count();
        $itens = $query->forPage($page, $perPage)->get();

        return ['itens' => $itens, 'total' => $total];
    }

    /**
     * Monta a consulta com o SELECT parcial e só as relações pedidas. É o ponto onde a
     * seleção deixa de ser contrato de API e passa a ser SQL.
     *
     * @return Builder<UnimPessoa>
     */
    private static function consulta(SelecaoDeCampos $selecao): Builder
    {
        $query = UnimPessoa::query();

        foreach ($selecao->relacoes() as $relacao => $colunas) {
            $query->with([$relacao => self::selecionar($colunas)]);
        }

        return $query->select($selecao->colunas());
    }

    /**
     * @param string[] $colunas
     *
     * @return Closure(Builder<Model>): void
     */
    private static function selecionar(array $colunas): Closure
    {
        return static function (Builder $consulta) use ($colunas): void {
            $consulta->select($colunas);
        };
    }
```

O docblock acima usa `Builder<Model>` no tipo do `Closure`, então o import de `Model` é necessário:

```php
use Hyperf\Database\Model\Model;
```

Se o `analyse` acusar o genérico do `Closure` (o callback do `with()` recebe o builder da relação, e o Hyperf não anota esse ponto), a saída é trocar **só** o docblock do `selecionar()` por:

```php
    /**
     * @param string[] $colunas
     *
     * @return Closure(Builder<UnimPessoa>): void
     */
```

E, se ainda acusar, `@return Closure` sem genérico — o parâmetro tipado `Builder $consulta` na assinatura já garante o que importa em runtime. Não remova o `select()`, não silencie com `ignoreErrors`.

- [ ] **Step 5: Rodar os testes do Repository**

```bash
rtk proxy docker exec lumina composer cs-fix
rtk proxy docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Repository/Pessoa/PessoaRepositoryTest.php
```

Esperado: PASS, incluindo os 4 testes novos. **Se `testEagerLoadParcial...` falhar com `fisica veio null`**, a causa é a FK ausente em `SelecaoDeCampos::relacoes()` — volte à Task 1 em vez de remendar aqui.

- [ ] **Step 6: Rodar `composer test` inteiro**

```bash
rtk proxy docker exec lumina composer test
```

Esperado: tudo verde. `analyse` merece atenção: o `ignoreErrors` de `phpstan.neon.dist` casa uma mensagem específica do retorno de `listar()` com `count: 1` — se a refatoração mudar o texto do erro, o PHPStan avisa que o ignore não casou mais, e aí atualize a mensagem no `.dist`.

- [ ] **Step 7: Commit**

```bash
rtk proxy git add app/Repository/Pessoa test/Cases/Repository/Pessoa/PessoaRepositoryTest.php phpstan.neon.dist
rtk proxy git commit -m "feat: select parcial e eager load condicional no PessoaRepository"
```

---

### Task 5: Ligar a listagem ponta a ponta com default enxuto

Depois desta task, `GET /pessoas` muda de comportamento: 4 campos e nenhuma query de relação.

**Files:**
- Modify: `app/Service/Pessoa/PessoaService.php:131-142`
- Modify: `app/Controller/Pessoa/PessoaController.php:135-161`
- Test: `test/Cases/Controller/Pessoa/PessoaControllerTest.php` (acrescentar casos)

**Interfaces:**
- Consumes: `MapaDeCamposPessoa::selecao()` (Task 2), `PessoaResource::muitos()` (Task 3), `PessoaRepositoryInterface::listar()` (Task 4).
- Produces:
  - `PessoaService::listar(int $cdCliente, array $filtros, int $page, int $perPage, ?SelecaoDeCampos $selecao = null): array{itens: Collection<int, UnimPessoa>, total: int, per_page: int}`

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar em `test/Cases/Controller/Pessoa/PessoaControllerTest.php`:

```php
    public function testListaSemFieldsDevolveApenasOConjuntoEnxuto()
    {
        $this->json('/pessoas', [
            'ds_nome' => 'Http Enxuta',
            'ds_login' => 'teste.http.enxuta',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Enxuta Oficial',
        ], $this->headers())->assertStatus(201);

        $listar = $this->get('/pessoas?nome=Enxuta', [], $this->headers());

        $listar->assertStatus(200);
        $item = $listar->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'ds_nome', 'ds_login', 'sn_pessoa_juridica'],
            array_keys($item)
        );
        $this->assertArrayNotHasKey('fisica', $item);
        $this->assertArrayNotHasKey('cd_cliente', $item);
    }

    public function testListaComFieldsAsteriscoMantemOContratoAntigo()
    {
        $this->json('/pessoas', [
            'ds_nome' => 'Http Completa',
            'ds_login' => 'teste.http.completa',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Completa Oficial',
        ], $this->headers())->assertStatus(201);

        $item = $this->get('/pessoas?nome=Completa&fields=*', [], $this->headers())->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'cd_cliente', 'ds_nome', 'ds_login', 'sn_pessoa_juridica', 'fisica', 'juridica'],
            array_keys($item)
        );
        $this->assertSame('Http Completa Oficial', $item['fisica']['ds_nome_oficial']);
    }

    public function testListaComFieldsDeRelacaoDevolveAninhadoSemVazarChaveDeJoin()
    {
        $this->json('/pessoas', [
            'ds_nome' => 'Http Relacao',
            'ds_login' => 'teste.http.relacao',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Relacao Oficial',
            'ds_cpf' => '99988877766',
        ], $this->headers())->assertStatus(201);

        $item = $this->get('/pessoas?nome=Relacao&fields=ds_nome,fisica.ds_cpf', [], $this->headers())->json('data.0');

        $this->assertSame(['ds_nome' => 'Http Relacao', 'fisica' => ['ds_cpf' => '99988877766']], $item);
    }

    public function testListaComRelacaoPedidaEmPessoaDoOutroTipoDevolveNulo()
    {
        $this->json('/pessoas', [
            'ds_nome' => 'Http Juridica Selecao',
            'ds_login' => 'teste.http.juridicasel',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => true,
            'ds_cnpj' => '00000000000191',
            'ds_nome_fantasia' => 'Http Juridica Fantasia',
        ], $this->headers())->assertStatus(201);

        $item = $this->get('/pessoas?nome=Juridica Selecao&fields=ds_nome,fisica.ds_cpf', [], $this->headers())->json('data.0');

        $this->assertArrayHasKey('fisica', $item);
        $this->assertNull($item['fisica']);
    }

    public function testMetaDaPaginacaoNaoMudaComFields()
    {
        $listar = $this->get('/pessoas?fields=ds_nome&per_page=10', [], $this->headers());

        $listar->assertStatus(200);
        $this->assertSame(10, $listar->json('meta.per_page'));
        $this->assertIsInt($listar->json('meta.total'));
    }
```

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
rtk proxy docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Controller/Pessoa/PessoaControllerTest.php --filter "testLista|testMetaDaPaginacao"
```

Esperado: FAIL — a lista ainda devolve todos os campos, então `array_keys($item)` traz 7 chaves onde se esperam 4.

- [ ] **Step 3: Repassar a seleção no Service**

Modificar `app/Service/Pessoa/PessoaService.php`. Acrescentar import:

```php
use App\Support\Campos\SelecaoDeCampos;
```

Substituir `listar()`:

```php
    /**
     * @param array<string, mixed> $filtros
     * @param null|SelecaoDeCampos $selecao repassada intacta ao Repository — o Service não
     *                                      decide nada sobre seleção de campos, quem define
     *                                      o default por endpoint é o Controller
     *
     * @return array{itens: Collection<int, UnimPessoa>, total: int, per_page: int}
     */
    public function listar(int $cdCliente, array $filtros, int $page, int $perPage, ?SelecaoDeCampos $selecao = null): array
    {
        // O per_page EFETIVO (clampado) precisa voltar pro Controller montar o `meta` --
        // senão meta.per_page/last_page mentem quando o cliente pede per_page > 100
        // (Finding 5, whole-branch review: o Controller usava o per_page ORIGINAL do
        // request pro meta, mas a paginação de fato rodava com o clampado).
        $perPage = min($perPage, 100);

        $resultado = $this->pessoaRepository->listar($cdCliente, $filtros, $page, $perPage, $selecao);

        return [...$resultado, 'per_page' => $perPage];
    }
```

- [ ] **Step 4: Montar a seleção no Controller e documentar no Swagger**

Modificar `app/Controller/Pessoa/PessoaController.php`. Acrescentar import:

```php
use App\Resource\Pessoa\MapaDeCamposPessoa;
```

Substituir os atributos e o corpo de `listar()`:

```php
    #[OA\Get(path: '/pessoas', summary: 'Lista pessoas do cliente autenticado', tags: ['Pessoa'])]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100))]
    #[OA\Parameter(name: 'nome', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'tipo_pessoa', in: 'query', schema: new OA\Schema(type: 'string', enum: ['fisica', 'juridica']))]
    #[OA\Parameter(
        name: 'fields',
        in: 'query',
        description: 'Campos a devolver, separados por vírgula. Campo de relação usa ponto (fisica.ds_cpf), e relação inteira usa curinga (fisica.*). `fields=*` devolve tudo. '
            . 'ATENÇÃO: sem este parâmetro a LISTA devolve apenas cd_pessoa, ds_nome, ds_login e sn_pessoa_juridica — diferente de GET /pessoas/{id}, que devolve o registro completo. '
            . 'Campos disponíveis: cd_pessoa, cd_cliente, ds_nome, ds_login, sn_pessoa_juridica, fisica.ds_nome_oficial, fisica.ds_cpf, juridica.ds_cnpj, juridica.ds_nome_fantasia.',
        schema: new OA\Schema(type: 'string', example: 'ds_nome,fisica.ds_cpf')
    )]
    #[OA\Response(response: 200, description: 'Lista paginada de pessoas')]
    #[OA\Response(response: 401, description: 'Não autenticado')]
    #[OA\Response(response: 403, description: 'Sem permissão')]
    #[OA\Response(response: 422, description: 'Dados inválidos')]
    public function listar(ListPessoaRequest $request): ResponseInterface
    {
        $validado = Tipo::mapa($request->validated());

        $filtros = array_intersect_key($validado, array_flip(['nome', 'tipo_pessoa']));
        $page = Tipo::inteiro($validado['page'] ?? null, 1);
        $perPage = Tipo::inteiro($validado['per_page'] ?? null, 20);

        $fields = $validado['fields'] ?? null;
        $selecao = MapaDeCamposPessoa::selecao(is_string($fields) ? $fields : null);

        $resultado = $this->pessoaService->listar(IdentidadeContext::cdCliente(), $filtros, $page, $perPage, $selecao);

        return $this->response->json(ApiResponse::sucesso(
            PessoaResource::muitos($resultado['itens'], $selecao),
            [
                'total' => $resultado['total'],
                'per_page' => $resultado['per_page'],
                'current_page' => $page,
                'last_page' => (int) ceil($resultado['total'] / $resultado['per_page']),
            ]
        ));
    }
```

- [ ] **Step 5: Rodar os testes de controller**

```bash
rtk proxy docker exec lumina composer cs-fix
rtk proxy docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Controller
```

Esperado: PASS, **sem precisar ajustar nenhum teste existente**. Isso foi verificado ao escrever o plano: nenhum teste afirma a forma do corpo da listagem. `EndToEndFlowTest` chama `GET /pessoas` apenas para conferir status (403 e 401), e `PessoaControllerTest::testListarComFiltroDeNomeEPaginacao` / `testMetaPerPageEUltimaPagina...` afirmam só `meta.*`. As asserções sobre campos de pessoa existentes são todas em `GET /pessoas/{id}` ou em resposta de escrita, que seguem completas.

Se algum teste **passar a falhar** afirmando `fisica` ausente na lista, é porque alguém o escreveu depois deste plano: o ajuste correto é passar `?fields=*` nele, não reverter o default.

- [ ] **Step 6: Rodar `composer test` inteiro**

```bash
rtk proxy docker exec lumina composer test
```

Esperado: tudo verde.

- [ ] **Step 7: Conferir ao vivo**

```bash
rtk proxy docker restart lumina
rtk proxy docker exec lumina curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:9501/
```

Esperado: `200`. O Swagger novo aparece em `http://localhost:9500`.

- [ ] **Step 8: Commit**

```bash
rtk proxy git add app/Service/Pessoa/PessoaService.php app/Controller/Pessoa/PessoaController.php test/Cases/Controller
rtk proxy git commit -m "feat: GET /pessoas com default enxuto e ?fields= ponta a ponta"
```

---

### Task 6: `fields` no detalhe, com default completo

Última peça. Exige a única mudança estrutural do plano: `GET /pessoas/{id}` não tem FormRequest nem `ValidationMiddleware` hoje.

**Files:**
- Create: `app/Request/Pessoa/BuscarPessoaRequest.php`
- Modify: `config/routes.php:56-58` (a rota `Router::get('/{id}', ...)`)
- Modify: `app/Controller/Pessoa/PessoaController.php:122-133`
- Modify: `app/Service/Pessoa/PessoaService.php:115-129`
- Test: `test/Cases/Controller/Pessoa/PessoaControllerTest.php` (acrescentar casos)

**Interfaces:**
- Consumes: tudo das tasks 1 a 4.
- Produces:
  - `PessoaService::buscar(int $cdPessoa, int $cdCliente, ?SelecaoDeCampos $selecao = null): UnimPessoa`
  - `BuscarPessoaRequest` com `rules(): ['fields' => 'sometimes|string']` e `withValidator()` da trait

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar em `test/Cases/Controller/Pessoa/PessoaControllerTest.php`:

```php
    public function testDetalheSemFieldsDevolveCompleto()
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Detalhe',
            'ds_login' => 'teste.http.detalhe',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Detalhe Oficial',
        ], $this->headers());

        $criar->assertStatus(201);
        $cdPessoa = $criar->json('data.cd_pessoa');

        $item = $this->get("/pessoas/{$cdPessoa}", [], $this->headers())->json('data');

        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'cd_cliente', 'ds_nome', 'ds_login', 'sn_pessoa_juridica', 'fisica', 'juridica'],
            array_keys($item)
        );
    }

    public function testDetalheComFieldsRecorta()
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Detalhe Recorte',
            'ds_login' => 'teste.http.detalherecorte',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Detalhe Recorte Oficial',
        ], $this->headers());

        $cdPessoa = $criar->json('data.cd_pessoa');

        $item = $this->get("/pessoas/{$cdPessoa}?fields=ds_nome", [], $this->headers())->json('data');

        $this->assertSame(['ds_nome' => 'Http Detalhe Recorte'], $item);
    }

    public function testDetalheComCampoInvalidoRetorna422()
    {
        $resposta = $this->get('/pessoas/1?fields=ds_senha', [], $this->headers());

        $resposta->assertStatus(422);
        $this->assertSame(['Campo não permitido: ds_senha.'], $resposta->json('errors.fields'));
    }

    /**
     * Resposta de escrita filtrada esconderia o que o servidor gravou, então fields é
     * ignorado em POST/PUT/PATCH de propósito.
     */
    public function testEscritaIgnoraFieldsEDevolveCompleto()
    {
        $criar = $this->json('/pessoas?fields=ds_nome', [
            'ds_nome' => 'Http Escrita Fields',
            'ds_login' => 'teste.http.escritafields',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Escrita Fields Oficial',
        ], $this->headers());

        $criar->assertStatus(201);
        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'cd_cliente', 'ds_nome', 'ds_login', 'sn_pessoa_juridica', 'fisica', 'juridica'],
            array_keys($criar->json('data'))
        );

        $cdPessoa = $criar->json('data.cd_pessoa');

        $patch = $this->patch("/pessoas/{$cdPessoa}?fields=ds_nome", ['ds_nome' => 'Http Escrita Fields Dois'], $this->headers());

        $patch->assertStatus(200);
        $this->assertArrayHasKey('fisica', $patch->json('data'));
    }
```

- [ ] **Step 2: Rodar e confirmar que falha**

```bash
rtk proxy docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Controller/Pessoa/PessoaControllerTest.php --filter "testDetalhe|testEscritaIgnora"
```

Esperado: `testDetalheComFieldsRecorta` e `testDetalheComCampoInvalidoRetorna422` falham (o parâmetro é ignorado hoje); os outros dois já passam e servem de regressão.

- [ ] **Step 3: Criar o `BuscarPessoaRequest`**

Criar `app/Request/Pessoa/BuscarPessoaRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Request\Pessoa;

use App\Request\Pessoa\Concerns\ValidaCamposDePessoa;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\Validation\Request\FormRequest;

/**
 * GET /pessoas/{id} não tinha FormRequest: nada havia para validar. Passou a ter com o
 * ?fields=, e sem isto o parâmetro seria silenciosamente ignorado no detalhe enquanto
 * funciona na lista — divergência que gera bug de cliente.
 */
class BuscarPessoaRequest extends FormRequest
{
    use ValidaCamposDePessoa;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fields' => 'sometimes|string',
        ];
    }

    public function withValidator(ValidatorInterface $validator): void
    {
        $this->validarCampos($validator);
    }
}
```

- [ ] **Step 4: Registrar o `ValidationMiddleware` na rota do detalhe**

Modificar `config/routes.php` — a rota do `GET /{id}` passa a ter middleware. Mantenha `ValidationMiddleware` **depois** de Auth/Acl (que vêm do grupo), como as outras rotas já fazem, para token inválido barrar em 401 antes da validação rodar:

```php
    Router::get('/{id}', [PessoaController::class, 'buscar'], [
        'acl' => ['recurso' => Recurso::GERENCIAR_PESSOA, 'privilegio' => Privilegio::ACESSAR],
        'middleware' => [ValidationMiddleware::class],
    ]);
```

- [ ] **Step 5: Repassar a seleção no Service**

Modificar `app/Service/Pessoa/PessoaService.php` — `buscar()` ganha o parâmetro:

```php
    /**
     * @param null|SelecaoDeCampos $selecao null significa contrato completo
     */
    public function buscar(int $cdPessoa, int $cdCliente, ?SelecaoDeCampos $selecao = null): UnimPessoa
    {
        $pessoa = $this->pessoaRepository->buscarPorId($cdPessoa, $cdCliente, $selecao);

        if ($pessoa === null) {
            throw new PessoaNaoEncontradaException();
        }

        return $pessoa;
    }
```

**Atenção:** `PessoaService::atualizarParcial()` chama `$this->buscar($cdPessoa, $cdCliente)` para descobrir o tipo real da pessoa. Sem argumento de seleção, ele continua recebendo o registro completo — que é o que aquele fluxo precisa (`$pessoaAtual->sn_pessoa_juridica`). Não passe seleção ali.

- [ ] **Step 6: Montar a seleção no Controller e documentar no Swagger**

Modificar `app/Controller/Pessoa/PessoaController.php` — acrescentar o import do request e substituir `buscar()`:

```php
use App\Request\Pessoa\BuscarPessoaRequest;
```

```php
    #[OA\Get(path: '/pessoas/{id}', summary: 'Busca uma pessoa pelo identificador', tags: ['Pessoa'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(
        name: 'fields',
        in: 'query',
        description: 'Campos a devolver, separados por vírgula (mesma sintaxe de GET /pessoas). '
            . 'Sem este parâmetro o detalhe devolve o registro COMPLETO — diferente da listagem, que devolve um conjunto enxuto.',
        schema: new OA\Schema(type: 'string', example: 'ds_nome,fisica.ds_cpf')
    )]
    #[OA\Response(response: 200, description: 'Pessoa encontrada')]
    #[OA\Response(response: 401, description: 'Não autenticado')]
    #[OA\Response(response: 403, description: 'Sem permissão')]
    #[OA\Response(response: 404, description: 'Pessoa não encontrada')]
    #[OA\Response(response: 422, description: 'Dados inválidos')]
    public function buscar(int $id, BuscarPessoaRequest $request): ResponseInterface
    {
        $fields = Tipo::mapa($request->validated())['fields'] ?? null;
        $selecao = MapaDeCamposPessoa::selecao(is_string($fields) ? $fields : null, padraoEhTudo: true);

        $pessoa = $this->pessoaService->buscar($id, IdentidadeContext::cdCliente(), $selecao);

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa, $selecao)));
    }
```

A ordem `(int $id, BuscarPessoaRequest $request)` é a mesma que `atualizar(int $id, UpdatePessoaRequest $request)` e `atualizarParcial(int $id, PatchPessoaRequest $request)` já usam neste controller e funcionam hoje — o Hyperf casa o parâmetro de rota por nome e injeta o resto pelo container. Não há incerteza aqui.

- [ ] **Step 7: Rodar os testes**

```bash
rtk proxy docker exec lumina composer cs-fix
rtk proxy docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Controller
```

Esperado: PASS, incluindo os 4 testes novos.

- [ ] **Step 8: Rodar `composer test` inteiro**

```bash
rtk proxy docker exec lumina composer test
```

Esperado: `cs-check` limpo, PHPUnit sem erro nem falha, `analyse` com `[OK] No errors`.

- [ ] **Step 9: Conferir ao vivo os dois defaults e o 422**

```bash
rtk proxy docker restart lumina
```

Então, com uma sessão válida no Redis (veja o padrão em `TenantDeTeste` ou fabrique com `docker exec redis redis-cli setex "session:TOKEN" 600 '{...}'`), confirme:

- `GET /pessoas` → 4 campos por item, sem `fisica`
- `GET /pessoas/{id}` → completo
- `GET /pessoas?fields=ds_senha` → 422
- `GET /pessoas/{id}?fields=ds_nome` → `{"ds_nome": "..."}`

Limpe as chaves de prova do Redis ao terminar.

- [ ] **Step 10: Commit**

```bash
rtk proxy git add app config/routes.php test
rtk proxy git commit -m "feat: ?fields= no detalhe de pessoa com default completo"
```

---

## Ordem e dependências

```
Task 1 (Campo, SelecaoDeCampos)
   └─► Task 2 (mapa + validacao 422)
          ├─► Task 3 (Resource)
          └─► Task 4 (Repository)
                 └─► Task 5 (lista ponta a ponta)   [depende de 3 e 4]
                        └─► Task 6 (detalhe)
```

Tasks 3 e 4 podem ser feitas em qualquer ordem entre si, mas ambas antes da 5.

## Fora de escopo

Nenhuma outra Resource; `?include=`; cache de resposta; ordenação por campo selecionado; seleção em resposta de escrita; tradução pt_BR das mensagens de validação; conserto dos 66 testes "risky" do harness do Hyperf.
