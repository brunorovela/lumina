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

namespace HyperfTest\Cases\Support\Campos;

use App\Support\Campos\Campo;
use App\Support\Campos\SelecaoDeCampos;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SelecaoDeCamposTest extends TestCase
{
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

    /**
     * Guard de defesa: um mapa sem nenhum campo noPadrao faz o caminho DEFAULT (sem
     * `fields`) cair numa seleção sem colunas — select([]) geraria SQL inválido
     * ("select  from"). Isto é inalcançável por HTTP (a validação barra token desconhecido
     * antes), mas SelecaoDeCampos é reutilizável por outras Resources, e uma que esqueça o
     * noPadrao em todo o mapa cairia num 500 sem pista da causa se não fosse este guard.
     */
    public function testColunasComSelecaoSemNenhumCampoNoPadraoLancaLogicException()
    {
        $mapaSemPadrao = [
            'ds_nome' => Campo::coluna('ds_nome'),
            'cd_cliente' => Campo::coluna('cd_cliente'),
        ];

        $selecao = SelecaoDeCampos::de(null, $mapaSemPadrao, 'cd_pessoa');

        $this->assertSame([], $selecao->campos(), 'pré-condição: default sem noPadrao é vazio.');

        $this->expectException(LogicException::class);

        $selecao->colunas();
    }

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
}
