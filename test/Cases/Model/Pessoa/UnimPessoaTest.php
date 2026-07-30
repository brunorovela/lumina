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

namespace HyperfTest\Cases\Model\Pessoa;

use App\Model\Pessoa\UnimPessoa;
use Hyperf\DbConnection\Db;
use Hyperf\Testing\TestCase;
use HyperfTest\Support\TenantDeTeste;

/**
 * @internal
 * @coversNothing
 */
class UnimPessoaTest extends TestCase
{
    protected function tearDown(): void
    {
        Db::table('unim_pessoa')->where('ds_login', 'teste.model.unimpessoa')->delete();
        parent::tearDown();
    }

    public function testSoftDeleteEsconqueLinhaSemApagar()
    {
        $pessoa = UnimPessoa::create([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'Pessoa Teste Model',
            'ds_login' => 'teste.model.unimpessoa',
            'ds_senha' => password_hash('123456', PASSWORD_BCRYPT),
            'sn_pessoa_juridica' => false,
        ]);

        $pessoa->delete();

        $this->assertNull(UnimPessoa::find($pessoa->cd_pessoa));
        $this->assertNotNull(UnimPessoa::withTrashed()->find($pessoa->cd_pessoa));

        $linhaCrua = Db::table('unim_pessoa')->where('cd_pessoa', $pessoa->cd_pessoa)->first();
        $this->assertNotNull($linhaCrua->dt_excluido);
    }
}
