<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

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
    public function testSelecaoNulaDevolveOContratoCompleto()
    {
        $pessoa = $this->pessoa();
        $fisica = new UnimPessoaFisica();
        $fisica->setRawAttributes(['cd_pessoa' => 7, 'ds_nome_oficial' => 'Ana Oficial', 'ds_cpf' => '123'], true);
        $pessoa->setRelation('fisica', $fisica);
        $pessoa->setRelation('juridica', null);

        $saida = PessoaResource::um($pessoa);

        // assertSame, não assertEquals: o array esperado tem dez expectativas `null` que
        // assertEquals deixaria passar contra '', 0 ou false (comparação frouxa) -- o
        // ponto do teste é provar o contrato exato do valor devolvido, não só sua forma.
        $this->assertSame([
            'cd_pessoa' => 7,
            'cd_cliente' => 20,
            'ds_nome' => 'Ana',
            'ds_login' => 'ana.teste',
            'sn_pessoa_juridica' => false,
            'fisica' => [
                'ds_nome_oficial' => 'Ana Oficial',
                'ds_nome_social' => null,
                'ds_nome_mae' => null,
                'ds_nome_pai' => null,
                'ds_cpf' => '123',
                'ds_identidade' => null,
                'ds_orgao_estado' => null,
                'ds_identidade_orgao_exp' => null,
                'dt_identidade_expedicao' => null,
                'dt_nascimento' => null,
                'ds_sexo' => null,
                'cd_estado_civil' => null,
            ],
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
        // Conjunto exato, não apenas ausência de dois campos: pin nas cinco chaves
        // sensíveis fora e nas sete não sensíveis dentro, para que perder OU ganhar uma
        // flag `sensivel: true` em qualquer campo do mapa quebre este teste.
        $this->assertEqualsCanonicalizing(
            ['ds_nome_oficial', 'ds_nome_social', 'ds_orgao_estado', 'ds_identidade_orgao_exp', 'dt_identidade_expedicao', 'ds_sexo', 'cd_estado_civil'],
            array_keys($semFields['fisica'])
        );

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
}
