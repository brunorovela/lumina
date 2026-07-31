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
