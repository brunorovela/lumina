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

namespace HyperfTest\Cases\Repository\Auth;

use App\Repository\Auth\AuthRepositoryInterface;
use Hyperf\DbConnection\Db;
use Hyperf\Testing\TestCase;
use HyperfTest\Support\TenantDeTeste;

/**
 * Finding 13 (whole-branch review): AuthService falava SQL direto via Hyperf\DbConnection\Db
 * em 3 tabelas, fora do padrão de Repository que Pessoa segue. Extraído pra
 * App\Repository\Auth\AuthRepository -- estes testes cobrem o Repository isoladamente
 * (os testes de comportamento de autenticação em si continuam em AuthServiceTest).
 *
 * @internal
 * @coversNothing
 */
class AuthRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Db::table('lgin_pessoa_perfil')->whereIn('cd_pessoa', function ($query) {
            $query->select('cd_pessoa')->from('unim_pessoa')->where('ds_login', 'like', 'teste.authrepo.%');
        })->delete();
        Db::table('unim_coligada')->whereIn('cd_pessoa', function ($query) {
            $query->select('cd_pessoa')->from('unim_pessoa')->where('ds_login', 'like', 'teste.authrepo.%');
        })->delete();
        Db::table('unim_pessoa')->where('ds_login', 'like', 'teste.authrepo.%')->delete();
        parent::tearDown();
    }

    public function testBuscarPessoaAtivaPorLoginEClienteEncontraEIgnoraExcluida()
    {
        $repository = $this->getContainer()->get(AuthRepositoryInterface::class);

        $cdPessoa = Db::table('unim_pessoa')->insertGetId([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'Auth Repo Teste',
            'ds_login' => 'teste.authrepo.ativa',
            'ds_senha' => 'hash',
            'sn_pessoa_juridica' => 0,
        ]);

        $encontrada = $repository->buscarPessoaAtivaPorLoginECliente(TenantDeTeste::cdCliente(), 'teste.authrepo.ativa');
        $this->assertNotNull($encontrada);
        $this->assertSame($cdPessoa, $encontrada->cd_pessoa);

        Db::table('unim_pessoa')->where('cd_pessoa', $cdPessoa)->update(['dt_excluido' => date('Y-m-d H:i:s')]);

        $depoisDeExcluir = $repository->buscarPessoaAtivaPorLoginECliente(TenantDeTeste::cdCliente(), 'teste.authrepo.ativa');
        $this->assertNull($depoisDeExcluir);
    }

    public function testBuscarPerfisDaPessoaRetornaPerfisDaColigadaDoCliente()
    {
        $repository = $this->getContainer()->get(AuthRepositoryInterface::class);

        $cdPessoa = Db::table('unim_pessoa')->insertGetId([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'Auth Repo Perfis',
            'ds_login' => 'teste.authrepo.perfis',
            'ds_senha' => 'hash',
            'sn_pessoa_juridica' => 0,
        ]);

        $cdColigada = TenantDeTeste::criarColigada($cdPessoa);

        $cdPerfilUm = TenantDeTeste::cdPerfil();
        $cdPerfilDois = TenantDeTeste::cdPerfil('SECUNDARIO');

        Db::table('lgin_pessoa_perfil')->insert([
            ['cd_pessoa' => $cdPessoa, 'cd_perfil' => $cdPerfilUm, 'cd_coligada' => $cdColigada],
            ['cd_pessoa' => $cdPessoa, 'cd_perfil' => $cdPerfilDois, 'cd_coligada' => $cdColigada],
        ]);

        $perfis = $repository->buscarPerfisDaPessoa($cdPessoa, TenantDeTeste::cdCliente());

        // Canonizing: a query não declara ORDER BY, então a ordem não faz parte do contrato
        // — afirmar ordem aqui seria testar detalhe de implementação do MySQL.
        $this->assertEqualsCanonicalizing([$cdPerfilUm, $cdPerfilDois], $perfis);
    }

    public function testAtualizarSenhaGravaOHashInformado()
    {
        $repository = $this->getContainer()->get(AuthRepositoryInterface::class);

        $cdPessoa = Db::table('unim_pessoa')->insertGetId([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'Auth Repo Senha',
            'ds_login' => 'teste.authrepo.senha',
            'ds_senha' => 'hash-antigo',
            'sn_pessoa_juridica' => 0,
        ]);

        $repository->atualizarSenha($cdPessoa, 'hash-novo');

        $hashAtual = Db::table('unim_pessoa')->where('cd_pessoa', $cdPessoa)->value('ds_senha');
        $this->assertSame('hash-novo', $hashAtual);
    }
}
