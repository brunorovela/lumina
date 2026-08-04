# Domínios de cadastro e pessoa física completa — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expor os dez campos de `unim_pessoa_fisica` hoje inalcançáveis pela API, cinco rotas read-only de domínio para que as FKs do cadastro possam ser descobertas, e validação de formato na escrita — com PII exigindo pedido nominal.

**Architecture:** O núcleo genérico de seleção de campos (`app/Support/Campos/`) ganha a noção de campo sensível, que `MapaDeCamposPessoa` usa para tirar PII do default do detalhe. Os domínios são catálogos globais lidos por um repositório novo (`app/Repository/Dominio/`) e recortados por um Resource explícito, sem service — não há regra de negócio a hospedar. A validação de formato entra nos `FormRequest` de pessoa: normalização em `validationData()` antes das regras, e dígito verificador por trait no `withValidator()`, seguindo o padrão que `ValidaCamposDePessoa` já estabeleceu.

**Tech Stack:** PHP 8.4, Hyperf (runtime Swoole com corrotinas), `hyperf/database` (ORM estilo Eloquent), MySQL 8 (schema `lms2`, compartilhado com o LMS legado), Redis (sessão e cache de ACL), PHPUnit via `co-phpunit`, PHPStan nível 10, PHP-CS-Fixer.

**Spec:** `docs/superpowers/specs/2026-08-04-dominios-e-pessoa-fisica-completa-design.md`

**Branch:** `feat/dominios-e-pessoa-fisica-completa`

## Global Constraints

Valem para todas as tarefas. São as regras do `CLAUDE.md` deste repositório e não são sugestões.

- **PHP não existe no host.** Todo comando PHP/composer roda com prefixo `docker exec lumina`.
- **Nunca escreva o header de licença à mão.** Ele é obrigatório em todo arquivo e é o `cs-fix` que o insere: `docker exec lumina composer cs-fix`.
- **Não crie migration.** Nada em `migrations/`. Se faltar coluna, privilégio ou par recurso/privilégio: pare, reporte, entregue o SQL, não aplique. A Task 10 é inteiramente bloqueada por isso.
- **`sn_excluido` não existe para você.** `dt_excluido` é a única fonte de verdade sobre exclusão. Não leia, filtre, ordene nem ramifique por `sn_excluido`.
- **PHPStan nível 10, zero erros.** Anote tipo de array em todo método novo: `array<string, mixed>`, `string[]`, `array<int, array<string, mixed>>`. `array` pelado é rejeitado.
- **Documentação é o JSON gerado, não o atributo PHP.** Toda mudança de contrato HTTP regenera o artefato **no mesmo commit**: `docker exec lumina php /opt/www/bin/hyperf.php gen:swagger`. Conferir em `storage/swagger/http.json`, nunca no fonte.
- **`cd_cliente = 1` e `cd_perfil = 1` não existem** neste banco (`saas_cliente` começa em 20, `lgin_perfil` em 79). Todo teste que precisa de tenant usa `HyperfTest\Support\TenantDeTeste`.
- **Chave de ACL é a do LMS.** `GERENCIAR_PESSOA` + `ACESSAR` para leitura. Não existe privilégio `listar` nem `visualizar`. Chave inventada nega tudo em silêncio.
- **Nunca use `/** @var X $y */` isolado** para estreitar tipo: o cs-fixer reescreve para `/* @var */` e o PHPStan ignora essa forma. Use `instanceof`.
- **Fechamento de cada tarefa:** `docker exec lumina composer cs-fix` e depois `docker exec lumina composer test` (roda `cs-check` + `php-unit` + `analyse`, nessa ordem). A suíte reporta vários testes como *risky* (`did not remove its own error handlers`) — é o harness do Hyperf, não é regressão. Só erro e falha contam.

---

### Task 1: Campo sensível no núcleo de seleção

Hoje `SelecaoDeCampos::de()` produz **exatamente a mesma seleção** para "default do item" e para `fields=*`:

```php
if ($tokens === []) {
    return $padraoEhTudo
        ? new self($mapa, array_keys($mapa), $chaveLocal, true)   // default do item
        : new self($mapa, self::doPadrao($mapa), $chaveLocal, false);
}

if (in_array('*', $tokens, true)) {
    return new self($mapa, array_keys($mapa), $chaveLocal, true); // curinga
}
```

As duas são indistinguíveis, então não há onde pendurar "PII só se pedida". Esta tarefa separa as três intenções. É trabalho puramente genérico: nada de pessoa entra aqui.

**Files:**
- Modify: `app/Support/Campos/Campo.php`
- Modify: `app/Support/Campos/SelecaoDeCampos.php:38-72`
- Test: `test/Cases/Support/Campos/SelecaoDeCamposTest.php`

**Interfaces:**
- Consumes: nada de tarefas anteriores.
- Produces:
  - `Campo::coluna(string $coluna, bool $noPadrao = false, bool $sensivel = false): self`
  - `Campo::relacao(string $relacao, string $coluna, string $chaveEstrangeira, bool $noPadrao = false, bool $sensivel = false): self`
  - `Campo->sensivel: bool` (readonly)
  - `SelecaoDeCampos::completa(array<string, Campo> $mapa, string $chaveLocal): self` — mapa inteiro, sensíveis inclusos
  - `SelecaoDeCampos::de()` mantém a assinatura atual; muda só o comportamento do ramo `padraoEhTudo` sem tokens

- [ ] **Step 1: Write the failing test**

Acrescente ao fim de `test/Cases/Support/Campos/SelecaoDeCamposTest.php`, antes dos helpers privados:

```php
    public function testPadraoEhTudoOmiteCampoSensivel()
    {
        $selecao = SelecaoDeCampos::de(null, $this->mapaComSensivel(), 'cd_pessoa', padraoEhTudo: true);

        $this->assertContains('ds_nome', $selecao->campos());
        $this->assertNotContains('fisica.ds_cpf', $selecao->campos());
    }

    public function testCuringaTrazCampoSensivelPorqueFoiPedido()
    {
        $this->assertContains(
            'fisica.ds_cpf',
            SelecaoDeCampos::de('*', $this->mapaComSensivel(), 'cd_pessoa')->campos()
        );

        $this->assertContains(
            'fisica.ds_cpf',
            SelecaoDeCampos::de('fisica.*', $this->mapaComSensivel(), 'cd_pessoa')->campos()
        );
    }

    public function testNomeExatoTrazCampoSensivel()
    {
        $selecao = SelecaoDeCampos::de('fisica.ds_cpf', $this->mapaComSensivel(), 'cd_pessoa');

        $this->assertSame(['fisica.ds_cpf'], $selecao->campos());
    }

    public function testCompletaTrazCampoSensivelParaRespostaDeEscrita()
    {
        $selecao = SelecaoDeCampos::completa($this->mapaComSensivel(), 'cd_pessoa');

        $this->assertEqualsCanonicalizing(array_keys($this->mapaComSensivel()), $selecao->campos());
    }

    /**
     * @return array<string, Campo>
     */
    private function mapaComSensivel(): array
    {
        return [
            'cd_pessoa' => Campo::coluna('cd_pessoa', noPadrao: true),
            'ds_nome' => Campo::coluna('ds_nome', noPadrao: true),
            'fisica.ds_nome_oficial' => Campo::relacao('fisica', 'ds_nome_oficial', 'cd_pessoa'),
            'fisica.ds_cpf' => Campo::relacao('fisica', 'ds_cpf', 'cd_pessoa', sensivel: true),
        ];
    }
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php \
  test/Cases/Support/Campos/SelecaoDeCamposTest.php --filter testPadraoEhTudoOmiteCampoSensivel
```

Esperado: FAIL. `Campo::relacao()` não conhece `sensivel:` — `ArgumentCountError`/`Unknown named parameter $sensivel`.

- [ ] **Step 3: Acrescente `sensivel` ao `Campo`**

Em `app/Support/Campos/Campo.php`, substitua construtor e factories:

```php
    private function __construct(
        public readonly string $coluna,
        public readonly ?string $relacao,
        public readonly ?string $chaveEstrangeira,
        public readonly bool $noPadrao,
        public readonly bool $sensivel,
    ) {
    }

    public static function coluna(string $coluna, bool $noPadrao = false, bool $sensivel = false): self
    {
        return new self($coluna, null, null, $noPadrao, $sensivel);
    }

    public static function relacao(
        string $relacao,
        string $coluna,
        string $chaveEstrangeira,
        bool $noPadrao = false,
        bool $sensivel = false
    ): self {
        return new self($coluna, $relacao, $chaveEstrangeira, $noPadrao, $sensivel);
    }
```

E acrescente ao PHPDoc da classe, depois da linha sobre chave estrangeira:

```php
 * Campo sensível (PII: CPF, RG, filiação, nascimento) sai do default do item — só vem se
 * pedido por nome ou por curinga. Ver SelecaoDeCampos::de() e ::completa().
```

- [ ] **Step 4: Separe as três intenções em `SelecaoDeCampos`**

Em `app/Support/Campos/SelecaoDeCampos.php`, troque o corpo de `de()` (linhas 38-72) por:

```php
    public static function de(
        ?string $fields,
        array $mapa,
        string $chaveLocal,
        bool $padraoEhTudo = false
    ): self {
        $tokens = self::tokens($fields);

        if ($tokens === []) {
            // padraoEhTudo é o default do ITEM, e default não é pedido: campo sensível fica
            // fora. Pedir por nome ou por curinga é pedido explícito e traz. Resposta de
            // escrita usa completa(), que ignora essa distinção.
            return $padraoEhTudo
                ? new self($mapa, self::naoSensiveis($mapa), $chaveLocal, true)
                : new self($mapa, self::doPadrao($mapa), $chaveLocal, false);
        }

        if (in_array('*', $tokens, true)) {
            return self::completa($mapa, $chaveLocal);
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
     * Mapa inteiro, campo sensível incluso. É o que a resposta de POST/PUT/PATCH usa:
     * filtrar a resposta de escrita esconderia o que o servidor acabou de gravar.
     *
     * @param array<string, Campo> $mapa
     */
    public static function completa(array $mapa, string $chaveLocal): self
    {
        return new self($mapa, array_keys($mapa), $chaveLocal, true);
    }
```

E acrescente, junto de `doPadrao()` no fim da classe:

```php
    /**
     * @param array<string, Campo> $mapa
     *
     * @return string[]
     */
    private static function naoSensiveis(array $mapa): array
    {
        $campos = [];

        foreach ($mapa as $chave => $campo) {
            if (! $campo->sensivel) {
                $campos[] = $chave;
            }
        }

        return $campos;
    }
```

Acrescente também ao PHPDoc de `tudo()`, porque o significado agora precisa ser dito:

```php
    /**
     * Verdadeiro quando o cliente NÃO recortou nada — default do item ou curinga. Não
     * significa "todos os campos do mapa": no default do item os sensíveis ficam fora.
     */
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php \
  test/Cases/Support/Campos/SelecaoDeCamposTest.php
```

Esperado: PASS, incluindo os testes que já existiam. Os antigos continuam verdes porque o mapa deles não tem campo sensível — `naoSensiveis()` devolve o mapa inteiro nesse caso.

- [ ] **Step 6: Feche a tarefa**

```bash
docker exec lumina composer cs-fix
docker exec lumina composer test
git add app/Support/Campos/Campo.php app/Support/Campos/SelecaoDeCampos.php \
        test/Cases/Support/Campos/SelecaoDeCamposTest.php
git commit -m "feat: campo sensivel no nucleo de selecao de campos

padraoEhTudo e fields=* produziam a mesma selecao e eram indistinguiveis, entao
nao havia onde pendurar a regra de PII. Agora sao tres intencoes: default do item
(sem sensivel), pedido explicito por nome ou curinga (com), e completa() para
resposta de escrita.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Dígito verificador de CPF e CNPJ

Unidade pura, sem framework e sem banco. Existe para que a Task 7 tenha o que chamar.

**Files:**
- Create: `app/Support/Documento.php`
- Test: `test/Cases/Support/DocumentoTest.php`

**Interfaces:**
- Consumes: nada.
- Produces:
  - `Documento::apenasDigitos(string $valor): string`
  - `Documento::cpfEhValido(string $cpf): bool`
  - `Documento::cnpjEhValido(string $cnpj): bool`

  Os dois validadores recebem valor **já sem máscara** e devolvem `false` para tamanho errado, para sequência de dígito repetido e para DV que não fecha.

- [ ] **Step 1: Write the failing test**

Crie `test/Cases/Support/DocumentoTest.php`:

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Support;

use App\Support\Documento;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DocumentoTest extends TestCase
{
    public function testApenasDigitosRemoveMascara()
    {
        $this->assertSame('12345678909', Documento::apenasDigitos('123.456.789-09'));
        $this->assertSame('00000000000191', Documento::apenasDigitos('00.000.000/0001-91'));
        $this->assertSame('01310100', Documento::apenasDigitos('01310-100'));
        $this->assertSame('', Documento::apenasDigitos('sem digito'));
    }

    public function testCpfComDigitoVerificadorCorreto()
    {
        $this->assertTrue(Documento::cpfEhValido('12345678909'));
        $this->assertTrue(Documento::cpfEhValido('52998224725'));
    }

    public function testCpfInvalido()
    {
        // DV que não fecha
        $this->assertFalse(Documento::cpfEhValido('12345678900'));
        // sequência de dígito repetido: DV fecha na aritmética, mas não é CPF
        $this->assertFalse(Documento::cpfEhValido('11111111111'));
        $this->assertFalse(Documento::cpfEhValido('00000000000'));
        // tamanho errado
        $this->assertFalse(Documento::cpfEhValido('1234567890'));
        $this->assertFalse(Documento::cpfEhValido(''));
    }

    public function testCnpjComDigitoVerificadorCorreto()
    {
        $this->assertTrue(Documento::cnpjEhValido('00000000000191'));
        $this->assertTrue(Documento::cnpjEhValido('11222333000181'));
    }

    public function testCnpjInvalido()
    {
        $this->assertFalse(Documento::cnpjEhValido('00000000000192'));
        $this->assertFalse(Documento::cnpjEhValido('11111111111111'));
        $this->assertFalse(Documento::cnpjEhValido('1122233300018'));
        $this->assertFalse(Documento::cnpjEhValido(''));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Support/DocumentoTest.php
```

Esperado: FAIL com `Class "App\Support\Documento" not found`.

- [ ] **Step 3: Write the implementation**

Crie `app/Support/Documento.php` (sem o header de licença — o `cs-fix` insere):

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Dígito verificador de CPF e CNPJ, e remoção de máscara.
 *
 * Recebe valor já sem máscara nos dois validadores: quem normaliza é
 * validationData() no FormRequest, antes de as regras rodarem. Sequência de dígito
 * repetido é rejeitada à parte porque fecha na aritmética do DV ("11111111111" é
 * aritmeticamente válido) e não é documento.
 *
 * O legado tem CPF com DV inválido gravado. Isto vale só para escrita nova — leitura
 * devolve o que está no banco, sem validar.
 */
final class Documento
{
    public static function apenasDigitos(string $valor): string
    {
        return preg_replace('/\D/', '', $valor) ?? '';
    }

    public static function cpfEhValido(string $cpf): bool
    {
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        foreach ([9, 10] as $posicao) {
            $soma = 0;

            for ($i = 0; $i < $posicao; ++$i) {
                $soma += (int) $cpf[$i] * ($posicao + 1 - $i);
            }

            if ((int) $cpf[$posicao] !== self::digito($soma)) {
                return false;
            }
        }

        return true;
    }

