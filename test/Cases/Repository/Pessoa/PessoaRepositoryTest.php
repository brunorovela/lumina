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
        // unim_pessoa_fisica/unim_pessoa_juridica têm FK real (ON DELETE RESTRICT) para
        // unim_pessoa, então os filhos precisam ser apagados antes do núcleo.
        $cdPessoas = Db::table('unim_pessoa')->where('ds_login', 'like', 'teste.repo.%')->pluck('cd_pessoa');

        Db::table('unim_pessoa_fisica')->whereIn('cd_pessoa', $cdPessoas)->delete();
        Db::table('unim_pessoa_juridica')->whereIn('cd_pessoa', $cdPessoas)->delete();
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
}
