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

namespace HyperfTest\Cases\Service\Auth;

use App\Exception\Auth\CredenciaisInvalidasException;
use App\Service\Auth\AuthService;
use Hyperf\DbConnection\Db;
use Hyperf\Testing\TestCase;
use HyperfTest\Support\TenantDeTeste;

/**
 * @internal
 * @coversNothing
 */
class AuthServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Db::table('lgin_pessoa_perfil')->whereIn('cd_pessoa', function ($query) {
            $query->select('cd_pessoa')->from('unim_pessoa')->where('ds_login', 'like', 'teste.auth.%');
        })->delete();
        Db::table('unim_coligada')->whereIn('cd_pessoa', function ($query) {
            $query->select('cd_pessoa')->from('unim_pessoa')->where('ds_login', 'like', 'teste.auth.%');
        })->delete();
        Db::table('unim_pessoa')->where('ds_login', 'like', 'teste.auth.%')->delete();
        parent::tearDown();
    }

    public function testAutenticaComSenhaBcryptEGeraTokenComListaDePerfis()
    {
        // Cliente/perfil/idioma vêm do tenant descartável da suíte (HyperfTest\Support\TenantDeTeste):
        // os ids fixos que estavam aqui (cd_cliente=1, cd_perfil=1) não existem no banco.
        $cdPessoa = Db::table('unim_pessoa')->insertGetId([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'Auth Bcrypt Teste',
            'ds_login' => 'teste.auth.bcrypt',
            'ds_senha' => password_hash('minhasenha', PASSWORD_BCRYPT),
            'sn_pessoa_juridica' => 0,
        ]);

        $cdColigada = TenantDeTeste::criarColigada($cdPessoa);

        $cdPerfilUm = TenantDeTeste::cdPerfil();
        $cdPerfilDois = TenantDeTeste::cdPerfil('SECUNDARIO');

        Db::table('lgin_pessoa_perfil')->insert([
            ['cd_pessoa' => $cdPessoa, 'cd_perfil' => $cdPerfilUm, 'cd_coligada' => $cdColigada],
            ['cd_pessoa' => $cdPessoa, 'cd_perfil' => $cdPerfilDois, 'cd_coligada' => $cdColigada],
        ]);

        $authService = $this->getContainer()->get(AuthService::class);
        $token = $authService->autenticar(TenantDeTeste::cdCliente(), 'teste.auth.bcrypt', 'minhasenha');

        $this->assertNotEmpty($token);

        $identidade = $authService->identidadePorToken($token);
        $this->assertSame($cdPessoa, $identidade['cd_pessoa']);
        $this->assertEqualsCanonicalizing([$cdPerfilUm, $cdPerfilDois], $identidade['cd_perfis']);

        $authService->logout($token);
        $this->assertNull($authService->identidadePorToken($token));
    }

    public function testAutenticaSemVinculoDePerfilRetornaListaVazia()
    {
        Db::table('unim_pessoa')->insert([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'Auth Sem Perfil Teste',
            'ds_login' => 'teste.auth.semperfil',
            'ds_senha' => password_hash('minhasenha', PASSWORD_BCRYPT),
            'sn_pessoa_juridica' => 0,
        ]);

        $authService = $this->getContainer()->get(AuthService::class);
        $token = $authService->autenticar(TenantDeTeste::cdCliente(), 'teste.auth.semperfil', 'minhasenha');

        $identidade = $authService->identidadePorToken($token);
        $this->assertSame([], $identidade['cd_perfis']);
    }

    public function testAutenticaComSenhaMd5EFazUpgradeSilenciosoPraBcrypt()
    {
        Db::table('unim_pessoa')->insert([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'Auth Md5 Teste',
            'ds_login' => 'teste.auth.md5',
            'ds_senha' => md5('senhafraca'),
            'sn_pessoa_juridica' => 0,
        ]);

        $authService = $this->getContainer()->get(AuthService::class);
        $token = $authService->autenticar(TenantDeTeste::cdCliente(), 'teste.auth.md5', 'senhafraca');

        $this->assertNotEmpty($token);

        $hashAtual = Db::table('unim_pessoa')->where('ds_login', 'teste.auth.md5')->value('ds_senha');
        $this->assertTrue(password_verify('senhafraca', $hashAtual));
    }

    public function testAutenticaComSenhaTextoPuroEFazUpgradeSilenciosoPraBcrypt()
    {
        Db::table('unim_pessoa')->insert([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'Auth Texto Puro Teste',
            'ds_login' => 'teste.auth.textopuro',
            'ds_senha' => 'senhasemhash',
            'sn_pessoa_juridica' => 0,
        ]);

        $authService = $this->getContainer()->get(AuthService::class);
        $token = $authService->autenticar(TenantDeTeste::cdCliente(), 'teste.auth.textopuro', 'senhasemhash');

        $this->assertNotEmpty($token);

        $hashAtual = Db::table('unim_pessoa')->where('ds_login', 'teste.auth.textopuro')->value('ds_senha');
        $this->assertTrue(password_verify('senhasemhash', $hashAtual));
    }

    public function testSenhaErradaNaoAutentica()
    {
        Db::table('unim_pessoa')->insert([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'Auth Errada Teste',
            'ds_login' => 'teste.auth.errada',
            'ds_senha' => password_hash('correta', PASSWORD_BCRYPT),
            'sn_pessoa_juridica' => 0,
        ]);

        $authService = $this->getContainer()->get(AuthService::class);

        $this->expectException(CredenciaisInvalidasException::class);
        $authService->autenticar(TenantDeTeste::cdCliente(), 'teste.auth.errada', 'errada');
    }
}
