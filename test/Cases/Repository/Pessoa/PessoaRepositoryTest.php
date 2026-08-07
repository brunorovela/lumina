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

namespace HyperfTest\Cases\Repository\Pessoa;

use App\Exception\Pessoa\PessoaNaoEncontradaException;
use App\Repository\Pessoa\PessoaRepositoryInterface;
use App\Resource\Pessoa\MapaDeCamposPessoa;
use Hyperf\DbConnection\Db;
use Hyperf\Testing\TestCase;
use HyperfTest\Support\TenantDeTeste;

/**
 * @internal
 * @coversNothing
 */
class PessoaRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        // Este repositório não cria mais filho em unim_pessoa_fisica/unim_pessoa_juridica;
        // o teste que simula dado de outro recurso limpa a linha que insere.
        Db::table('unim_pessoa')->where('ds_login', 'like', 'teste.repo.%')->delete();

        parent::tearDown();
    }

    public function testCriarSalvaSomenteAPessoa()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $pessoa = $repository->criar([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'Fulano de Teste',
            'ds_login' => 'teste.repo.pessoa',
            'ds_senha' => password_hash('123456', PASSWORD_BCRYPT),
            'sn_pessoa_juridica' => false,
        ]);

        $this->assertNotNull($pessoa->cd_pessoa);
        $this->assertSame('Fulano de Teste', $pessoa->ds_nome);
        $this->assertSame(0, Db::table('unim_pessoa_fisica')->where('cd_pessoa', $pessoa->cd_pessoa)->count());
    }

    public function testLoginExisteDetectaDuplicataPorCliente()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $repository->criar([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'Ciclano de Teste',
            'ds_login' => 'teste.repo.duplicado',
            'ds_senha' => password_hash('123456', PASSWORD_BCRYPT),
            'sn_pessoa_juridica' => false,
        ]);

        $this->assertTrue($repository->loginExiste(TenantDeTeste::cdCliente(), 'teste.repo.duplicado'));
        $this->assertFalse($repository->loginExiste(TenantDeTeste::cdClienteInexistente(), 'teste.repo.duplicado'));
    }

    public function testAtualizarMantemSenhaAtualQuandoNaoInformada()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $pessoa = $repository->criar([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'Atualiza Teste',
            'ds_login' => 'teste.repo.atualiza',
            'ds_senha' => 'hash-original',
            'sn_pessoa_juridica' => false,
        ]);

        $atualizada = $repository->atualizar(
            $pessoa->cd_pessoa,
            TenantDeTeste::cdCliente(),
            ['ds_nome' => 'Atualiza Teste Renomeado']
        );

        $this->assertSame('Atualiza Teste Renomeado', $atualizada->ds_nome);
        $this->assertSame('hash-original', $atualizada->ds_senha);
    }

    public function testAtualizarPessoaInexistenteLancaExcecao()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $this->expectException(PessoaNaoEncontradaException::class);

        $repository->atualizar(999999, TenantDeTeste::cdCliente(), ['ds_nome' => 'Nao Existe']);
    }

    /**
     * Cross-tenant: o UPDATE tem cd_cliente no WHERE. Sem isso, um id de outro tenant seria
     * atualizável — e a linha existe de verdade, então o teste falha (200 onde deveria ser
     * 404) se o WHERE cair.
     */
    public function testAtualizarPessoaDeOutroClienteLancaExcecaoENaoAlteraALinha()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $pessoa = $repository->criar([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'Cross Tenant Update',
            'ds_login' => 'teste.repo.crosstenant',
            'ds_senha' => 'x',
            'sn_pessoa_juridica' => false,
        ]);

        try {
            $repository->atualizar($pessoa->cd_pessoa, TenantDeTeste::cdClienteInexistente(), ['ds_nome' => 'Invadido']);
            $this->fail('Atualizar pessoa de outro cliente deveria lancar PessoaNaoEncontradaException.');
        } catch (PessoaNaoEncontradaException) {
            $linha = Db::table('unim_pessoa')->where('cd_pessoa', $pessoa->cd_pessoa)->first();
            $this->assertSame('Cross Tenant Update', $linha->ds_nome);
        }
    }

    /**
     * Nenhuma escrita de pessoa apaga linha das tabelas de outro recurso — nem quando
     * sn_pessoa_juridica é invertido, que era exatamente o caso em que a versão anterior
     * apagava a linha antiga (e com ela o CPF).
     */
    public function testAtualizarNaoApagaLinhaDeOutroRecurso()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $pessoa = $repository->criar([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'Com Juridica De Outro Recurso',
            'ds_login' => 'teste.repo.outrorecurso',
            'ds_senha' => 'x',
            'sn_pessoa_juridica' => true,
        ]);

        Db::table('unim_pessoa_juridica')->insert([
            'cd_pessoa' => $pessoa->cd_pessoa,
            'ds_cnpj' => '00000000000191',
            'ds_nome_fantasia' => 'Fantasia De Outro Recurso',
        ]);

        try {
            $repository->atualizar(
                $pessoa->cd_pessoa,
                TenantDeTeste::cdCliente(),
                ['ds_nome' => 'Renomeada', 'sn_pessoa_juridica' => false]
            );

            $this->assertNotNull(
                Db::table('unim_pessoa_juridica')->where('cd_pessoa', $pessoa->cd_pessoa)->first(),
                'Escrita de pessoa NAO pode apagar linha de unim_pessoa_juridica.'
            );
        } finally {
            Db::table('unim_pessoa_juridica')->where('cd_pessoa', $pessoa->cd_pessoa)->delete();
        }
    }

    public function testListarFiltraPorNomeETipoPessoaEPaginaCertoDentroDoCliente()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $repository->criar(
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Maria Fisica Teste', 'ds_login' => 'teste.repo.listar1', 'ds_senha' => 'x', 'sn_pessoa_juridica' => false]
        );
        $repository->criar(
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Empresa Juridica Teste', 'ds_login' => 'teste.repo.listar2', 'ds_senha' => 'x', 'sn_pessoa_juridica' => true]
        );

        $resultado = $repository->listar(TenantDeTeste::cdCliente(), ['nome' => 'Teste', 'tipo_pessoa' => 'fisica'], 1, 20);

        $this->assertSame(1, $resultado['total']);
        $this->assertSame('Maria Fisica Teste', $resultado['itens']->first()->ds_nome);
    }

    public function testLoginExisteContinuaTrueParaPessoaExcluida()
    {
        // Finding 4 (whole-branch review): o índice UNIQUE (cd_cliente, ds_login) do banco
        // não filtra por dt_excluido, então loginExiste() precisa considerar withTrashed()
        // -- senão o login de uma pessoa excluída fica "livre" pra checagem de negócio e só
        // estoura como erro de banco genérico quando alguém tenta recriar.
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $pessoa = $repository->criar(
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Login Reciclado Teste', 'ds_login' => 'teste.repo.loginreciclado', 'ds_senha' => 'x', 'sn_pessoa_juridica' => false]
        );

        $repository->excluir($pessoa->cd_pessoa, TenantDeTeste::cdCliente());

        $this->assertTrue($repository->loginExiste(TenantDeTeste::cdCliente(), 'teste.repo.loginreciclado'));
    }

    public function testExcluirEhSoftDeleteNaoRemoveLinha()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $pessoa = $repository->criar(
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Exclui Teste', 'ds_login' => 'teste.repo.excluir', 'ds_senha' => 'x', 'sn_pessoa_juridica' => false]
        );

        $this->assertTrue($repository->excluir($pessoa->cd_pessoa, TenantDeTeste::cdCliente()));
        $this->assertNull($repository->buscarPorId($pessoa->cd_pessoa, TenantDeTeste::cdCliente()));

        $linhaCrua = Db::table('unim_pessoa')->where('cd_pessoa', $pessoa->cd_pessoa)->first();
        $this->assertNotNull($linhaCrua);
        $this->assertNotNull($linhaCrua->dt_excluido);
    }

    /**
     * buscarPorId() não recorta por fields de propósito: o detalhe é cacheado por entidade
     * (uma chave por pessoa) e o recorte roda na serialização. Se este teste passar a ver
     * menos colunas, o cache passa a devolver registro incompleto para quem pediu outro
     * fields.
     */
    public function testBuscarPorIdTrazTodasAsColunasDoMapaENuncaASenha()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $pessoa = $repository->criar(
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Selecao Item', 'ds_login' => 'teste.repo.selitem', 'ds_senha' => 'hash-secreto', 'sn_pessoa_juridica' => false]
        );

        $encontrada = $repository->buscarPorId($pessoa->cd_pessoa, TenantDeTeste::cdCliente());

        $this->assertNotNull($encontrada);
        $this->assertEqualsCanonicalizing(MapaDeCamposPessoa::colunas(), array_keys($encontrada->getAttributes()));
        $this->assertArrayNotHasKey('ds_senha', $encontrada->getAttributes());
    }

    public function testBuscarPorIdDeOutroClienteDevolveNulo()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $pessoa = $repository->criar(
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Item De Outro Cliente', 'ds_login' => 'teste.repo.itemoutro', 'ds_senha' => 'x', 'sn_pessoa_juridica' => false]
        );

        $this->assertNull($repository->buscarPorId($pessoa->cd_pessoa, TenantDeTeste::cdClienteInexistente()));
    }

    /**
     * O SELECT parcial da listagem continua real: sem fields, só o conjunto enxuto do mapa
     * chega ao model. Um select() completo aqui passaria despercebido em qualquer teste de
     * resposta HTTP, porque o Resource recorta a saída de qualquer forma.
     */
    public function testListarComFieldsFazSelectParcialDeVerdade()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $repository->criar(
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Selecao Enxuta', 'ds_login' => 'teste.repo.selenxuta', 'ds_senha' => 'x', 'sn_pessoa_juridica' => false]
        );

        $resultado = $repository->listar(
            TenantDeTeste::cdCliente(),
            ['nome' => 'Selecao Enxuta'],
            1,
            20,
            MapaDeCamposPessoa::selecao('ds_nome')
        );

        $pessoa = $resultado['itens']->first();

        $this->assertNotNull($pessoa);
        $this->assertSame(['ds_nome'], array_keys($pessoa->getAttributes()));
    }

    /**
     * A listagem não carrega relação nenhuma: elas saíram do mapa junto com as tabelas de
     * outro recurso. relationLoaded() === false prova que nem o eager load nem um lazy load
     * acidental acontecem.
     */
    public function testListarNaoCarregaRelacaoNenhuma()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $repository->criar(
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Sem Relacao', 'ds_login' => 'teste.repo.semrelacao', 'ds_senha' => 'x', 'sn_pessoa_juridica' => false]
        );

        $resultado = $repository->listar(TenantDeTeste::cdCliente(), ['nome' => 'Sem Relacao'], 1, 20, MapaDeCamposPessoa::selecao('*'));

        $pessoa = $resultado['itens']->first();

        $this->assertNotNull($pessoa);
        $this->assertFalse($pessoa->relationLoaded('fisica'));
        $this->assertFalse($pessoa->relationLoaded('juridica'));
    }
}
