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

namespace HyperfTest\Cases\Service\Acl;

use App\Service\Acl\AclService;
use Hyperf\Redis\Redis;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AclServiceTest extends TestCase
{
    public function testIsAllowedUsaCacheAposPrimeiraConsulta()
    {
        $aclService = $this->getContainer()->get(AclService::class);
        $redis = $this->getContainer()->get(Redis::class);

        $redis->del('acl:perfil:1');

        // primeira chamada monta do banco e grava no cache
        $aclService->isAllowed([1], 'pessoa', 'listar');

        $this->assertNotEmpty($redis->get('acl:perfil:1'));
    }

    public function testIsAllowedRetornaTrueSeQualquerPerfilDaListaConceder()
    {
        $aclService = $this->getContainer()->get(AclService::class);
        $redis = $this->getContainer()->get(Redis::class);

        $redis->setex('acl:perfil:1', 3600, json_encode(['pessoa' => []]));
        $redis->setex('acl:perfil:2', 3600, json_encode(['pessoa' => ['listar']]));

        $this->assertTrue($aclService->isAllowed([1, 2], 'pessoa', 'listar'));
        $this->assertFalse($aclService->isAllowed([1], 'pessoa', 'listar'));
    }

    public function testInvalidarRemoveOCache()
    {
        $aclService = $this->getContainer()->get(AclService::class);
        $redis = $this->getContainer()->get(Redis::class);

        $aclService->isAllowed([1], 'pessoa', 'listar');
        $this->assertNotEmpty($redis->get('acl:perfil:1'));

        $aclService->invalidar(1);
        $this->assertFalse($redis->get('acl:perfil:1'));
    }
}