    public static function cnpjEhValido(string $cnpj): bool
    {
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        // Pesos do CNPJ: 5..2 seguido de 9..2 para o primeiro DV; o segundo repete a
        // sequência com um peso a mais na frente.
        $pesosPrimeiro = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $pesosSegundo = [6, ...$pesosPrimeiro];

        foreach ([[12, $pesosPrimeiro], [13, $pesosSegundo]] as [$posicao, $pesos]) {
            $soma = 0;

            foreach ($pesos as $i => $peso) {
                $soma += (int) $cnpj[$i] * $peso;
            }

            if ((int) $cnpj[$posicao] !== self::digito($soma)) {
                return false;
            }
        }

        return true;
    }

    private static function digito(int $soma): int
    {
        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Support/DocumentoTest.php
```

Esperado: PASS, 5 testes.

- [ ] **Step 5: Feche a tarefa**

```bash
docker exec lumina composer cs-fix
docker exec lumina composer test
git add app/Support/Documento.php test/Cases/Support/DocumentoTest.php
git commit -m "feat: digito verificador de CPF e CNPJ

O banco nao restringe nada e o legado gravou documento com DV invalido. A API nao
vai acrescentar mais: escrita nova valida, leitura devolve o que esta la.

Sequencia de digito repetido e rejeitada a parte porque fecha na aritmetica do DV.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Models de domínio e `DominioRepository`

Cinco catálogos globais. Nenhum tem `cd_cliente` — logo não há `WHERE` de tenant — e nenhum tem `dt_excluido`, logo nada de `SoftDeletes`.

**Files:**
- Create: `app/Model/Dominio/SaasPais.php`
- Create: `app/Model/Dominio/SaasEstado.php`
- Create: `app/Model/Dominio/SaasCidade.php`
- Create: `app/Model/Dominio/SaasEstadoCivil.php`
- Create: `app/Model/Dominio/UnimPessoaContatoTipo.php`
- Create: `app/Repository/Dominio/DominioRepositoryInterface.php`
- Create: `app/Repository/Dominio/DominioRepository.php`
- Modify: `config/autoload/dependencies.php`
- Test: `test/Cases/Repository/Dominio/DominioRepositoryTest.php`

**Interfaces:**
- Consumes: nada.
- Produces:
  - `DominioRepositoryInterface::paises(): Collection<int, SaasPais>`
  - `DominioRepositoryInterface::estados(?int $cdPais = null): Collection<int, SaasEstado>`
  - `DominioRepositoryInterface::cidades(int $cdEstado, ?string $q = null): Collection<int, SaasCidade>`
  - `DominioRepositoryInterface::estadosCivis(): Collection<int, SaasEstadoCivil>`
  - `DominioRepositoryInterface::tiposDeContato(): Collection<int, UnimPessoaContatoTipo>`
  - `Collection` é `Hyperf\Database\Model\Collection`.

- [ ] **Step 1: Write the failing test**

Crie `test/Cases/Repository/Dominio/DominioRepositoryTest.php`. As asserções não fixam contagem — `saas_cidade` tem 4928 linhas hoje e isso é dado volátil. Provam propriedade: o filtro filtra, a ordem é determinística, a coluna de controle não vem.

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Repository\Dominio;

use App\Repository\Dominio\DominioRepository;
use Hyperf\DbConnection\Db;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DominioRepositoryTest extends TestCase
{
    private DominioRepository $repositorio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositorio = new DominioRepository();
    }

    public function testPaisesTrazApenasAsColunasExpostas()
    {
        $pais = $this->repositorio->paises()->first();

        $this->assertNotNull($pais);
        $this->assertSame(
            ['cd_pais', 'ds_pais', 'ds_nacionalidade'],
            array_keys($pais->getAttributes())
        );
    }

    public function testEstadosFiltradosPorPaisSoTrazemAquelePais()
    {
        $cdPais = (int) Db::table('saas_estado')->min('cd_pais');

        $estados = $this->repositorio->estados($cdPais);

        $this->assertGreaterThan(0, $estados->count());

        foreach ($estados as $estado) {
            $this->assertSame($cdPais, $estado->cd_pais);
        }
    }

    public function testEstadosSemFiltroTrazMaisDoQueUmPaisSozinhoOuIgual()
    {
        $cdPais = (int) Db::table('saas_estado')->min('cd_pais');

        $this->assertGreaterThanOrEqual(
            $this->repositorio->estados($cdPais)->count(),
            $this->repositorio->estados()->count()
        );
    }

    public function testCidadesExigemEstadoENuncaVazamOutroEstado()
    {
        $cdEstado = (int) Db::table('saas_cidade')->min('cd_estado');

        $cidades = $this->repositorio->cidades($cdEstado);

        $this->assertGreaterThan(0, $cidades->count());

        foreach ($cidades as $cidade) {
            $this->assertSame($cdEstado, $cidade->cd_estado);
        }
    }

    public function testCidadesFiltradasPorTermoCasamOTermo()
    {
        $cdEstado = (int) Db::table('saas_cidade')->min('cd_estado');
        $primeira = $this->repositorio->cidades($cdEstado)->first();

        $this->assertNotNull($primeira);

        $termo = mb_substr((string) $primeira->ds_cidade, 0, 3);
        $cidades = $this->repositorio->cidades($cdEstado, $termo);

        $this->assertGreaterThan(0, $cidades->count());

        foreach ($cidades as $cidade) {
            $this->assertStringContainsStringIgnoringCase($termo, (string) $cidade->ds_cidade);
        }
    }

    public function testEstadosCivisNaoTrazemColunaDeControleDoLms()
    {
        $estadoCivil = $this->repositorio->estadosCivis()->first();

        $this->assertNotNull($estadoCivil);
        $this->assertArrayNotHasKey('dt_base', $estadoCivil->getAttributes());
    }

    public function testTiposDeContatoTrazemChaveEDescricao()
    {
        $tipos = $this->repositorio->tiposDeContato();

        $this->assertGreaterThan(0, $tipos->count());

        $chaves = $tipos->pluck('ds_chave')->all();
        $this->assertContains('EMAIL', $chaves);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php \
  test/Cases/Repository/Dominio/DominioRepositoryTest.php
```

Esperado: FAIL com `Class "App\Repository\Dominio\DominioRepository" not found`.

- [ ] **Step 3: Crie os cinco models**

Todos sem `SoftDeletes` (nenhuma tabela tem `dt_excluido`) e com `timestamps = false` — `dt_base` é coluna de controle do LMS com `ON UPDATE CURRENT_TIMESTAMP`, e nós nunca escrevemos nela.

`app/Model/Dominio/SaasPais.php`:

```php
<?php

declare(strict_types=1);

namespace App\Model\Dominio;

use App\Model\Model;

/**
 * Catálogo global: saas_pais não tem cd_cliente, logo não há escopo de tenant a aplicar.
 * Leitura apenas — sem $fillable de propósito, nada nesta API escreve em domínio.
 *
 * dt_base existe na tabela e NÃO é exposta: é controle do LMS legado
 * (ON UPDATE CURRENT_TIMESTAMP), não dado de negócio.
 *
 * @property int $cd_pais
 * @property null|string $ds_pais
 * @property null|string $ds_nacionalidade
 */
class SaasPais extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'saas_pais';

    protected string $primaryKey = 'cd_pais';
}
```

`app/Model/Dominio/SaasEstado.php`:

```php
<?php

declare(strict_types=1);

namespace App\Model\Dominio;

use App\Model\Model;

/**
 * Catálogo global. Ver App\Model\Dominio\SaasPais.
 *
 * @property int $cd_estado
 * @property int $cd_pais
 * @property null|string $ds_estado
 * @property null|string $ds_uf
 */
class SaasEstado extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'saas_estado';

    protected string $primaryKey = 'cd_estado';
}
```

`app/Model/Dominio/SaasCidade.php`:

```php
<?php

declare(strict_types=1);

namespace App\Model\Dominio;

use App\Model\Model;

/**
 * Catálogo global. Ver App\Model\Dominio\SaasPais.
 *
 * 4928 linhas: a leitura sempre passa por cd_estado, nunca varre a tabela inteira.
 *
 * @property int $cd_cidade
 * @property int $cd_estado
 * @property null|string $ds_cidade
 */
class SaasCidade extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'saas_cidade';

    protected string $primaryKey = 'cd_cidade';
}
```

`app/Model/Dominio/SaasEstadoCivil.php`:

```php
<?php

declare(strict_types=1);

namespace App\Model\Dominio;

use App\Model\Model;

/**
 * Catálogo global. Ver App\Model\Dominio\SaasPais.
 *
 * Destino da FK unim_pessoa_fisica.cd_estado_civil.
 *
 * @property int $cd_estado_civil
 * @property null|string $ds_estado_civil
 */
class SaasEstadoCivil extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'saas_estado_civil';

    protected string $primaryKey = 'cd_estado_civil';
}
```

`app/Model/Dominio/UnimPessoaContatoTipo.php`:

```php
<?php

declare(strict_types=1);

namespace App\Model\Dominio;

use App\Model\Model;

/**
 * Catálogo global. Ver App\Model\Dominio\SaasPais.
 *
 * Destino da FK unim_pessoa_contato.cd_tipo. As chaves são as do LMS: TELEFONE,
 * TELEFONE-COMERCIAL, TELEFONE-CELULAR, EMAIL, SITE.
 *
 * @property int $cd_tipo
 * @property string $ds_descricao
 * @property string $ds_chave
 */
class UnimPessoaContatoTipo extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'unim_pessoa_contato_tipo';

    protected string $primaryKey = 'cd_tipo';
}
```

- [ ] **Step 4: Crie a interface do repositório**

`app/Repository/Dominio/DominioRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository\Dominio;

use App\Model\Dominio\SaasCidade;
use App\Model\Dominio\SaasEstado;
use App\Model\Dominio\SaasEstadoCivil;
use App\Model\Dominio\SaasPais;
use App\Model\Dominio\UnimPessoaContatoTipo;
use Hyperf\Database\Model\Collection;

/**
 * Leitura dos catálogos que alimentam o cadastro de pessoa. Todos globais: nenhuma das
 * tabelas tem cd_cliente, então não existe escopo de tenant aqui — diferente de tudo em
 * App\Repository\Pessoa.
 */
interface DominioRepositoryInterface
{
    /**
     * @return Collection<int, SaasPais>
     */
    public function paises(): Collection;

    /**
     * @param null|int $cdPais null devolve todos os estados
     *
     * @return Collection<int, SaasEstado>
     */
    public function estados(?int $cdPais = null): Collection;

    /**
     * @param int $cdEstado obrigatório: sem ele a consulta varreria 4928 linhas
     * @param null|string $q filtra ds_cidade por LIKE %q%
     *
     * @return Collection<int, SaasCidade>
     */
    public function cidades(int $cdEstado, ?string $q = null): Collection;

    /**
     * @return Collection<int, SaasEstadoCivil>
     */
    public function estadosCivis(): Collection;

    /**
     * @return Collection<int, UnimPessoaContatoTipo>
     */
    public function tiposDeContato(): Collection;
}
```

- [ ] **Step 5: Implemente o repositório**

Repare no padrão de chamada: `select()` e `orderBy()` são chamados como **statement**, não encadeados no `return`. `select()` só existe em `Query\Builder` e chega ao `Model\Builder` via `@mixin`; encadear faria o PHPStan inferir `Query\Builder` e perder o `TModel`, que é exatamente a dívida já registrada no `ignoreErrors` de `phpstan.neon.dist` para o `forPage()` do `PessoaRepository`. Chamando separadamente, `$query` continua tipado como `Builder<SaasPais>` e `get()` devolve `Collection<int, SaasPais>` sem precisar de exceção nova.

`app/Repository/Dominio/DominioRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository\Dominio;

use App\Model\Dominio\SaasCidade;
use App\Model\Dominio\SaasEstado;
use App\Model\Dominio\SaasEstadoCivil;
use App\Model\Dominio\SaasPais;
use App\Model\Dominio\UnimPessoaContatoTipo;
use Hyperf\Database\Model\Collection;

class DominioRepository implements DominioRepositoryInterface
{
    /**
     * @return Collection<int, SaasPais>
     */
    public function paises(): Collection
    {
        $query = SaasPais::query();
        $query->select(['cd_pais', 'ds_pais', 'ds_nacionalidade']);
        $query->orderBy('ds_pais');

        return $query->get();
    }

    /**
     * @return Collection<int, SaasEstado>
     */
    public function estados(?int $cdPais = null): Collection
    {
        $query = SaasEstado::query();
        $query->select(['cd_estado', 'cd_pais', 'ds_estado', 'ds_uf']);

        if ($cdPais !== null) {
            $query->where('cd_pais', $cdPais);
        }

        $query->orderBy('ds_estado');

        return $query->get();
    }

    /**
     * @return Collection<int, SaasCidade>
     */
    public function cidades(int $cdEstado, ?string $q = null): Collection
    {
        $query = SaasCidade::query();
        $query->select(['cd_cidade', 'cd_estado', 'ds_cidade']);
        $query->where('cd_estado', $cdEstado);

        if ($q !== null && $q !== '') {
            $query->where('ds_cidade', 'like', '%' . $q . '%');
        }

        $query->orderBy('ds_cidade');

        return $query->get();
    }

    /**
     * @return Collection<int, SaasEstadoCivil>
     */
    public function estadosCivis(): Collection
    {
        $query = SaasEstadoCivil::query();
        $query->select(['cd_estado_civil', 'ds_estado_civil']);
        $query->orderBy('cd_estado_civil');

        return $query->get();
    }

    /**
     * @return Collection<int, UnimPessoaContatoTipo>
     */
    public function tiposDeContato(): Collection
    {
        $query = UnimPessoaContatoTipo::query();
        $query->select(['cd_tipo', 'ds_descricao', 'ds_chave']);
        $query->orderBy('cd_tipo');

        return $query->get();
    }
}
```

- [ ] **Step 6: Registre o binding no container**

Em `config/autoload/dependencies.php`, acrescente o import e a entrada:

```php
use App\Repository\Dominio\DominioRepository;
use App\Repository\Dominio\DominioRepositoryInterface;
```

```php
    DominioRepositoryInterface::class => DominioRepository::class,
```

Coloque a entrada junto das outras duas de repositório, antes do comentário sobre `ResponseInterface`.

- [ ] **Step 7: Run tests to verify they pass**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php \
  test/Cases/Repository/Dominio/DominioRepositoryTest.php
```

Esperado: PASS, 7 testes. Se `testPaisesTrazApenasAsColunasExpostas` falhar mostrando `dt_base` entre as chaves, o `select()` não foi aplicado — confira que ele é statement e não parte de um encadeamento cujo resultado foi descartado por engano.

- [ ] **Step 8: Feche a tarefa**

