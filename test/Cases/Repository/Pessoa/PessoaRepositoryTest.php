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
                'cd_cliente' => TenantDeTeste::cdCliente(),
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
                'cd_cliente' => TenantDeTeste::cdCliente(),
                'ds_nome' => 'Ciclano de Teste',
                'ds_login' => 'teste.repo.duplicado',
                'ds_senha' => password_hash('123456', PASSWORD_BCRYPT),
                'sn_pessoa_juridica' => false,
            ],
            ['ds_nome_oficial' => 'Ciclano'],
            null
        );

        $this->assertTrue($repository->loginExiste(TenantDeTeste::cdCliente(), 'teste.repo.duplicado'));
        $this->assertFalse($repository->loginExiste(2, 'teste.repo.duplicado'));
    }

    public function testAtualizarMantemSenhaAtualQuandoNaoInformada()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $pessoa = $repository->criar(
            [
                'cd_cliente' => TenantDeTeste::cdCliente(),
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
            TenantDeTeste::cdCliente(),
            ['ds_nome' => 'Atualiza Teste Renomeado'],
            null,
            null
        );

        $this->assertSame('Atualiza Teste Renomeado', $atualizada->ds_nome);
        $this->assertSame('hash-original', $atualizada->ds_senha);
    }

    public function testAtualizarPessoaInexistenteLancaExcecao()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $this->expectException(PessoaNaoEncontradaException::class);

        $repository->atualizar(999999, 1, ['ds_nome' => 'Nao Existe'], null, null);
    }

    public function testAtualizarComEhIsentoDeFisicaJuridicaNuncaApagaFilhoOrfao()
    {
        // Regressão (re-review pós-fix do Critical 1): o delete de filho órfão rodava
        // sempre que $dadosFisica/$dadosJuridica vinham null junto de sn_pessoa_juridica,
        // mas pessoas isentas (login admin/administrador) SEMPRE mandam os dois null --
        // não porque o tipo mudou, mas porque a regra de negócio nunca aplica
        // física/jurídica a elas. Sem o guard $ehIsentoDeFisicaJuridica, um PUT válido
        // numa pessoa isenta com fisica/juridica órfã de dado legado apagava essa linha
        // (reproduzido de verdade contra cd_pessoa=1/2, cd_cliente=23, fora deste teste).
        // Aqui simulamos o mesmo cenário com dado de teste: uma pessoa "isenta" (no
        // sentido do parâmetro, independente do login) com uma linha órfã em
        // unim_pessoa_juridica inserida direto no banco (só existiria por dado legado --
        // o fluxo normal da aplicação nunca cria filho pra pessoa isenta).
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $pessoa = $repository->criar(
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Isento Teste', 'ds_login' => 'teste.repo.isento', 'ds_senha' => 'x', 'sn_pessoa_juridica' => false],
            null,
            null
        );

        Db::table('unim_pessoa_juridica')->insert([
            'cd_pessoa' => $pessoa->cd_pessoa,
            'ds_cnpj' => '00000000000191',
            'ds_nome_fantasia' => 'Fantasia Orfa De Dado Legado',
        ]);

        $atualizada = $repository->atualizar(
            $pessoa->cd_pessoa,
            TenantDeTeste::cdCliente(),
            ['ds_nome' => 'Isento Teste Renomeado', 'sn_pessoa_juridica' => false],
            null,
            null,
            ehIsentoDeFisicaJuridica: true
        );

        $this->assertSame('Isento Teste Renomeado', $atualizada->ds_nome);

        $linhaJuridica = Db::table('unim_pessoa_juridica')->where('cd_pessoa', $pessoa->cd_pessoa)->first();
        $this->assertNotNull($linhaJuridica, 'PUT valido numa pessoa isenta NAO pode apagar juridica orfa de dado legado.');
    }

    public function testListarFiltraPorNomeETipoPessoaEPaginaCertoDentroDoCliente()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $repository->criar(
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Maria Fisica Teste', 'ds_login' => 'teste.repo.listar1', 'ds_senha' => 'x', 'sn_pessoa_juridica' => false],
            ['ds_nome_oficial' => 'Maria Fisica Teste'],
            null
        );
        $repository->criar(
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Empresa Juridica Teste', 'ds_login' => 'teste.repo.listar2', 'ds_senha' => 'x', 'sn_pessoa_juridica' => true],
            null,
            ['ds_cnpj' => '00000000000191', 'ds_nome_fantasia' => 'Empresa Juridica Teste']
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
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Login Reciclado Teste', 'ds_login' => 'teste.repo.loginreciclado', 'ds_senha' => 'x', 'sn_pessoa_juridica' => false],
            ['ds_nome_oficial' => 'Login Reciclado Teste'],
            null
        );

        $repository->excluir($pessoa->cd_pessoa, TenantDeTeste::cdCliente());

        $this->assertTrue($repository->loginExiste(TenantDeTeste::cdCliente(), 'teste.repo.loginreciclado'));
    }

    public function testExcluirEhSoftDeleteNaoRemoveLinha()
    {
        $repository = $this->getContainer()->get(PessoaRepositoryInterface::class);

        $pessoa = $repository->criar(
            ['cd_cliente' => TenantDeTeste::cdCliente(), 'ds_nome' => 'Exclui Teste', 'ds_login' => 'teste.repo.excluir', 'ds_senha' => 'x', 'sn_pessoa_juridica' => false],
            ['ds_nome_oficial' => 'Exclui Teste'],
            null
        );

        $this->assertTrue($repository->excluir($pessoa->cd_pessoa, TenantDeTeste::cdCliente()));
        $this->assertNull($repository->buscarPorId($pessoa->cd_pessoa, TenantDeTeste::cdCliente()));

        $linhaCrua = Db::table('unim_pessoa')->where('cd_pessoa', $pessoa->cd_pessoa)->first();
        $this->assertNotNull($linhaCrua);
        $this->assertNotNull($linhaCrua->dt_excluido);
    }

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
        $this->assertNotNull($pessoa->fisica, 'fisica veio null no caminho default: chave estrangeira ausente no eager load.');
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
            MapaDeCamposPessoa::selecao('ds_nome,fisica.ds_cpf,fisica.dt_nascimento')
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
}
