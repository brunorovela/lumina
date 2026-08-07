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
use App\Resource\Pessoa\MapaDeCamposPessoa;
use App\Resource\Pessoa\PessoaResource;
use PHPUnit\Framework\TestCase;

/**
 * Sem banco de propósito: os models são montados em memória com setRawAttributes(), o que
 * permite afirmar que o Resource não dispara consulta nenhuma (aqui não há conexão para
 * atender).
 *
 * @internal
 * @coversNothing
 */
class PessoaResourceTest extends TestCase
{
    public function testSelecaoNulaDevolveOContratoCompleto()
    {
        $saida = PessoaResource::um($this->pessoa());

        // assertSame, não assertEquals: assertEquals deixaria `false` passar contra 0/''.
        $this->assertSame([
            'cd_pessoa' => 7,
            'cd_cliente' => 20,
            'ds_nome' => 'Ana',
            'ds_login' => 'ana.teste',
            'sn_pessoa_juridica' => false,
        ], $saida);
    }

    public function testRecortaExatamenteOsCamposPedidos()
    {
        $saida = PessoaResource::um($this->pessoa(), MapaDeCamposPessoa::selecao('ds_nome'));

        $this->assertSame(['ds_nome' => 'Ana'], $saida);
    }

    /**
     * A resposta não expõe mais fisica/juridica nem quando a relação está carregada no
     * model: o que a API expõe é o mapa, e o mapa não tem relação. Sem esta garantia, um
     * model que chegue com a relação carregada por outro caminho voltaria a vazar PII de
     * outro recurso.
     */
    public function testRelacaoCarregadaNoModelNaoAparecaNaResposta()
    {
        $pessoa = $this->pessoa();
        $pessoa->setRelation('fisica', null);

        $saida = PessoaResource::um($pessoa, MapaDeCamposPessoa::selecao('*'));

        $this->assertArrayNotHasKey('fisica', $saida);
        $this->assertArrayNotHasKey('juridica', $saida);
    }

    public function testNaoTocaRelacaoNenhuma()
    {
        $pessoa = $this->pessoa();

        PessoaResource::um($pessoa, MapaDeCamposPessoa::selecao(null, padraoEhTudo: true));

        // Tocar uma relação não carregada dispararia lazy load — sem conexão, o teste
        // quebraria em vez de passar; relationLoaded() falso prova que nem isso aconteceu.
        $this->assertFalse($pessoa->relationLoaded('fisica'));
        $this->assertFalse($pessoa->relationLoaded('juridica'));
    }

    public function testMuitosAplicaAMesmaSelecaoEmTodosOsItens()
    {
        $saida = PessoaResource::muitos([$this->pessoa(), $this->pessoa()], MapaDeCamposPessoa::selecao('ds_nome'));

        $this->assertSame([['ds_nome' => 'Ana'], ['ds_nome' => 'Ana']], $saida);
    }

    /**
     * O default do ITEM traz tudo (nenhum campo de unim_pessoa é sensível hoje), e o da
     * LISTA é o conjunto enxuto — a diferença é cd_cliente. Se um campo do mapa ganhar
     * `sensivel: true`, este teste é o que denuncia a mudança de default.
     */
    public function testDefaultDoItemEDaListaDiferemApenasEmCdCliente()
    {
        $item = PessoaResource::um($this->pessoa(), MapaDeCamposPessoa::selecao(null, padraoEhTudo: true));
        $lista = PessoaResource::um($this->pessoa(), MapaDeCamposPessoa::selecao(null));

        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'cd_cliente', 'ds_nome', 'ds_login', 'sn_pessoa_juridica'],
            array_keys($item)
        );
        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'ds_nome', 'ds_login', 'sn_pessoa_juridica'],
            array_keys($lista)
        );
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