```bash
docker exec lumina composer cs-fix
docker exec lumina composer test
git add app/Model/Dominio app/Repository/Dominio config/autoload/dependencies.php \
        test/Cases/Repository/Dominio/DominioRepositoryTest.php
git commit -m "feat: models e repositorio dos dominios de cadastro

Cinco catalogos globais: pais, estado, cidade, estado civil e tipo de contato.
Nenhum tem cd_cliente (sem escopo de tenant) nem dt_excluido (sem SoftDeletes).

dt_base fica fora do select: e controle do LMS legado, nao dado de negocio, e
toArray() do model a vazaria.

select() e orderBy() sao statement e nao encadeamento -- encadear faz o phpstan
inferir Query\\Builder e perder o TModel, a mesma divida ja registrada no
ignoreErrors para o forPage() do PessoaRepository.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Rotas de domínio sem parâmetro (`/paises`, `/estados-civis`, `/contato-tipos`)

Três rotas que não recebem nada, logo sem `FormRequest` e sem `ValidationMiddleware` — não haveria o que validar. Entregam a primeira fatia usável: o cliente já descobre estado civil e tipo de contato.

**Files:**
- Create: `app/Resource/Dominio/DominioResource.php`
- Create: `app/Controller/Dominio/DominioController.php`
- Create: `app/Swagger/PaisSchema.php`
- Create: `app/Swagger/EstadoCivilSchema.php`
- Create: `app/Swagger/ContatoTipoSchema.php`
- Modify: `config/routes.php`
- Test: `test/Cases/Controller/Dominio/DominioControllerTest.php`

**Interfaces:**
- Consumes: `DominioRepositoryInterface` (Task 3), com os cinco métodos.
- Produces:
  - `DominioResource::paises(iterable $paises): array<int, array<string, mixed>>`
  - `DominioResource::estadosCivis(iterable $estadosCivis): array<int, array<string, mixed>>`
  - `DominioResource::tiposDeContato(iterable $tipos): array<int, array<string, mixed>>`
  - `DominioController::paises()`, `::estadosCivis()`, `::tiposDeContato()`, todos `ResponseInterface`
  - Schemas OpenAPI `Pais`, `EstadoCivil`, `ContatoTipo` em `#/components/schemas/`
  - A Task 5 acrescenta `DominioResource::estados()`/`::cidades()` e as actions correspondentes na **mesma** classe.

- [ ] **Step 1: Write the failing test**

Crie `test/Cases/Controller/Dominio/DominioControllerTest.php`. O `setUp` monta sessão e ACL no Redis exatamente como `PessoaControllerTest` faz — as chaves têm de ser as `ds_chave` reais do LMS, porque chave inventada nega tudo em silêncio.

```php
<?php

declare(strict_types=1);

namespace HyperfTest\Cases\Controller\Dominio;

use App\Enum\Privilegio;
use App\Enum\Recurso;
use Hyperf\Redis\Redis;
use Hyperf\Testing\TestCase;
use HyperfTest\Support\TenantDeTeste;

/**
 * @internal
 * @coversNothing
 */
class DominioControllerTest extends TestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $cdPerfil = TenantDeTeste::cdPerfil();

        $redis = $this->getContainer()->get(Redis::class);
        $this->token = bin2hex(random_bytes(32));
        $redis->setex("session:{$this->token}", 3600, json_encode([
            'cd_pessoa' => 1,
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'cd_perfis' => [$cdPerfil],
        ]));

        $redis->setex("acl:perfil:{$cdPerfil}", 3600, json_encode([
            Recurso::GERENCIAR_PESSOA->value => [Privilegio::ACESSAR->value],
        ]));
    }

    public function testPaisesRespondeEnvelopeSemMeta()
    {
        $resposta = $this->get('/paises', [], $this->headers());

        $resposta->assertStatus(200);
        $corpo = $resposta->json();

        $this->assertTrue($corpo['success']);
        $this->assertIsArray($corpo['data']);
        // Lista de domínio não pagina, então não tem meta. Se aparecer, alguém copiou o
        // envelope de /pessoas sem pensar.
        $this->assertArrayNotHasKey('meta', $corpo);
        $this->assertSame(
            ['cd_pais', 'ds_pais', 'ds_nacionalidade'],
            array_keys($corpo['data'][0])
        );
    }

    public function testEstadosCivisNaoVazamColunaDeControle()
    {
        $resposta = $this->get('/estados-civis', [], $this->headers());

        $resposta->assertStatus(200);
        $this->assertSame(
            ['cd_estado_civil', 'ds_estado_civil'],
            array_keys($resposta->json('data')[0])
        );
    }

    public function testContatoTiposTrazemAsChavesDoLms()
    {
        $resposta = $this->get('/contato-tipos', [], $this->headers());

        $resposta->assertStatus(200);
        $chaves = array_column($resposta->json('data'), 'ds_chave');

        $this->assertContains('EMAIL', $chaves);
        $this->assertContains('TELEFONE-CELULAR', $chaves);
    }

    public function testSemTokenResponde401()
    {
        $this->get('/paises')->assertStatus(401);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php \
  test/Cases/Controller/Dominio/DominioControllerTest.php
```

Esperado: FAIL. `/paises` não existe: 404 onde o teste espera 200.

- [ ] **Step 3: Crie o Resource**

Recorte explícito, campo por campo. **Nunca** `toArray()` do model: ele devolveria `dt_base`, e o dia em que alguém acrescentar coluna à tabela do LMS ela apareceria na API sem ninguém decidir isso.

`app/Resource/Dominio/DominioResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Resource\Dominio;

use App\Model\Dominio\SaasEstadoCivil;
use App\Model\Dominio\SaasPais;
use App\Model\Dominio\UnimPessoaContatoTipo;

/**
 * Recorte da saída dos catálogos.
 *
 * Campo por campo de propósito: toArray() do model devolveria dt_base (controle do LMS) e
 * exporia qualquer coluna nova que aparecesse na tabela sem ninguém ter decidido expor.
 */
class DominioResource
{
    /**
     * @param iterable<SaasPais> $paises
     *
     * @return array<int, array<string, mixed>>
     */
    public static function paises(iterable $paises): array
    {
        $itens = [];

        foreach ($paises as $pais) {
            $itens[] = [
                'cd_pais' => $pais->cd_pais,
                'ds_pais' => $pais->ds_pais,
                'ds_nacionalidade' => $pais->ds_nacionalidade,
            ];
        }

        return $itens;
    }

    /**
     * @param iterable<SaasEstadoCivil> $estadosCivis
     *
     * @return array<int, array<string, mixed>>
     */
    public static function estadosCivis(iterable $estadosCivis): array
    {
        $itens = [];

        foreach ($estadosCivis as $estadoCivil) {
            $itens[] = [
                'cd_estado_civil' => $estadoCivil->cd_estado_civil,
                'ds_estado_civil' => $estadoCivil->ds_estado_civil,
            ];
        }

        return $itens;
    }

    /**
     * @param iterable<UnimPessoaContatoTipo> $tipos
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tiposDeContato(iterable $tipos): array
    {
        $itens = [];

        foreach ($tipos as $tipo) {
            $itens[] = [
                'cd_tipo' => $tipo->cd_tipo,
                'ds_descricao' => $tipo->ds_descricao,
                'ds_chave' => $tipo->ds_chave,
            ];
        }

        return $itens;
    }
}
```

- [ ] **Step 4: Crie os três schemas de documentação**

Uma classe por schema: `#[OA\Schema]` não é repetível na mesma classe.

`app/Swagger/PaisSchema.php`:

```php
<?php

declare(strict_types=1);

namespace App\Swagger;

use Hyperf\Swagger\Annotation as OA;

/**
 * Classe só de documentação. Ver App\Swagger\PessoaSchema.
 */
#[OA\Schema(
    schema: 'Pais',
    description: 'País do catálogo global saas_pais. Catálogo NÃO tem escopo de cliente: '
        . 'a mesma lista vale para todos os tenants, diferente de tudo em /pessoas.',
    properties: [
        new OA\Property(property: 'cd_pais', description: 'Identificador do país, usado em cd_pais e cd_pais_nascimento do endereço.', type: 'integer', example: 1),
        new OA\Property(property: 'ds_pais', type: 'string', example: 'Brasil', nullable: true),
        new OA\Property(property: 'ds_nacionalidade', type: 'string', example: 'Brasileira', nullable: true),
    ],
    type: 'object'
)]
final class PaisSchema
{
}
```

`app/Swagger/EstadoCivilSchema.php`:

```php
<?php

declare(strict_types=1);

namespace App\Swagger;

use Hyperf\Swagger\Annotation as OA;

/**
 * Classe só de documentação. Ver App\Swagger\PessoaSchema.
 */
#[OA\Schema(
    schema: 'EstadoCivil',
    description: 'Estado civil do catálogo global saas_estado_civil. É o destino da FK '
        . 'de fisica.cd_estado_civil: use esta rota para traduzir o código, porque a leitura '
        . 'de pessoa devolve o código e não o rótulo.',
    properties: [
        new OA\Property(property: 'cd_estado_civil', type: 'integer', example: 37),
        new OA\Property(property: 'ds_estado_civil', type: 'string', example: 'Solteiro(a)', nullable: true),
    ],
    type: 'object'
)]
final class EstadoCivilSchema
{
}
```

`app/Swagger/ContatoTipoSchema.php`:

```php
<?php

declare(strict_types=1);

namespace App\Swagger;

use Hyperf\Swagger\Annotation as OA;

/**
 * Classe só de documentação. Ver App\Swagger\PessoaSchema.
 */
#[OA\Schema(
    schema: 'ContatoTipo',
    description: 'Tipo de contato do catálogo global unim_pessoa_contato_tipo. As chaves são '
        . 'as do LMS: TELEFONE, TELEFONE-COMERCIAL, TELEFONE-CELULAR, EMAIL, SITE.',
    properties: [
        new OA\Property(property: 'cd_tipo', type: 'integer', example: 34),
        new OA\Property(property: 'ds_descricao', type: 'string', example: 'E-mail'),
        new OA\Property(property: 'ds_chave', type: 'string', example: 'EMAIL'),
    ],
    type: 'object'
)]
final class ContatoTipoSchema
{
}
```

- [ ] **Step 5: Crie o controller com as três actions**

Sem Service: catálogo read-only não tem regra de negócio a hospedar, e um Service aqui seria repasse vazio. O controller injeta o repositório direto, com `#[Inject]` como o resto do projeto.

**`#[OA\HyperfServer(name: 'http')]` na classe é obrigatório.** Sem ele o `gen:swagger` publica os schemas mas **não** os paths — a documentação fica com `Pais` em `components` e nenhuma rota em `paths`, que é exatamente a falha silenciosa da regra 1. `PessoaController` e `AuthController` já o declaram; siga a convenção. (Corrigido durante a execução da Task 4, que descobriu a omissão empiricamente.)

`app/Controller/Dominio/DominioController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Dominio;

use App\Controller\AbstractController;
use App\Repository\Dominio\DominioRepositoryInterface;
use App\Resource\Dominio\DominioResource;
use App\Support\ApiResponse;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Swagger\Annotation as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * Catálogos que alimentam o cadastro de pessoa.
 *
 * Sem Service no meio de propósito: não há regra de negócio nenhuma a hospedar aqui, e um
 * Service seria repasse vazio do repositório.
 *
 * Nenhuma destas rotas tem escopo de tenant, porque nenhuma das tabelas tem cd_cliente.
 * ACL reusa GERENCIAR_PESSOA + ACESSAR: é o único par que existe em
 * ulms_recurso_privilegio para este domínio, e chave inventada nega tudo em silêncio.
 */
class DominioController extends AbstractController
{
    #[Inject]
    protected DominioRepositoryInterface $dominioRepository;

    #[OA\Get(
        path: '/paises',
        summary: 'Lista os países do catálogo global',
        description: 'Catálogo global, sem escopo de cliente e sem paginação — são poucas linhas. '
            . 'A resposta NÃO tem `meta`, diferente de GET /pessoas.',
        tags: ['Domínio']
    )]
    #[OA\Response(
        response: 200,
        description: 'Lista completa de países, ordenada por ds_pais.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Pais')),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    public function paises(): ResponseInterface
    {
        return $this->response->json(
            ApiResponse::sucesso(DominioResource::paises($this->dominioRepository->paises()))
        );
    }

    #[OA\Get(
        path: '/estados-civis',
        summary: 'Lista os estados civis do catálogo global',
        description: 'Catálogo global, sem escopo de cliente, sem paginação e sem `meta`. '
            . 'Use para traduzir fisica.cd_estado_civil: a leitura de pessoa devolve o código, não o rótulo.',
        tags: ['Domínio']
    )]
    #[OA\Response(
        response: 200,
        description: 'Lista completa de estados civis, ordenada por cd_estado_civil.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/EstadoCivil')),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    public function estadosCivis(): ResponseInterface
    {
        return $this->response->json(
            ApiResponse::sucesso(DominioResource::estadosCivis($this->dominioRepository->estadosCivis()))
        );
    }

    #[OA\Get(
        path: '/contato-tipos',
        summary: 'Lista os tipos de contato do catálogo global',
        description: 'Catálogo global, sem escopo de cliente, sem paginação e sem `meta`. '
            . 'O cd_tipo devolvido aqui é o que o cadastro de contato exige.',
        tags: ['Domínio']
    )]
    #[OA\Response(
        response: 200,
        description: 'Lista completa de tipos de contato, ordenada por cd_tipo.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContatoTipo')),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    public function tiposDeContato(): ResponseInterface
    {
        return $this->response->json(
            ApiResponse::sucesso(DominioResource::tiposDeContato($this->dominioRepository->tiposDeContato()))
        );
    }
}
```

- [ ] **Step 6: Registre as três rotas**

Em `config/routes.php`, acrescente o import e, ao fim do arquivo, o bloco. Sem `ValidationMiddleware`: nenhuma das três recebe parâmetro, então não há o que validar.

```php
use App\Controller\Dominio\DominioController;
```

```php
// Catálogos que alimentam o cadastro de pessoa. Globais: nenhuma das tabelas tem
// cd_cliente, então não há escopo de tenant aqui — ao contrário de /pessoas.
// O ACL reusa GERENCIAR_PESSOA + ACESSAR porque é o único par existente em
// ulms_recurso_privilegio para este domínio, e chave inventada nega tudo em silêncio.
// Sem ValidationMiddleware nestas três: não recebem parâmetro nenhum.
Router::get('/paises', [DominioController::class, 'paises'], [
    'acl' => ['recurso' => Recurso::GERENCIAR_PESSOA, 'privilegio' => Privilegio::ACESSAR],
    'middleware' => [AuthMiddleware::class, AclMiddleware::class],
]);

Router::get('/estados-civis', [DominioController::class, 'estadosCivis'], [
    'acl' => ['recurso' => Recurso::GERENCIAR_PESSOA, 'privilegio' => Privilegio::ACESSAR],
    'middleware' => [AuthMiddleware::class, AclMiddleware::class],
]);

Router::get('/contato-tipos', [DominioController::class, 'tiposDeContato'], [
    'acl' => ['recurso' => Recurso::GERENCIAR_PESSOA, 'privilegio' => Privilegio::ACESSAR],
    'middleware' => [AuthMiddleware::class, AclMiddleware::class],
]);
```

- [ ] **Step 7: Run tests to verify they pass**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php \
  test/Cases/Controller/Dominio/DominioControllerTest.php
```

Esperado: PASS, 4 testes. Se vier 403 em vez de 200, o par ACL do Redis não bate com o declarado na rota. Se vier 404, a rota não foi carregada — o container precisa de restart quando `config/` muda e o watcher não está ligado.

- [ ] **Step 8: Regenere a documentação e confira o artefato**

```bash
docker exec lumina php /opt/www/bin/hyperf.php gen:swagger
python3 -c "import json; d=json.load(open('storage/swagger/http.json')); print(json.dumps(d['paths']['/paises']['get'], ensure_ascii=False, indent=2))"
python3 -c "import json; d=json.load(open('storage/swagger/http.json')); print(sorted(d['components']['schemas'].keys()))"
```

Esperado: o `get` de `/paises` com `content`/`JsonContent` apontando `#/components/schemas/Pais`, e `Pais`, `EstadoCivil`, `ContatoTipo` na lista de schemas. Editar o atributo não publica nada — quem serve a documentação é o `lumina-docs` lendo este arquivo estático.

