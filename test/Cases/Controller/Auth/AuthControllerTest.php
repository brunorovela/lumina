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

namespace HyperfTest\Cases\Controller\Auth;

use Hyperf\DbConnection\Db;
use Hyperf\Testing\TestCase;
use HyperfTest\Support\TenantDeTeste;

/**
 * @internal
 * @coversNothing
 */
class AuthControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Db::table('unim_pessoa')->where('ds_login', 'teste.controller.auth')->delete();
        parent::tearDown();
    }

    public function testLoginComCredenciaisValidasRetornaToken()
    {
        Db::table('unim_pessoa')->insert([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'Controller Auth Teste',
            'ds_login' => 'teste.controller.auth',
            'ds_senha' => password_hash('123456', PASSWORD_BCRYPT),
            'sn_pessoa_juridica' => 0,
        ]);

        $resposta = $this->post('/auth/login', [
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_login' => 'teste.controller.auth',
            'ds_senha' => '123456',
        ]);

        $resposta->assertStatus(200);
        $this->assertTrue($resposta->json('success'));
        $this->assertNotEmpty($resposta->json('data.token'));
    }

    public function testLoginComSenhaErradaRetorna401()
    {
        Db::table('unim_pessoa')->insert([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'Controller Auth Teste',
            'ds_login' => 'teste.controller.auth',
            'ds_senha' => password_hash('123456', PASSWORD_BCRYPT),
            'sn_pessoa_juridica' => 0,
        ]);

        $resposta = $this->post('/auth/login', [
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_login' => 'teste.controller.auth',
            'ds_senha' => 'errada',
        ]);

        $resposta->assertStatus(401);
    }
}