- [ ] **Step 9: Feche a tarefa**

```bash
docker exec lumina composer cs-fix
docker exec lumina composer test
git add app/Resource/Dominio app/Controller/Dominio app/Swagger/PaisSchema.php \
        app/Swagger/EstadoCivilSchema.php app/Swagger/ContatoTipoSchema.php \
        config/routes.php storage/swagger/http.json \
        test/Cases/Controller/Dominio/DominioControllerTest.php
git commit -m "feat: GET /paises, /estados-civis e /contato-tipos

Primeira fatia usavel dos dominios: o cliente ja descobre estado civil e tipo de
contato sem abrir o banco.

Sem paginacao e sem meta -- sao poucas linhas e paginar duas linhas e cerimonia.
Sem Service: catalogo read-only nao tem regra de negocio, um Service seria repasse
vazio. Sem ValidationMiddleware: nenhuma das tres recebe parametro.

Resource recorta campo por campo porque toArray() vazaria dt_base, controle do LMS.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Rotas de domínio com parâmetro (`/estados`, `/cidades`)

`/cidades` é a razão de `/paises` existir separada: `saas_cidade` tem 4928 linhas e a rota **exige** `cd_estado`. Aqui entram os dois `FormRequest` e, com eles, o `ValidationMiddleware`.

**Files:**
- Create: `app/Request/Dominio/ListEstadoRequest.php`
- Create: `app/Request/Dominio/ListCidadeRequest.php`
- Create: `app/Swagger/EstadoSchema.php`
- Create: `app/Swagger/CidadeSchema.php`
- Modify: `app/Resource/Dominio/DominioResource.php`
- Modify: `app/Controller/Dominio/DominioController.php`
- Modify: `config/routes.php`
- Test: `test/Cases/Controller/Dominio/DominioControllerTest.php`

**Interfaces:**
- Consumes: `DominioRepositoryInterface::estados()`/`::cidades()` (Task 3); `DominioResource` e `DominioController` (Task 4).
- Produces:
  - `DominioResource::estados(iterable $estados): array<int, array<string, mixed>>`
  - `DominioResource::cidades(iterable $cidades): array<int, array<string, mixed>>`
  - `DominioController::estados(ListEstadoRequest $request): ResponseInterface`
  - `DominioController::cidades(ListCidadeRequest $request): ResponseInterface`
  - Schemas OpenAPI `Estado` e `Cidade`

- [ ] **Step 1: Write the failing test**

Acrescente a `test/Cases/Controller/Dominio/DominioControllerTest.php`, antes do helper `headers()`:

```php
    public function testEstadosFiltradosPorPais()
    {
        $cdPais = $this->get('/paises', [], $this->headers())->json('data')[0]['cd_pais'];

        $resposta = $this->get('/estados', ['cd_pais' => $cdPais], $this->headers());

        $resposta->assertStatus(200);
        $dados = $resposta->json('data');

        $this->assertNotEmpty($dados);
        $this->assertSame(['cd_estado', 'cd_pais', 'ds_estado', 'ds_uf'], array_keys($dados[0]));

        foreach ($dados as $estado) {
            $this->assertSame($cdPais, $estado['cd_pais']);
        }
    }

    public function testCidadesSemEstadoResponde422()
    {
        $resposta = $this->get('/cidades', [], $this->headers());

        // Sem cd_estado a consulta varreria 4928 linhas. A rota recusa em vez de despejar.
        $resposta->assertStatus(422);
        $this->assertFalse($resposta->json('success'));
        $this->assertArrayHasKey('cd_estado', $resposta->json('errors'));
    }

    public function testCidadesDeUmEstadoNaoVazamOutroEstado()
    {
        $cdEstado = $this->get('/estados', [], $this->headers())->json('data')[0]['cd_estado'];

        $resposta = $this->get('/cidades', ['cd_estado' => $cdEstado], $this->headers());

        $resposta->assertStatus(200);
        $dados = $resposta->json('data');

        $this->assertNotEmpty($dados);
        $this->assertSame(['cd_cidade', 'cd_estado', 'ds_cidade'], array_keys($dados[0]));

        foreach ($dados as $cidade) {
            $this->assertSame($cdEstado, $cidade['cd_estado']);
        }
    }

    public function testCidadesFiltradasPorTermo()
    {
        $cdEstado = $this->get('/estados', [], $this->headers())->json('data')[0]['cd_estado'];
        $primeira = $this->get('/cidades', ['cd_estado' => $cdEstado], $this->headers())->json('data')[0];

        $termo = mb_substr((string) $primeira['ds_cidade'], 0, 3);

        $resposta = $this->get('/cidades', ['cd_estado' => $cdEstado, 'q' => $termo], $this->headers());

        $resposta->assertStatus(200);
        $dados = $resposta->json('data');

        $this->assertNotEmpty($dados);

        foreach ($dados as $cidade) {
            $this->assertStringContainsStringIgnoringCase($termo, (string) $cidade['ds_cidade']);
        }
    }
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php \
  test/Cases/Controller/Dominio/DominioControllerTest.php --filter testCidadesSemEstadoResponde422
```

Esperado: FAIL. `/cidades` não existe: 404 onde o teste espera 422.

- [ ] **Step 3: Crie os dois `FormRequest`**

`app/Request/Dominio/ListEstadoRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Request\Dominio;

use Hyperf\Validation\Request\FormRequest;

class ListEstadoRequest extends FormRequest
{
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
            'cd_pais' => 'sometimes|integer|min:1',
        ];
    }
}
```

`app/Request/Dominio/ListCidadeRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Request\Dominio;

use Hyperf\Validation\Request\FormRequest;

/**
 * cd_estado é obrigatório e isso não é rigor gratuito: saas_cidade tem 4928 linhas e a
 * rota não pagina. Sem o filtro, uma chamada devolveria o catálogo inteiro.
 */
class ListCidadeRequest extends FormRequest
{
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
            'cd_estado' => 'required|integer|min:1',
            'q' => 'sometimes|string|min:1|max:255',
        ];
    }
}
```

- [ ] **Step 4: Crie os dois schemas**

`app/Swagger/EstadoSchema.php`:

```php
<?php

declare(strict_types=1);

namespace App\Swagger;

use Hyperf\Swagger\Annotation as OA;

/**
 * Classe só de documentação. Ver App\Swagger\PessoaSchema.
 */
#[OA\Schema(
    schema: 'Estado',
    description: 'Estado (unidade federativa) do catálogo global saas_estado. Sem escopo de cliente.',
    properties: [
        new OA\Property(property: 'cd_estado', description: 'Identificador do estado, usado em cd_estado e cd_estado_nascimento do endereço.', type: 'integer', example: 26),
        new OA\Property(property: 'cd_pais', type: 'integer', example: 1),
        new OA\Property(property: 'ds_estado', type: 'string', example: 'São Paulo', nullable: true),
        new OA\Property(property: 'ds_uf', type: 'string', example: 'SP', nullable: true),
    ],
    type: 'object'
)]
final class EstadoSchema
{
}
```

`app/Swagger/CidadeSchema.php`:

```php
<?php

declare(strict_types=1);

namespace App\Swagger;

use Hyperf\Swagger\Annotation as OA;

/**
 * Classe só de documentação. Ver App\Swagger\PessoaSchema.
 */
#[OA\Schema(
    schema: 'Cidade',
    description: 'Cidade do catálogo global saas_cidade. Sem escopo de cliente. '
        . 'A tabela tem quase 5 mil linhas, por isso a rota exige cd_estado e nunca devolve o catálogo inteiro.',
    properties: [
        new OA\Property(property: 'cd_cidade', description: 'Identificador da cidade, usado em cd_cidade e cd_cidade_nascimento do endereço.', type: 'integer', example: 5270),
        new OA\Property(property: 'cd_estado', type: 'integer', example: 26),
        new OA\Property(property: 'ds_cidade', type: 'string', example: 'São Paulo', nullable: true),
    ],
    type: 'object'
)]
final class CidadeSchema
{
}
```

- [ ] **Step 5: Acrescente os dois métodos ao Resource**

Em `app/Resource/Dominio/DominioResource.php`, importe os models novos e acrescente os métodos (mantenha a ordem alfabética dos `use`, que o cs-fixer exige):

```php
use App\Model\Dominio\SaasCidade;
use App\Model\Dominio\SaasEstado;
```

```php
    /**
     * @param iterable<SaasEstado> $estados
     *
     * @return array<int, array<string, mixed>>
     */
    public static function estados(iterable $estados): array
    {
        $itens = [];

        foreach ($estados as $estado) {
            $itens[] = [
                'cd_estado' => $estado->cd_estado,
                'cd_pais' => $estado->cd_pais,
                'ds_estado' => $estado->ds_estado,
                'ds_uf' => $estado->ds_uf,
            ];
        }

        return $itens;
    }

    /**
     * @param iterable<SaasCidade> $cidades
     *
     * @return array<int, array<string, mixed>>
     */
    public static function cidades(iterable $cidades): array
    {
        $itens = [];

        foreach ($cidades as $cidade) {
            $itens[] = [
                'cd_cidade' => $cidade->cd_cidade,
                'cd_estado' => $cidade->cd_estado,
                'ds_cidade' => $cidade->ds_cidade,
            ];
        }

        return $itens;
    }
```

- [ ] **Step 6: Acrescente as duas actions ao controller**

Em `app/Controller/Dominio/DominioController.php`, importe o que falta:

```php
use App\Request\Dominio\ListCidadeRequest;
use App\Request\Dominio\ListEstadoRequest;
use App\Support\Tipo;
```

E acrescente as actions. `Tipo::mapa()`/`Tipo::inteiro()` existem porque `validated()` devolve `array<string, mixed>` mesmo quando a regra garante inteiro, e no nível 10 `(int) $mixed` é erro com razão.

```php
    #[OA\Get(
        path: '/estados',
        summary: 'Lista os estados do catálogo global',
        description: 'Catálogo global, sem escopo de cliente, sem paginação e sem `meta`. '
            . 'Omitir cd_pais devolve os estados de todos os países.',
        tags: ['Domínio']
    )]
    #[OA\Parameter(
        name: 'cd_pais',
        in: 'query',
        required: false,
        description: 'Filtra por país. Omitido, devolve todos os estados. Obtenha o código em GET /paises.',
        schema: new OA\Schema(type: 'integer', example: 1)
    )]
    #[OA\Response(
        response: 200,
        description: 'Lista de estados, ordenada por ds_estado.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Estado')),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 422, description: 'cd_pais não é inteiro válido', content: new OA\JsonContent(ref: '#/components/schemas/ErroValidacao'))]
    public function estados(ListEstadoRequest $request): ResponseInterface
    {
        $validado = Tipo::mapa($request->validated());
        $cdPais = isset($validado['cd_pais']) ? Tipo::inteiro($validado['cd_pais']) : null;

        return $this->response->json(
            ApiResponse::sucesso(DominioResource::estados($this->dominioRepository->estados($cdPais)))
        );
    }

    #[OA\Get(
        path: '/cidades',
        summary: 'Lista as cidades de um estado',
        description: 'Catálogo global, sem escopo de cliente, sem paginação e sem `meta`. '
            . 'cd_estado é OBRIGATÓRIO: a tabela tem quase 5 mil linhas e a rota não devolve o catálogo inteiro. '
            . 'Sem ele a resposta é 422.',
        tags: ['Domínio']
    )]
    #[OA\Parameter(
        name: 'cd_estado',
        in: 'query',
        required: true,
        description: 'Estado das cidades. Obrigatório — sem ele a resposta é 422. Obtenha o código em GET /estados.',
        schema: new OA\Schema(type: 'integer', example: 26)
    )]
    #[OA\Parameter(
        name: 'q',
        in: 'query',
        required: false,
        description: 'Filtra ds_cidade por trecho do nome (LIKE %q%), sem distinção de acento ou caixa (collation do banco). Omitido, devolve todas as cidades do estado.',
        schema: new OA\Schema(type: 'string', example: 'camp')
    )]
    #[OA\Response(
        response: 200,
        description: 'Lista de cidades do estado pedido, ordenada por ds_cidade.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Cidade')),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 422, description: 'cd_estado ausente ou inválido', content: new OA\JsonContent(ref: '#/components/schemas/ErroValidacao'))]
    public function cidades(ListCidadeRequest $request): ResponseInterface
    {
        $validado = Tipo::mapa($request->validated());
        $cdEstado = Tipo::inteiro($validado['cd_estado'] ?? null);
        $q = isset($validado['q']) ? Tipo::texto($validado['q']) : null;

        return $this->response->json(
            ApiResponse::sucesso(DominioResource::cidades($this->dominioRepository->cidades($cdEstado, $q)))
        );
    }
```

- [ ] **Step 7: Registre as duas rotas**

Em `config/routes.php`, junto das outras três de domínio. Estas **têm** `ValidationMiddleware`, e ele vem **depois** de `AuthMiddleware`/`AclMiddleware` na mesma lista: token ausente barra em 401 antes de a validação revelar o contrato da rota.

```php
// Estas duas recebem parâmetro, então entram com ValidationMiddleware — depois de
// Auth/Acl na mesma lista, para token inválido barrar em 401 antes de a validação
// contar ao cliente quais parâmetros existem.
Router::get('/estados', [DominioController::class, 'estados'], [
    'acl' => ['recurso' => Recurso::GERENCIAR_PESSOA, 'privilegio' => Privilegio::ACESSAR],
    'middleware' => [AuthMiddleware::class, AclMiddleware::class, ValidationMiddleware::class],
]);

Router::get('/cidades', [DominioController::class, 'cidades'], [
    'acl' => ['recurso' => Recurso::GERENCIAR_PESSOA, 'privilegio' => Privilegio::ACESSAR],
    'middleware' => [AuthMiddleware::class, AclMiddleware::class, ValidationMiddleware::class],
]);
```

- [ ] **Step 8: Run tests to verify they pass**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php \
  test/Cases/Controller/Dominio/DominioControllerTest.php
```

Esperado: PASS, 8 testes.

- [ ] **Step 9: Regenere a documentação e confira o artefato**

```bash
docker exec lumina php /opt/www/bin/hyperf.php gen:swagger
python3 -c "import json; d=json.load(open('storage/swagger/http.json')); g=d['paths']['/cidades']['get']; print([p['name'] for p in g['parameters']], sorted(g['responses'].keys()))"
```

Esperado: `['cd_estado', 'q']` e os status `200`, `401`, `403`, `422`.

- [ ] **Step 10: Feche a tarefa**

```bash
docker exec lumina composer cs-fix
docker exec lumina composer test
git add app/Request/Dominio app/Swagger/EstadoSchema.php app/Swagger/CidadeSchema.php \
        app/Resource/Dominio/DominioResource.php app/Controller/Dominio/DominioController.php \
        config/routes.php storage/swagger/http.json \
        test/Cases/Controller/Dominio/DominioControllerTest.php
git commit -m "feat: GET /estados e /cidades

/cidades exige cd_estado e responde 422 sem ele: saas_cidade tem 4928 linhas e a
rota nao pagina, entao sem filtro despejaria o catalogo inteiro. cd_pais em
/estados e opcional.

ValidationMiddleware entra nestas duas (as unicas com FormRequest) e depois de
Auth/Acl na mesma lista, para token invalido barrar em 401 antes de a validacao
revelar o contrato.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Dez campos de pessoa física na leitura, PII fora do default

A partir daqui a entrega volta para `/pessoas`. Esta tarefa é só leitura: os campos passam a ser selecionáveis e a PII sai do default do detalhe. A escrita vem nas Tasks 7 e 8.

Há uma armadilha de serialização a resolver junto. `PessoaResource` usa `getAttribute()`, e com cast `date` isso devolve um `Carbon`, não string. O formato `date:Y-m-d` do Hyperf só é aplicado dentro de `toArray()` (`HasAttributes::addCastAttributesToArray`), que o Resource deliberadamente não usa. Sem tratamento, `dt_nascimento` sairia no JSON como `1990-05-12T00:00:00.000000Z` em vez de `1990-05-12`.

**Files:**
- Modify: `app/Resource/Pessoa/MapaDeCamposPessoa.php`
- Modify: `app/Resource/Pessoa/PessoaResource.php`
- Modify: `app/Repository/Pessoa/PessoaRepository.php:139,157`
- Test: `test/Cases/Resource/Pessoa/PessoaResourceTest.php`
- Test: `test/Cases/Controller/Pessoa/PessoaControllerTest.php`

**Interfaces:**
- Consumes: `Campo::relacao(..., sensivel: true)` e `SelecaoDeCampos::completa()` (Task 1).
- Produces: as dez chaves novas do mapa, exatamente com estes nomes — a Task 8 (escrita) e a Task 9 (Swagger) dependem deles:
  `fisica.ds_nome_social`, `fisica.ds_nome_mae`, `fisica.ds_nome_pai`, `fisica.ds_identidade`, `fisica.ds_orgao_estado`, `fisica.ds_identidade_orgao_exp`, `fisica.dt_identidade_expedicao`, `fisica.dt_nascimento`, `fisica.ds_sexo`, `fisica.cd_estado_civil`.
  Sensíveis: `fisica.ds_cpf`, `fisica.ds_identidade`, `fisica.ds_nome_mae`, `fisica.ds_nome_pai`, `fisica.dt_nascimento`.

- [ ] **Step 1: Write the failing test (unidade, no Resource)**

Acrescente a `test/Cases/Resource/Pessoa/PessoaResourceTest.php`:

```php
    public function testDefaultDoItemNaoTrazPiiMasCuringaTraz()
    {
        $pessoa = new UnimPessoa(['ds_nome' => 'Ana Souza']);
        $pessoa->setRelation('fisica', new UnimPessoaFisica([
            'ds_nome_oficial' => 'Ana Souza',
            'ds_cpf' => '12345678909',
            'ds_nome_mae' => 'Maria Souza',
        ]));

        $semFields = PessoaResource::um($pessoa, MapaDeCamposPessoa::selecao(null, padraoEhTudo: true));

        $this->assertIsArray($semFields['fisica']);
        $this->assertArrayHasKey('ds_nome_oficial', $semFields['fisica']);
        $this->assertArrayNotHasKey('ds_cpf', $semFields['fisica']);
        $this->assertArrayNotHasKey('ds_nome_mae', $semFields['fisica']);

        $comCuringa = PessoaResource::um($pessoa, MapaDeCamposPessoa::selecao('fisica.*'));

        $this->assertIsArray($comCuringa['fisica']);
        $this->assertSame('12345678909', $comCuringa['fisica']['ds_cpf']);
        $this->assertSame('Maria Souza', $comCuringa['fisica']['ds_nome_mae']);
    }

    public function testRespostaDeEscritaTrazPii()
    {
        $pessoa = new UnimPessoa(['ds_nome' => 'Ana Souza']);
        $pessoa->setRelation('fisica', new UnimPessoaFisica([
            'ds_nome_oficial' => 'Ana Souza',
            'ds_cpf' => '12345678909',
        ]));

        // Sem seleção = caminho de POST/PUT/PATCH. Filtrar aqui esconderia o que o
        // servidor acabou de gravar.
        $escrita = PessoaResource::um($pessoa);

        $this->assertIsArray($escrita['fisica']);
        $this->assertSame('12345678909', $escrita['fisica']['ds_cpf']);
    }

    public function testDataSaiComoYmdENaoComoDatetimeIso()
    {
        $pessoa = new UnimPessoa(['ds_nome' => 'Ana Souza']);
        $pessoa->setRelation('fisica', new UnimPessoaFisica(['dt_nascimento' => '1990-05-12']));

        $saida = PessoaResource::um($pessoa, MapaDeCamposPessoa::selecao('fisica.dt_nascimento'));

        // getAttribute() devolve Carbon: sem tratamento no Resource o JSON sairia
        // "1990-05-12T00:00:00.000000Z".
        $this->assertIsArray($saida['fisica']);
        $this->assertSame('1990-05-12', $saida['fisica']['dt_nascimento']);
    }
```

Confira que o `use` de `UnimPessoaFisica` e `MapaDeCamposPessoa` está presente no topo do arquivo; se não estiver, acrescente:

```php
use App\Model\Pessoa\UnimPessoaFisica;
use App\Resource\Pessoa\MapaDeCamposPessoa;
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php \
  test/Cases/Resource/Pessoa/PessoaResourceTest.php --filter testDefaultDoItemNaoTrazPii
```

Esperado: FAIL. `MapaDeCamposPessoa` ainda não conhece `ds_nome_mae`, então `SelecaoDeCampos::campo()` lança `LogicException` ou a chave simplesmente não existe na saída.

- [ ] **Step 3: Acrescente os dez campos ao mapa**

Substitua o corpo de `mapa()` em `app/Resource/Pessoa/MapaDeCamposPessoa.php`:

```php
    public static function mapa(): array
    {
        return [
            'cd_pessoa' => Campo::coluna('cd_pessoa', noPadrao: true),
            'cd_cliente' => Campo::coluna('cd_cliente'),
            'ds_nome' => Campo::coluna('ds_nome', noPadrao: true),
            'ds_login' => Campo::coluna('ds_login', noPadrao: true),
            'sn_pessoa_juridica' => Campo::coluna('sn_pessoa_juridica', noPadrao: true),
            'fisica.ds_nome_oficial' => Campo::relacao('fisica', 'ds_nome_oficial', self::CHAVE_LOCAL),
            'fisica.ds_nome_social' => Campo::relacao('fisica', 'ds_nome_social', self::CHAVE_LOCAL),
            'fisica.ds_nome_mae' => Campo::relacao('fisica', 'ds_nome_mae', self::CHAVE_LOCAL, sensivel: true),
            'fisica.ds_nome_pai' => Campo::relacao('fisica', 'ds_nome_pai', self::CHAVE_LOCAL, sensivel: true),
            'fisica.ds_cpf' => Campo::relacao('fisica', 'ds_cpf', self::CHAVE_LOCAL, sensivel: true),
            'fisica.ds_identidade' => Campo::relacao('fisica', 'ds_identidade', self::CHAVE_LOCAL, sensivel: true),
            'fisica.ds_orgao_estado' => Campo::relacao('fisica', 'ds_orgao_estado', self::CHAVE_LOCAL),
            'fisica.ds_identidade_orgao_exp' => Campo::relacao('fisica', 'ds_identidade_orgao_exp', self::CHAVE_LOCAL),
            'fisica.dt_identidade_expedicao' => Campo::relacao('fisica', 'dt_identidade_expedicao', self::CHAVE_LOCAL),
            'fisica.dt_nascimento' => Campo::relacao('fisica', 'dt_nascimento', self::CHAVE_LOCAL, sensivel: true),
            'fisica.ds_sexo' => Campo::relacao('fisica', 'ds_sexo', self::CHAVE_LOCAL),
            'fisica.cd_estado_civil' => Campo::relacao('fisica', 'cd_estado_civil', self::CHAVE_LOCAL),
            'juridica.ds_cnpj' => Campo::relacao('juridica', 'ds_cnpj', self::CHAVE_LOCAL),
            'juridica.ds_nome_fantasia' => Campo::relacao('juridica', 'ds_nome_fantasia', self::CHAVE_LOCAL),
        ];
    }
```

E acrescente ao PHPDoc de `mapa()`, junto do aviso de manutenção que já está lá:

```php
     * PII (sensivel: true) sai do default de GET /pessoas/{id} e só vem se pedida por nome
     * ou por curinga. Resposta de escrita traz sempre — ver PessoaResource.
```

Não há `@property` a acrescentar: `UnimPessoaFisica` já declara as 13 colunas.

- [ ] **Step 4: Trate a data no Resource e use `completa()` na escrita**

Em `app/Resource/Pessoa/PessoaResource.php`, acrescente um único import — `MapaDeCamposPessoa` é do mesmo namespace e `SelecaoDeCampos` já está importado:

```php
use DateTimeInterface;
```

Troque a linha do default:

```php
        $selecao ??= MapaDeCamposPessoa::selecao(null, padraoEhTudo: true);
```

por:

```php
        // completa() e não selecao(padraoEhTudo: true): sem seleção significa resposta de
        // ESCRITA, e ali a PII tem de vir — filtrar esconderia o que o servidor gravou.
        $selecao ??= SelecaoDeCampos::completa(MapaDeCamposPessoa::mapa(), MapaDeCamposPessoa::CHAVE_LOCAL);
```

Troque as duas leituras de atributo para passar pelo normalizador. A de coluna direta:

```php
            if (! $campo->ehDeRelacao()) {
                $saida[$chave] = self::valor($pessoa->getAttribute($campo->coluna));

                continue;
            }
```

E a de relação:

```php
            $valores[$campo->coluna] = self::valor($filho->getAttribute($campo->coluna));
```

Acrescente o método privado ao fim da classe:

```php
    /**
     * Data vira 'Y-m-d'. getAttribute() devolve Carbon quando a coluna tem cast date, e o
     * formato declarado em $casts (date:Y-m-d) só é aplicado dentro de toArray()
     * (HasAttributes::addCastAttributesToArray) — que este Resource não usa, de propósito,
     * porque toArray() exporia coluna que o mapa não expõe. Sem isto o JSON sairia
     * "1990-05-12T00:00:00.000000Z" onde a documentação promete "1990-05-12".
     *
     * Hoje todo campo de data exposto é data pura. No dia em que um datetime entrar no
     * mapa, esta regra precisa passar a distinguir os dois.
     */
    private static function valor(mixed $valor): mixed
    {
        return $valor instanceof DateTimeInterface ? $valor->format('Y-m-d') : $valor;
    }
```

E acrescente `use App\Support\Campos\SelecaoDeCampos;` se ainda não estiver importado (está — o parâmetro do método já o usa).

- [ ] **Step 5: Ajuste o fallback do repositório**

Em `app/Repository/Pessoa/PessoaRepository.php`, `buscarPorId()` (linha ~139) e `listar()` (linha ~157) têm:

```php
        $selecao ??= MapaDeCamposPessoa::selecao(null, padraoEhTudo: true);
```

Troque as duas por:

```php
        // completa(): este fallback é leitura INTERNA (PessoaService::atualizarParcial()
        // chama buscar() sem seleção só para descobrir o tipo da pessoa). Regra de
        // exposição de PII é do contrato HTTP, e quem a aplica é o Controller.
        $selecao ??= SelecaoDeCampos::completa(MapaDeCamposPessoa::mapa(), MapaDeCamposPessoa::CHAVE_LOCAL);
```

`SelecaoDeCampos` já está importado no arquivo.

- [ ] **Step 6: Write the failing HTTP test**

Acrescente a `test/Cases/Controller/Pessoa/PessoaControllerTest.php`, antes do helper `headers()`:

```php
    public function testDetalheNaoDevolvePiiSemPedidoExplicito()
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste PII',
            'ds_login' => 'teste.http.pii',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste PII Oficial',
            'ds_cpf' => '12345678909',
        ], $this->headers());

        $criar->assertStatus(201);
        // Resposta de escrita traz PII: o cliente precisa confirmar o que foi gravado.
        $this->assertSame('12345678909', $criar->json('data.fisica.ds_cpf'));

        $cdPessoa = $criar->json('data.cd_pessoa');

        $semFields = $this->get("/pessoas/{$cdPessoa}", [], $this->headers());
        $semFields->assertStatus(200);
        $this->assertArrayNotHasKey('ds_cpf', $semFields->json('data.fisica'));

        $porNome = $this->get("/pessoas/{$cdPessoa}", ['fields' => 'fisica.ds_cpf'], $this->headers());
        $porNome->assertStatus(200);
        $this->assertSame('12345678909', $porNome->json('data.fisica.ds_cpf'));

        $porCuringa = $this->get("/pessoas/{$cdPessoa}", ['fields' => 'fisica.*'], $this->headers());
        $porCuringa->assertStatus(200);
        $this->assertSame('12345678909', $porCuringa->json('data.fisica.ds_cpf'));
    }
```

- [ ] **Step 7: Estenda o teste de eager load parcial com um campo novo**

`test/Cases/Repository/Pessoa/PessoaRepositoryTest.php:272` já tem `testEagerLoadParcialTrazAFkEPortantoCasaPaiEFilho`, que guarda a falha silenciosa mais perigosa do projeto: `select` de relação sem a chave estrangeira faz o Eloquent devolver a relação como `null` **sem erro nenhum**. Acrescente um campo novo ao `fields` desse teste — por exemplo trocando `fisica.ds_cpf` por `fisica.ds_cpf,fisica.dt_nascimento` na seleção que ele monta — e mantenha o `assertNotNull($pessoa->fisica)` existente. Não crie um teste novo: o mecanismo é o mesmo, só a superfície cresceu.

Isolamento de tenant em `GET /pessoas/{id}` **já está coberto** por `test/Cases/Controller/EndToEndFlowTest.php:159-164`, que cria a pessoa com um token e prova 404 com o token de outro cliente. Não duplique — o guarda é o mesmo `where('cd_cliente', ...)` e os dez campos novos passam por ele.

- [ ] **Step 8: Run tests to verify they pass**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Resource/Pessoa/PessoaResourceTest.php
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Controller/Pessoa/PessoaControllerTest.php
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Repository/Pessoa/PessoaRepositoryTest.php
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Controller/EndToEndFlowTest.php
```

Esperado: PASS nos quatro. O `PessoaRepositoryTest` entra na lista porque o fallback mudou — se algum teste dele afirmava a forma completa do default, ele continua verde por usar `completa()`. O `EndToEndFlowTest` entra porque é onde vive o isolamento de tenant do detalhe.

- [ ] **Step 9: Feche a tarefa**

```bash
docker exec lumina composer cs-fix
docker exec lumina composer test
git add app/Resource/Pessoa/MapaDeCamposPessoa.php app/Resource/Pessoa/PessoaResource.php \
        app/Repository/Pessoa/PessoaRepository.php \
        test/Cases/Resource/Pessoa/PessoaResourceTest.php \
        test/Cases/Repository/Pessoa/PessoaRepositoryTest.php \
        test/Cases/Controller/Pessoa/PessoaControllerTest.php
git commit -m "feat: dez campos de pessoa fisica na leitura, PII fora do default

unim_pessoa_fisica tem 13 colunas e o mapa expunha 2. As outras dez eram
inalcancaveis: o que nao esta no mapa nao existe para o cliente.

QUEBRA DE CONTRATO: ds_cpf sai do default de GET /pessoas/{id}. PII (cpf, rg,
filiacao, nascimento) passa a exigir pedido por nome ou curinga. Escrita continua
trazendo tudo.

Data sai como Y-m-d. getAttribute() devolve Carbon e o formato de \$casts so vale
dentro de toArray(), que o Resource nao usa de proposito -- sem tratamento o JSON
sairia 1990-05-12T00:00:00.000000Z.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Normalização e validação de formato nos `FormRequest` de pessoa

Só validação e normalização. Os campos ainda não chegam ao banco — quem os leva é a Task 8. Esta tarefa entrega os 422 corretos.

**Files:**
- Create: `app/Request/Pessoa/Concerns/NormalizaCamposDePessoa.php`
- Create: `app/Request/Pessoa/Concerns/ValidaDocumentosDePessoa.php`
- Modify: `app/Request/Pessoa/CreatePessoaRequest.php`
- Modify: `app/Request/Pessoa/UpdatePessoaRequest.php`
- Modify: `app/Request/Pessoa/PatchPessoaRequest.php`
- Modify: `storage/languages/en/validation.php`
- Test: `test/Cases/Request/Pessoa/CreatePessoaRequestTest.php`
- Test: `test/Cases/Controller/Pessoa/PessoaControllerTest.php`

**Interfaces:**
- Consumes: `Documento::apenasDigitos()`, `::cpfEhValido()`, `::cnpjEhValido()` (Task 2).
- Produces:
  - `trait NormalizaCamposDePessoa` com `protected function normalizarCamposDePessoa(array<string, mixed> $dados): array<string, mixed>`
  - `trait ValidaDocumentosDePessoa` com `protected function validarDocumentos(ValidatorInterface $validator): void`
  - Chaves de mensagem `cpf_invalido` e `cnpj_invalido` em `storage/languages/en/validation.php`

- [ ] **Step 1: Write the failing test**

Acrescente a `test/Cases/Controller/Pessoa/PessoaControllerTest.php`, antes de `headers()`. Todos estes provam **recusa** (422), que é o que esta tarefa entrega — a prova de que o valor normalizado chega ao banco é da Task 8, porque só lá os campos passam a ser persistidos.

```php
    public function testCpfComMascaraPassaPelaRegraDigits()
    {
        // A normalização roda em validationData(), ANTES das regras: sem ela, "123.456.789-09"
        // reprovaria em digits:11 e este teste veria 422. ds_cpf já é persistido hoje, então
        // aqui a asserção sobre o valor gravado é legítima antes da Task 8.
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Mascara',
            'ds_login' => 'teste.http.mascara',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Mascara Oficial',
            'ds_cpf' => '123.456.789-09',
        ], $this->headers());

        $criar->assertStatus(201);
        $this->assertSame('12345678909', $criar->json('data.fisica.ds_cpf'));
    }

    public function testCpfComDigitoVerificadorInvalidoResponde422ComFrase()
    {
        $resposta = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste CPF',
            'ds_login' => 'teste.http.cpfruim',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste CPF Oficial',
            'ds_cpf' => '12345678900',
        ], $this->headers());

        $resposta->assertStatus(422);
        $mensagem = $resposta->json('errors.ds_cpf')[0];

        // A mensagem tem de ser frase, não a chave crua: storage/languages é produção.
        $this->assertStringNotContainsString('validation.', $mensagem);
    }

    public function testSexoForaDoDominioResponde422()
    {
        $resposta = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Sexo',
            'ds_login' => 'teste.http.sexoruim',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Sexo Oficial',
            'ds_sexo' => 'x',
        ], $this->headers());

        $resposta->assertStatus(422);
        $this->assertArrayHasKey('ds_sexo', $resposta->json('errors'));
    }

    public function testEstadoCivilInexistenteResponde422ENao409()
    {
        $resposta = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Estado Civil',
            'ds_login' => 'teste.http.estadocivil',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Estado Civil Oficial',
            'cd_estado_civil' => 999999,
        ], $this->headers());

        // Sem a regra exists, a FK viraria SQLSTATE 23000 e o DatabaseExceptionHandler
        // devolveria 409 -- o mesmo status de "login ja existe", mandando quem investiga
        // para o lado errado.
        $resposta->assertStatus(422);
        $this->assertArrayHasKey('cd_estado_civil', $resposta->json('errors'));
    }

    public function testExpedicaoAnteriorAoNascimentoResponde422()
    {
        $resposta = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Datas',
            'ds_login' => 'teste.http.datas',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Datas Oficial',
            'dt_nascimento' => '1990-05-12',
            'dt_identidade_expedicao' => '1985-01-01',
        ], $this->headers());

        $resposta->assertStatus(422);
        $this->assertArrayHasKey('dt_identidade_expedicao', $resposta->json('errors'));
    }

    public function testNascimentoNoFuturoResponde422()
    {
        $resposta = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Futuro',
            'ds_login' => 'teste.http.futuro',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Futuro Oficial',
            'dt_nascimento' => '2099-01-01',
        ], $this->headers());

        $resposta->assertStatus(422);
        $this->assertArrayHasKey('dt_nascimento', $resposta->json('errors'));
    }
```

A normalização de `ds_sexo` e de string vazia não é testada aqui: os campos são validados nesta tarefa mas ainda não chegam ao banco, então não há resposta onde observá-los. O teste correspondente está na Task 8, step 1.

- [ ] **Step 2: Run test to verify it fails**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php \
  test/Cases/Controller/Pessoa/PessoaControllerTest.php --filter testSexoForaDoDominioResponde422
```

Esperado: FAIL com 201 onde o teste espera 422 — a regra `in:f,m` ainda não existe, então `ds_sexo: 'x'` passa.

- [ ] **Step 3: Crie o trait de normalização**

`app/Request/Pessoa/Concerns/NormalizaCamposDePessoa.php`:

```php
<?php

declare(strict_types=1);

namespace App\Request\Pessoa\Concerns;

use App\Support\Documento;

/**
 * Normaliza o payload ANTES de as regras rodarem, sobrepondo validationData().
 *
 * A ordem importa: `ds_cpf` com máscara ("123.456.789-09") reprovaria em digits:11 se
 * chegasse cru às regras. Normalizando antes, a regra vê só dígitos e validated() já
 * devolve o valor que vai para o banco.
 *
 * Consequência para quem consome: a resposta traz o valor normalizado, não o enviado.
 * Está dito no Swagger dos três endpoints de escrita.
 */
trait NormalizaCamposDePessoa
{
    /**
     * Documentos guardados só com dígitos: máscara na coluna quebraria busca por CPF e
     * deixaria a mesma pessoa gravada de duas formas.
     *
     * @var string[]
     */
    private const CAMPOS_SO_DIGITOS = ['ds_cpf', 'ds_cnpj'];

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    protected function normalizarCamposDePessoa(array $dados): array
    {
        foreach (self::CAMPOS_SO_DIGITOS as $campo) {
            if (isset($dados[$campo]) && is_string($dados[$campo])) {
                $dados[$campo] = Documento::apenasDigitos($dados[$campo]);
            }
        }

        // O legado gravou 'f' e 'm' minúsculos (291k e 218k linhas). Aceitar 'F' e baixar
        // é mais gentil que recusar, e mantém a coluna homogênea.
        if (isset($dados['ds_sexo']) && is_string($dados['ds_sexo'])) {
            $dados['ds_sexo'] = strtolower(trim($dados['ds_sexo']));
        }

        // String vazia é ausência de dado, não dado vazio: a coluna é nullable e o legado
        // tem 27k linhas com '' em ds_sexo justamente por não fazer isto.
        foreach ($dados as $campo => $valor) {
            if ($valor === '') {
                $dados[$campo] = null;
            }
        }

        return $dados;
    }
}
```

- [ ] **Step 4: Crie o trait de dígito verificador**

`app/Request/Pessoa/Concerns/ValidaDocumentosDePessoa.php`:

```php
<?php

declare(strict_types=1);

namespace App\Request\Pessoa\Concerns;

use App\Support\Documento;
use App\Support\Tipo;
use Hyperf\Contract\ValidatorInterface;

/**
 * Dígito verificador de CPF/CNPJ no after() do validador, mesmo padrão de
 * ValidaCamposDePessoa.
 *
 * Roda depois de digits:11/digits:14, então já recebe valor com o tamanho certo e sem
 * máscara (NormalizaCamposDePessoa limpou em validationData()). Só reporta quando o campo
 * veio: campo ausente é assunto de `nullable`/`required_if`, não daqui.
 */
trait ValidaDocumentosDePessoa
{
    protected function validarDocumentos(ValidatorInterface $validator): void
    {
        $validator->after(function (ValidatorInterface $validator): void {
            $cpf = $this->input('ds_cpf');

            if (is_string($cpf) && $cpf !== '' && ! Documento::cpfEhValido(Tipo::texto($cpf))) {
                $validator->errors()->add('ds_cpf', trans('validation.cpf_invalido'));
            }

            $cnpj = $this->input('ds_cnpj');

            if (is_string($cnpj) && $cnpj !== '' && ! Documento::cnpjEhValido(Tipo::texto($cnpj))) {
                $validator->errors()->add('ds_cnpj', trans('validation.cnpj_invalido'));
            }
        });
    }
}
```

**`trans()` é namespaced, não global.** `hyperf/translation` declara `src/Functions.php` em `autoload.files`, então o arquivo é carregado — mas a função dentro dele vive em `Hyperf\Translation`. Importe:

```php
use function Hyperf\Translation\trans;
```

(Descoberto na execução da Task 7. A verificação original olhou o `autoload.files` e concluiu "global" sem conferir o namespace do arquivo.)

**`$this->input()` dentro do `after()` lê o request CRU, não o `validationData()` normalizado.** A normalização alimenta o validador, não o `input()` do FormRequest — então o trait de DV receberia o valor ainda com máscara e reprovaria um CPF válido enviado como `123.456.789-09`. Reaplique `Documento::apenasDigitos()` dentro do trait antes de checar o dígito; é idempotente. (Também descoberto na execução da Task 7.)

- [ ] **Step 5: Acrescente as mensagens ao arquivo versionado**

Em `storage/languages/en/validation.php`, acrescente as duas chaves em ordem alfabética entre as existentes (perto de `confirmed`/`date`):

```php
    'cnpj_invalido' => 'The :attribute is not a valid CNPJ.',
    'cpf_invalido' => 'The :attribute is not a valid CPF.',
```

Este arquivo é código de produção e é versionado. Sem a chave, o 422 responderia `validation.cpf_invalido` cru em vez de uma frase.

- [ ] **Step 6: Ligue tudo no `CreatePessoaRequest`**

Substitua `app/Request/Pessoa/CreatePessoaRequest.php` inteiro (sem header — `cs-fix` insere):

```php
<?php

declare(strict_types=1);

namespace App\Request\Pessoa;

use App\Request\Pessoa\Concerns\NormalizaCamposDePessoa;
use App\Request\Pessoa\Concerns\ValidaDocumentosDePessoa;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\Validation\Request\FormRequest;

class CreatePessoaRequest extends FormRequest
{
    use NormalizaCamposDePessoa;
    use ValidaDocumentosDePessoa;

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
            'ds_nome' => 'required|string|max:255',
            'ds_login' => 'required|string|max:100',
            'ds_senha' => 'required|string|min:6',
            'sn_pessoa_juridica' => 'required|boolean',
            'ds_nome_oficial' => 'required_if:sn_pessoa_juridica,false|string|max:255',
            'ds_cpf' => 'nullable|digits:11',
            'ds_cnpj' => 'required_if:sn_pessoa_juridica,true|digits:14',
            'ds_nome_fantasia' => 'required_if:sn_pessoa_juridica,true|string|max:255',
            'ds_nome_social' => 'nullable|string|max:255',
            'ds_nome_mae' => 'nullable|string|max:255',
            'ds_nome_pai' => 'nullable|string|max:255',
            'ds_identidade' => 'nullable|string|max:255',
            'ds_orgao_estado' => 'nullable|string|max:255',
            'ds_identidade_orgao_exp' => 'nullable|string|max:255',
            'dt_identidade_expedicao' => 'nullable|date_format:Y-m-d|before_or_equal:today|after_or_equal:dt_nascimento',
            'dt_nascimento' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'ds_sexo' => 'nullable|in:f,m',
            'cd_estado_civil' => 'nullable|integer|exists:saas_estado_civil,cd_estado_civil',
        ];
    }

    public function withValidator(ValidatorInterface $validator): void
    {
        $this->validarDocumentos($validator);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validationData(): array
    {
        return $this->normalizarCamposDePessoa(parent::validationData());
    }
}
```

- [ ] **Step 7: Aplique o mesmo em `UpdatePessoaRequest` e `PatchPessoaRequest`**

Abra `app/Request/Pessoa/UpdatePessoaRequest.php` e `app/Request/Pessoa/PatchPessoaRequest.php`. Em cada um:

1. acrescente os dois `use` de trait dentro da classe (`use NormalizaCamposDePessoa;` e `use ValidaDocumentosDePessoa;`) e os `use` de namespace no topo;
2. acrescente ao array de `rules()` as **mesmas dez linhas** de campo de física do passo 6 (de `ds_nome_social` a `cd_estado_civil`) — no `PatchPessoaRequest` todas as regras já são `sometimes`/`nullable`, então mantenha esse estilo e escreva `sometimes|nullable|...` nas dez;

   **Atenção, e aqui o texto anterior deste passo estava errado:** ele dizia "preservando as regras que já existem para os campos antigos", o que faz `ds_cpf`/`ds_cnpj` ficarem `string` no `UpdatePessoaRequest` e no `PatchPessoaRequest` enquanto o `CreatePessoaRequest` ganha `digits:11`/`digits:14`. O spec lista essas regras **uma vez, para escrita em geral**, sem distinguir endpoint. Aplique `digits:11` e `digits:14` nos três, mantendo `required_if` onde já está. Divergência entre as três classes é precisamente o Finding 14 deste projeto;
3. acrescente `validationData()` idêntico ao do passo 6;
4. se a classe já tem `withValidator()`, acrescente a chamada `$this->validarDocumentos($validator);` ao corpo existente; se não tem, crie o método como no passo 6.

Não invente regra diferente entre os três: divergência entre `rules()` de Create/Update/Patch é exatamente como o `ds_cnpj` entrou em pessoa física no Finding 14.

- [ ] **Step 8: Run tests to verify they pass**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php \
  test/Cases/Controller/Pessoa/PessoaControllerTest.php \
  --filter 'testCpfComMascara|testSexoForaDoDominio|testCpfComDigito|testEstadoCivilInexistente|testExpedicaoAnterior|testNascimentoNoFuturo'
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php \
  test/Cases/Request/Pessoa/CreatePessoaRequestTest.php
```

Esperado: PASS nos seis, e no teste de request que já existia.

- [ ] **Step 9: Feche a tarefa**

```bash
docker exec lumina composer cs-fix
docker exec lumina composer test
git add app/Request/Pessoa storage/languages/en/validation.php \
        test/Cases/Controller/Pessoa/PessoaControllerTest.php \
        test/Cases/Request/Pessoa/CreatePessoaRequestTest.php
git commit -m "feat: normalizacao e validacao de formato nos requests de pessoa

O banco nao restringe nada nesses campos e o legado gravou lixo: ds_sexo tem 'n',
'a', 'o', 'b' e 27k strings vazias. A API para de acrescentar.

Normalizacao em validationData(), antes das regras: CPF/CNPJ perdem mascara (senao
reprovariam em digits:11), ds_sexo baixa para minusculo, string vazia vira null.

exists:saas_estado_civil nao e decoracao -- sem ele um codigo inexistente viola FK,
sai como 23000 e o handler devolve 409, o mesmo status de login duplicado.

Chaves cpf_invalido/cnpj_invalido em storage/languages/en/validation.php, que e
codigo de producao e versionado: sem elas o 422 responderia a chave crua.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Os dez campos chegam ao banco

Fecha a escrita. Hoje `PessoaService` monta os dados de física em dois lugares com listas literais diferentes — `separarDados()` usa `ds_nome_oficial`/`ds_cpf`, `atualizarParcial()` repete `['ds_nome_oficial', 'ds_cpf']` por conta própria. Divergência entre essas duas listas é a origem do Finding 14. Com dez campos a mais, uma constante única deixa de ser elegância e passa a ser necessidade.

**Files:**
- Modify: `app/Service/Pessoa/PessoaService.php`
- Test: `test/Cases/Service/Pessoa/PessoaServiceTest.php`
- Test: `test/Cases/Controller/Pessoa/PessoaControllerTest.php`

**Interfaces:**
- Consumes: as dez chaves do mapa (Task 6) e o payload normalizado (Task 7).
- Produces: `PessoaService::CAMPOS_FISICA` (`string[]`, privada) — fonte única usada por `separarDados()` e `atualizarParcial()`.

- [ ] **Step 1: Write the failing test**

Acrescente a `test/Cases/Controller/Pessoa/PessoaControllerTest.php`, antes de `headers()`:

```php
    public function testCriaPessoaFisicaComOsDezCamposNovos()
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Completa',
            'ds_login' => 'teste.http.completa',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Completa Oficial',
            'ds_nome_social' => 'Completa',
            'ds_nome_mae' => 'Mae Completa',
            'ds_nome_pai' => 'Pai Completa',
            'ds_cpf' => '52998224725',
            'ds_identidade' => '123456789',
            'ds_orgao_estado' => 'SP',
            'ds_identidade_orgao_exp' => 'SSP',
            'dt_identidade_expedicao' => '2015-03-01',
            'dt_nascimento' => '1990-05-12',
            'ds_sexo' => 'f',
            'cd_estado_civil' => $this->cdEstadoCivil(),
        ], $this->headers());

        $criar->assertStatus(201);
        $cdPessoa = $criar->json('data.cd_pessoa');

        $detalhe = $this->get("/pessoas/{$cdPessoa}", ['fields' => 'fisica.*'], $this->headers());
        $detalhe->assertStatus(200);
        $fisica = $detalhe->json('data.fisica');

        $this->assertSame('Completa', $fisica['ds_nome_social']);
        $this->assertSame('Mae Completa', $fisica['ds_nome_mae']);
        $this->assertSame('Pai Completa', $fisica['ds_nome_pai']);
        $this->assertSame('52998224725', $fisica['ds_cpf']);
        $this->assertSame('123456789', $fisica['ds_identidade']);
        $this->assertSame('SP', $fisica['ds_orgao_estado']);
        $this->assertSame('SSP', $fisica['ds_identidade_orgao_exp']);
        $this->assertSame('2015-03-01', $fisica['dt_identidade_expedicao']);
        $this->assertSame('1990-05-12', $fisica['dt_nascimento']);
        $this->assertSame('f', $fisica['ds_sexo']);
        $this->assertSame($this->cdEstadoCivil(), $fisica['cd_estado_civil']);
    }

    public function testNormalizaSexoEStringVaziaAoGravar()
    {
        // Fecha o par que a Task 7 deixou pela metade: a normalização roda em
        // validationData(), mas só aqui os campos chegam ao banco e podem ser observados.
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Normaliza',
            'ds_login' => 'teste.http.normaliza',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Normaliza Oficial',
            'ds_sexo' => 'F',
            'ds_nome_social' => '',
        ], $this->headers());

        $criar->assertStatus(201);
        $this->assertSame('f', $criar->json('data.fisica.ds_sexo'));
        $this->assertNull($criar->json('data.fisica.ds_nome_social'));
    }

    public function testPatchAtualizaCampoNovoDeFisica()
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Patch Fisica',
            'ds_login' => 'teste.http.patchfisica',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Patch Fisica Oficial',
        ], $this->headers());

        $criar->assertStatus(201);
        $cdPessoa = $criar->json('data.cd_pessoa');

        $patch = $this->patch("/pessoas/{$cdPessoa}", ['ds_nome_social' => 'Patchado'], $this->headers());
        $patch->assertStatus(200);
        $this->assertSame('Patchado', $patch->json('data.fisica.ds_nome_social'));
    }

    public function testPatchComCampoDeFisicaEmPessoaJuridicaNaoCriaLinhaFisica()
    {
        // Finding 14: PATCH nunca troca o tipo pessoa, e campo do tipo que a pessoa NÃO é
        // tem de ser ignorado em silêncio. Com dez campos a mais, dez portas a mais.
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Juridica Patch',
            'ds_login' => 'teste.http.juridicapatch',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => true,
            'ds_cnpj' => '00000000000191',
            'ds_nome_fantasia' => 'Http Teste Fantasia',
        ], $this->headers());

        $criar->assertStatus(201);
        $cdPessoa = $criar->json('data.cd_pessoa');

        $patch = $this->patch("/pessoas/{$cdPessoa}", [
            'ds_nome_mae' => 'Nao Deve Gravar',
            'ds_sexo' => 'f',
        ], $this->headers());

        $patch->assertStatus(200);
        $this->assertSame(
            0,
            Db::table('unim_pessoa_fisica')->where('cd_pessoa', $cdPessoa)->count()
        );
    }

    private function cdEstadoCivil(): int
    {
        return (int) Db::table('saas_estado_civil')->min('cd_estado_civil');
    }
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php \
  test/Cases/Controller/Pessoa/PessoaControllerTest.php --filter testCriaPessoaFisicaComOsDezCamposNovos
```

Esperado: FAIL. `ds_nome_social` volta `null` — o campo é validado (Task 7) e legível (Task 6), mas `separarDados()` ainda copia só `ds_nome_oficial` e `ds_cpf`.

- [ ] **Step 3: Introduza a constante única**

Em `app/Service/Pessoa/PessoaService.php`, acrescente logo abaixo de `LOGINS_ISENTOS_DE_FISICA_JURIDICA`:

```php
    /**
     * Colunas de unim_pessoa_fisica que a API escreve. FONTE ÚNICA: separarDados() (POST/PUT)
     * e atualizarParcial() (PATCH) leem daqui.
     *
     * Antes eram duas listas literais separadas, e é assim que ds_cnpj acabou gravado em
     * pessoa física (Finding 14). Com treze colunas em jogo, manter duas listas em sincronia
     * na mão não é uma aposta razoável.
     *
     * ds_nome_oficial fica FORA: é obrigatório para pessoa física e tratado à parte em
     * separarDados(), com regra própria.
     *
     * @var string[]
     */
    private const CAMPOS_FISICA = [
        'ds_nome_social',
        'ds_nome_mae',
        'ds_nome_pai',
        'ds_cpf',
        'ds_identidade',
        'ds_orgao_estado',
        'ds_identidade_orgao_exp',
        'dt_identidade_expedicao',
        'dt_nascimento',
        'ds_sexo',
        'cd_estado_civil',
    ];

    /**
     * Colunas de unim_pessoa_juridica que a API escreve. Mesma razão de CAMPOS_FISICA.
     *
     * @var string[]
     */
    private const CAMPOS_JURIDICA = ['ds_cnpj', 'ds_nome_fantasia'];
```

- [ ] **Step 4: Use a constante em `separarDados()`**

No fim de `separarDados()`, substitua o bloco de jurídica e o de física:

```php
        if ($dados['sn_pessoa_juridica']) {
            return [$dadosPessoa, null, self::somenteCamposConhecidos($dados, self::CAMPOS_JURIDICA)];
        }

        // ds_nome_oficial é obrigatório para pessoa física (required_if no FormRequest), por
        // isso entra direto e não pela lista de opcionais.
        $dadosFisica = ['ds_nome_oficial' => $dados['ds_nome_oficial']];

        return [
            $dadosPessoa,
            [...$dadosFisica, ...self::somenteCamposConhecidos($dados, self::CAMPOS_FISICA)],
            null,
        ];
```

E acrescente o helper privado ao fim da classe:

```php
    /**
     * Recorta do payload apenas as chaves presentes e conhecidas. `array_intersect_key` faria
     * o mesmo, mas com a lista invertida (flip) em cada chamada — aqui a intenção fica
     * explícita e a lista de campos continua legível.
     *
     * @param array<string, mixed> $dados
     * @param string[] $campos
     *
     * @return array<string, mixed>
     */
    private static function somenteCamposConhecidos(array $dados, array $campos): array
    {
        $recorte = [];

        foreach ($campos as $campo) {
            if (array_key_exists($campo, $dados)) {
                $recorte[$campo] = $dados[$campo];
            }
        }

        return $recorte;
    }
```

- [ ] **Step 5: Use a constante em `atualizarParcial()`**

Substitua os dois `array_intersect_key` de física/jurídica:

```php
        // Campos do tipo que a pessoa NÃO é são ignorados silenciosamente, mesmo que venham
        // no payload. Com treze colunas de física em jogo, a lista vem da constante — duas
        // listas literais foi como ds_cnpj entrou em pessoa física (Finding 14).
        $dadosFisica = $pessoaAtual->sn_pessoa_juridica
            ? []
            : self::somenteCamposConhecidos($dados, [...self::CAMPOS_FISICA, 'ds_nome_oficial']);

        $dadosJuridica = $pessoaAtual->sn_pessoa_juridica
            ? self::somenteCamposConhecidos($dados, self::CAMPOS_JURIDICA)
            : [];
```

- [ ] **Step 6: Confira que `UnimPessoaFisica` aceita as colunas**

Abra `app/Model/Pessoa/UnimPessoaFisica.php` e confirme que `$fillable` já lista as treze colunas (`cd_pessoa`, `ds_nome_oficial`, `ds_nome_social`, `ds_nome_mae`, `ds_nome_pai`, `ds_identidade`, `ds_orgao_estado`, `ds_identidade_orgao_exp`, `dt_identidade_expedicao`, `dt_nascimento`, `ds_cpf`, `ds_sexo`, `cd_estado_civil`). Ele já lista — nada a mudar. Se alguma faltasse, o `create()` a descartaria em silêncio, sem erro.

- [ ] **Step 7: Run tests to verify they pass**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Controller/Pessoa/PessoaControllerTest.php
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Service/Pessoa/PessoaServiceTest.php
```

Esperado: PASS em tudo, incluindo `testNormalizaSexoEStringVaziaAoGravar` e os testes de troca de tipo que já existiam.

Se `testPatchComCampoDeFisicaEmPessoaJuridicaNaoCriaLinhaFisica` falhar com contagem 1, a lista de física está sendo aplicada sem consultar `sn_pessoa_juridica` da pessoa gravada — é a regressão do Finding 14 voltando.

- [ ] **Step 8: Feche a tarefa**

```bash
docker exec lumina composer cs-fix
docker exec lumina composer test
git add app/Service/Pessoa/PessoaService.php \
        test/Cases/Service/Pessoa/PessoaServiceTest.php \
        test/Cases/Controller/Pessoa/PessoaControllerTest.php
git commit -m "feat: os dez campos de pessoa fisica chegam ao banco

separarDados() e atualizarParcial() mantinham duas listas literais separadas dos
campos de fisica, e divergencia entre elas foi como ds_cnpj entrou em pessoa
fisica (Finding 14). Com treze colunas em jogo, uma constante unica deixa de ser
elegancia.

PATCH continua ignorando em silencio campo do tipo que a pessoa nao e, agora com
dez portas a mais para isso dar errado -- e com teste em cima.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Documentação de pessoa

O contrato mudou em três frentes — dez campos novos, PII fora do default, normalização silenciosa — e nada disso está publicado. Regra 1: o que serve a documentação é `storage/swagger/http.json`, não o atributo PHP.

**Files:**
- Modify: `app/Swagger/PessoaSchema.php`
- Modify: `app/Controller/Pessoa/PessoaController.php` (descrições de `fields` nas linhas ~156-162 e ~193-200, e as respostas dos três endpoints de escrita)
- Modify: `storage/swagger/http.json` (gerado)

**Interfaces:**
- Consumes: os nomes de campo da Task 6, as regras da Task 7.
- Produces: schema `Pessoa` completo em `#/components/schemas/`.

- [ ] **Step 1: Acrescente as dez propriedades ao `PessoaSchema`**

Em `app/Swagger/PessoaSchema.php`, substitua a propriedade `fisica` inteira por:

```php
        new OA\Property(
            property: 'fisica',
            description: 'Dados de pessoa física. null quando a pessoa é jurídica. '
                . 'ATENÇÃO: ds_cpf, ds_identidade, ds_nome_mae, ds_nome_pai e dt_nascimento são dado pessoal e '
                . 'NÃO vêm no default de GET /pessoas/{id} — só aparecem se pedidos por nome (fields=fisica.ds_cpf) '
                . 'ou por curinga (fields=fisica.* ou fields=*). Resposta de POST/PUT/PATCH traz todos.',
            properties: [
                new OA\Property(property: 'ds_nome_oficial', description: 'Nome em documento. Obrigatório ao criar pessoa física.', type: 'string', example: 'Ana Souza'),
                new OA\Property(property: 'ds_nome_social', type: 'string', example: 'Ana', nullable: true),
                new OA\Property(property: 'ds_nome_mae', description: 'Dado pessoal: fora do default, só com fields explícito.', type: 'string', example: 'Maria Souza', nullable: true),
                new OA\Property(property: 'ds_nome_pai', description: 'Dado pessoal: fora do default, só com fields explícito.', type: 'string', example: 'Jose Souza', nullable: true),
                new OA\Property(property: 'ds_cpf', description: 'Dado pessoal: fora do default, só com fields explícito. Gravado e devolvido SEM máscara, mesmo que enviado com.', type: 'string', example: '52998224725', nullable: true),
                new OA\Property(property: 'ds_identidade', description: 'Dado pessoal: fora do default, só com fields explícito.', type: 'string', example: '123456789', nullable: true),
                new OA\Property(property: 'ds_orgao_estado', description: 'UF do órgão expedidor da identidade.', type: 'string', example: 'SP', nullable: true),
                new OA\Property(property: 'ds_identidade_orgao_exp', description: 'Órgão expedidor da identidade.', type: 'string', example: 'SSP', nullable: true),
                new OA\Property(property: 'dt_identidade_expedicao', description: 'Data no formato Y-m-d. Não pode ser futura nem anterior a dt_nascimento quando as duas vêm no mesmo payload.', type: 'string', format: 'date', example: '2015-03-01', nullable: true),
                new OA\Property(property: 'dt_nascimento', description: 'Data no formato Y-m-d, não pode ser futura. Dado pessoal: fora do default, só com fields explícito.', type: 'string', format: 'date', example: '1990-05-12', nullable: true),
                new OA\Property(property: 'ds_sexo', description: 'Na escrita aceita apenas f, m ou null (F e M são aceitos e gravados em minúsculo). A LEITURA pode devolver outros valores: o banco legado tem dado fora desse domínio e a API não mente sobre o que está gravado.', type: 'string', enum: ['f', 'm'], example: 'f', nullable: true),
                new OA\Property(property: 'cd_estado_civil', description: 'Código de saas_estado_civil. Traduza o rótulo em GET /estados-civis — a leitura de pessoa devolve o código, não o nome.', type: 'integer', example: 37, nullable: true),
            ],
            type: 'object',
            nullable: true
        ),
```

E na propriedade `juridica`, acrescente à descrição do `ds_cnpj`:

```php
                new OA\Property(property: 'ds_cnpj', description: 'Gravado e devolvido SEM máscara, mesmo que enviado com.', type: 'string', example: '00000000000191'),
```

- [ ] **Step 2: Reescreva a descrição de `fields` nos dois endpoints de leitura**

Em `app/Controller/Pessoa/PessoaController.php`, a lista de campos disponíveis no `#[OA\Parameter(name: 'fields')]` de `listar()` está desatualizada (menciona nove campos) e a de `buscar()` não diz nada sobre PII. Atributo PHP exige expressão constante, então nada disso pode ser derivado do mapa — é cópia manual, e é por isso que a regra manda conferir o artefato.

Em `buscar()`:

```php
    #[OA\Parameter(
        name: 'fields',
        in: 'query',
        description: 'Campos a devolver, separados por vírgula (mesma sintaxe de GET /pessoas). '
            . 'Sem este parâmetro o detalhe devolve o registro completo MENOS o dado pessoal — diferente da listagem, que devolve um conjunto enxuto. '
            . 'Dado pessoal (fisica.ds_cpf, fisica.ds_identidade, fisica.ds_nome_mae, fisica.ds_nome_pai, fisica.dt_nascimento) só vem se pedido por nome ou por curinga (fisica.* ou *). '
            . 'Campos disponíveis: cd_pessoa, cd_cliente, ds_nome, ds_login, sn_pessoa_juridica, '
            . 'fisica.ds_nome_oficial, fisica.ds_nome_social, fisica.ds_nome_mae, fisica.ds_nome_pai, fisica.ds_cpf, '
            . 'fisica.ds_identidade, fisica.ds_orgao_estado, fisica.ds_identidade_orgao_exp, fisica.dt_identidade_expedicao, '
            . 'fisica.dt_nascimento, fisica.ds_sexo, fisica.cd_estado_civil, juridica.ds_cnpj, juridica.ds_nome_fantasia.',
        schema: new OA\Schema(type: 'string', example: 'ds_nome,fisica.ds_cpf')
    )]
```

E ajuste a descrição da resposta 200 do mesmo endpoint:

```php
        description: 'Pessoa encontrada. Por padrão vem o registro completo SEM o dado pessoal; com ?fields= vem só o que foi pedido, dado pessoal incluso se pedido explicitamente.',
```

Em `listar()`, substitua a lista de campos disponíveis pela mesma enumeração de dezenove campos acima, mantendo o resto da descrição (o aviso de que a lista devolve só quatro campos por padrão continua correto e importante).

- [ ] **Step 3: Diga a normalização nas respostas de escrita**

Nos `#[OA\Response(response: 201, ...)]` de `criar()` e nos `200` de `atualizar()` e `atualizarParcial()`, acrescente à `description`:

```
 A resposta ignora ?fields= e traz o registro completo, dado pessoal incluso — é o que o servidor gravou. CPF e CNPJ voltam sem máscara e ds_sexo em minúsculo, mesmo que enviados de outra forma.
```

Em `atualizarParcial()`, acrescente também:

```
 Campo do tipo que a pessoa NÃO é (física em jurídica, ou o contrário) é ignorado em silêncio, e PATCH nunca troca o tipo. A regra cruzada de dt_identidade_expedicao contra dt_nascimento só é avaliada quando as duas datas vêm no mesmo payload.
```

- [ ] **Step 4: Regenere e confira o artefato**

```bash
docker exec lumina php /opt/www/bin/hyperf.php gen:swagger
python3 -c "import json; d=json.load(open('storage/swagger/http.json')); print(sorted(d['components']['schemas']['Pessoa']['properties']['fisica']['properties'].keys()))"
python3 -c "import json; d=json.load(open('storage/swagger/http.json')); print([p['description'][:120] for p in d['paths']['/pessoas/{id}']['get']['parameters'] if p['name']=='fields'])"
```

Esperado: as doze propriedades de `fisica` na primeira saída, e a descrição nova de `fields` na segunda. Se `http.json` não mudou de data, o `gen:swagger` não rodou — e editar o atributo não publica nada.

- [ ] **Step 5: Feche a tarefa**

```bash
docker exec lumina composer cs-fix
docker exec lumina composer test
git add app/Swagger/PessoaSchema.php app/Controller/Pessoa/PessoaController.php storage/swagger/http.json
git commit -m "docs: contrato de pessoa fisica completo no swagger

Tres mudancas de contrato estavam no codigo e nao na documentacao: dez campos
novos, PII fora do default do detalhe, e normalizacao silenciosa de mascara e de
ds_sexo.

Ditas em letras claras porque quem le nao vai adivinhar: qual campo e dado pessoal
e como pedi-lo, que a resposta devolve valor diferente do enviado, que ds_sexo na
LEITURA pode vir fora do dominio (legado), e que cd_estado_civil se traduz em
GET /estados-civis.

Artefato regenerado no mesmo commit -- editar o atributo nao publica nada.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: `SoftDeletes` em `UnimPessoaFisica` — BLOQUEADA POR DECISÃO DE BANCO

**Não comece esta tarefa antes de confirmar que o `ALTER` foi aplicado.** Ela depende de uma mudança de schema que a regra 2 do `CLAUDE.md` proíbe este projeto de aplicar. Confira primeiro:

```bash
docker exec mysql_84 mysql -uroot -punimestre lms2 -N -e \
  "select count(*) from information_schema.columns where table_schema='lms2' and table_name='unim_pessoa_fisica' and column_name='dt_excluido';"
```

Se devolver `0`, **pare aqui**. Reporte que falta a coluna, entregue o SQL abaixo, e deixe a decisão com quem tem a caneta do banco. Não aplique, não crie migration, não use `Db::statement()` para contornar.

```sql
ALTER TABLE unim_pessoa_fisica ADD COLUMN dt_excluido datetime DEFAULT NULL;
```

Se devolver `1`, siga.

O passo 3 desta tarefa não é polimento e não pode ser deixado para depois. `unim_pessoa_fisica` tem PK `cd_pessoa`; assim que `SoftDeletes` entra, o scope global esconde a linha excluída, o `updateOrCreate()` tenta INSERT, bate na PK, sai como SQLSTATE 23000 e o `DatabaseExceptionHandler` devolve **409**. Trocar uma pessoa de jurídica para física depois de uma exclusão passaria a falhar. É o mesmo motivo pelo qual `PessoaRepository::loginExiste()` já usa `withTrashed()`, documentado lá.

**Files:**
- Modify: `app/Model/Pessoa/UnimPessoaFisica.php`
- Modify: `app/Repository/Pessoa/PessoaRepository.php:118-126`
- Test: `test/Cases/Controller/Pessoa/PessoaControllerTest.php`

**Interfaces:**
- Consumes: `PessoaRepository::atualizar()` como está hoje.
- Produces: `UnimPessoaFisica::DELETED_AT = 'dt_excluido'`; comportamento de reativação em `atualizar()`.

- [ ] **Step 1: Write the failing test**

Acrescente a `test/Cases/Controller/Pessoa/PessoaControllerTest.php`, antes de `headers()`:

```php
    public function testVoltarDeJuridicaParaFisicaDepoisDeExclusaoNaoDa409()
    {
        // Com SoftDeletes em unim_pessoa_fisica, a linha excluída fica escondida do scope
        // global. updateOrCreate() não a vê, tenta INSERT, bate na PK cd_pessoa e sai como
        // 23000 -> 409, o mesmo status de "login ja existe".
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Revive',
            'ds_login' => 'teste.http.revive',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Revive Oficial',
        ], $this->headers());

        $criar->assertStatus(201);
        $cdPessoa = $criar->json('data.cd_pessoa');

        // física -> jurídica: apaga (soft) a linha física
        $paraJuridica = $this->put("/pessoas/{$cdPessoa}", [
            'ds_nome' => 'Http Teste Revive',
            'ds_login' => 'teste.http.revive',
            'sn_pessoa_juridica' => true,
            'ds_cnpj' => '00000000000191',
            'ds_nome_fantasia' => 'Http Teste Revive Fantasia',
        ], $this->headers());

        $paraJuridica->assertStatus(200);
        $this->assertNull($paraJuridica->json('data.fisica'));

        // jurídica -> física: a linha antiga tem de reviver, não colidir
        $voltaParaFisica = $this->put("/pessoas/{$cdPessoa}", [
            'ds_nome' => 'Http Teste Revive',
            'ds_login' => 'teste.http.revive',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Revive Oficial 2',
        ], $this->headers());

        $voltaParaFisica->assertStatus(200);
        $this->assertSame('Http Teste Revive Oficial 2', $voltaParaFisica->json('data.fisica.ds_nome_oficial'));
        $this->assertNull($voltaParaFisica->json('data.juridica'));
    }
```

Acrescente também `Db::table('unim_pessoa_fisica')` ao `tearDown()` — ele já apaga a tabela, e com soft delete a linha continua lá, então o `delete()` direto por `Db::table()` (que ignora scope de model) continua sendo o certo. Nada a mudar.

- [ ] **Step 2: Run test to verify it fails**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php \
  test/Cases/Controller/Pessoa/PessoaControllerTest.php --filter testVoltarDeJuridicaParaFisicaDepoisDeExclusao
```

Esperado: PASS neste momento (sem `SoftDeletes` ainda, o `delete()` é físico e o `updateOrCreate()` insere sem colidir). O teste existe para **provar que o passo 3 não quebra nada** — depois do passo seguinte ele passa a ser a rede de segurança de verdade. Rode-o de novo após o passo 3 e confirme que continua verde: é ali que ele tem valor.

- [ ] **Step 3: Ligue `SoftDeletes` no model**

Em `app/Model/Pessoa/UnimPessoaFisica.php`, acrescente o import e o trait:

```php
use Hyperf\Database\Model\SoftDeletes;
```

```php
class UnimPessoaFisica extends Model
{
    use SoftDeletes;

    public const DELETED_AT = 'dt_excluido';
```

Acrescente `dt_excluido` ao `@property` da classe e ao `$casts`:

```php
 * @property null|Carbon $dt_excluido
```

```php
        'dt_excluido' => 'datetime',
```

`dt_excluido` **não** entra em `$fillable`: exclusão é do `SoftDeletes`, não de payload.

- [ ] **Step 4: Troque `updateOrCreate()` por reativação explícita**

Em `app/Repository/Pessoa/PessoaRepository.php`, dentro da transação de `atualizar()`, substitua:

```php
            if ($dadosFisica !== null) {
                UnimPessoaFisica::updateOrCreate(['cd_pessoa' => $cdPessoa], $dadosFisica);
            }
```

por:

```php
            if ($dadosFisica !== null) {
                self::gravarFisica($cdPessoa, $dadosFisica);
            }
```

E acrescente o método privado à classe:

```php
    /**
     * updateOrCreate() não serve aqui depois que unim_pessoa_fisica passou a ter
     * dt_excluido: o scope do SoftDeletes esconde a linha excluída, o updateOrCreate()
     * tenta INSERT, bate na PK cd_pessoa e o erro chega como SQLSTATE 23000 — que o
     * DatabaseExceptionHandler traduz para 409, o mesmo status de "login já existe".
     *
     * Uma pessoa que foi de física para jurídica e volta cai exatamente nesse caminho.
     * withTrashed() acha a linha escondida e restore() a revive, mesmo motivo pelo qual
     * loginExiste() já usa withTrashed() sobre unim_pessoa.
     *
     * @param array<string, mixed> $dadosFisica
     */
    private static function gravarFisica(int $cdPessoa, array $dadosFisica): void
    {
        $fisica = UnimPessoaFisica::withTrashed()->where('cd_pessoa', $cdPessoa)->first();

        if (! $fisica instanceof UnimPessoaFisica) {
            UnimPessoaFisica::create(['cd_pessoa' => $cdPessoa, ...$dadosFisica]);

            return;
        }

        if ($fisica->trashed()) {
            $fisica->restore();
        }

        $fisica->update($dadosFisica);
    }
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Controller/Pessoa/PessoaControllerTest.php
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Repository/Pessoa/PessoaRepositoryTest.php
docker exec lumina vendor/bin/co-phpunit --prepend test/bootstrap.php test/Cases/Service/Pessoa/PessoaServiceTest.php
```

Esperado: PASS em tudo. Um 409 inesperado em qualquer teste de troca de tipo é o sintoma exato descrito no passo 4 — confira que `gravarFisica()` está sendo chamada e que `withTrashed()` está lá.

- [ ] **Step 6: Feche a tarefa**

```bash
docker exec lumina composer cs-fix
docker exec lumina composer test
git add app/Model/Pessoa/UnimPessoaFisica.php app/Repository/Pessoa/PessoaRepository.php \
        test/Cases/Controller/Pessoa/PessoaControllerTest.php
git commit -m "feat: SoftDeletes em unim_pessoa_fisica e reativacao no lugar de updateOrCreate

Depende do ALTER TABLE unim_pessoa_fisica ADD COLUMN dt_excluido, aplicado por quem
tem a caneta do banco -- este projeto nao cria migration (regra 2).

updateOrCreate() deixa de servir com soft delete: o scope esconde a linha excluida,
o INSERT bate na PK cd_pessoa e o erro chega como 23000, que o handler traduz para
409 -- o mesmo status de login duplicado. Pessoa que foi de juridica para fisica e
volta cai exatamente ai. withTrashed() + restore() revive a linha, mesmo padrao que
loginExiste() ja usava.

Nota: o LMS legado nao conhece esta coluna, entao linha que o Lumina considera
excluida continua visivel la ate alguem mexer nas entidades Doctrine.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Ordem e dependências

```
Task 1  nucleo: campo sensivel          (sem dependencia)
Task 2  DV de CPF/CNPJ                  (sem dependencia)
Task 3  models + repositorio de dominio (sem dependencia)
Task 4  /paises /estados-civis /contato-tipos   <- Task 3
Task 5  /estados /cidades                       <- Task 3, Task 4
Task 6  dez campos na leitura + PII             <- Task 1
Task 7  normalizacao e validacao                <- Task 2 (e Task 4 para /estados-civis existir na doc)
Task 8  os dez campos chegam ao banco           <- Task 6, Task 7
Task 9  documentacao de pessoa                  <- Task 6, Task 7, Task 8
Task 10 SoftDeletes em fisica          BLOQUEADA por ALTER TABLE
```

Tasks 1, 2 e 3 são independentes e podem sair em paralelo. As Tasks 4 e 5 entregam valor sozinhas: depois delas o cliente já descobre os catálogos, mesmo que nada de pessoa tenha mudado.

## O que este plano NÃO faz

Fora de escopo por decisão registrada no spec, não por esquecimento:

- endereço (`/pessoas/{id}/endereco`) — entrega 2
- contatos (`/pessoas/{id}/contatos`) — entrega 3
- sub-recurso `/pessoas/{id}/fisica` e `/juridica` — decisão adiada
- expansão de rótulo na leitura de pessoa (`fisica.estado_civil.ds_estado_civil`) — exige relação aninhada de profundidade 2 em `SelecaoDeCampos`, que é spec próprio
- cache dos catálogos de domínio
- `?fields=` nas rotas de domínio
- `dt_excluido` nos cinco catálogos
- qualquer escrita em tabela de domínio
- unicidade de CPF por cliente — o legado tem duplicados e o banco não tem índice
