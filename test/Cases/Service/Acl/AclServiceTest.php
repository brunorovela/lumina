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

use App\Enum\Privilegio;
use App\Enum\Recurso;
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
        $aclService->isAllowed([1], Recurso::GERENCIAR_PESSOA, Privilegio::ACESSAR);

        $this->assertNotEmpty($redis->get('acl:perfil:1'));
    }

    public function testIsAllowedRetornaTrueSeQualquerPerfilDaListaConceder()
    {
        $aclService = $this->getContainer()->get(AclService::class);
        $redis = $this->getContainer()->get(Redis::class);

        $redis->setex('acl:perfil:1', 3600, json_encode(['GERENCIAR_PESSOA' => []]));
        $redis->setex('acl:perfil:2', 3600, json_encode(['GERENCIAR_PESSOA' => ['ACESSAR']]));

        $this->assertTrue($aclService->isAllowed([1, 2], Recurso::GERENCIAR_PESSOA, Privilegio::ACESSAR));
        $this->assertFalse($aclService->isAllowed([1], Recurso::GERENCIAR_PESSOA, Privilegio::ACESSAR));
    }

    public function testInvalidarRemoveOCache()
    {
        $aclService = $this->getContainer()->get(AclService::class);
        $redis = $this->getContainer()->get(Redis::class);

        $aclService->isAllowed([1], Recurso::GERENCIAR_PESSOA, Privilegio::ACESSAR);
        $this->assertNotEmpty($redis->get('acl:perfil:1'));

        $aclService->invalidar(1);
        $this->assertFalse($redis->get('acl:perfil:1'));
    }

    /**
     * Regressão: o cache guarda ds_chave crua, então uma chave que não existe no banco
     * (o bug antigo: 'pessoa'/'listar') tem que negar, e a chave certa tem que liberar
     * com a MESMA massa de cache.
     */
    public function testCacheDoBancoUsaDsChaveEmMaiusculoDoLms()
    {
        $aclService = $this->getContainer()->get(AclService::class);
        $redis = $this->getContainer()->get(Redis::class);

        $redis->setex('acl:perfil:1', 3600, json_encode([
            'GERENCIAR_PESSOA' => ['ACESSAR', 'INSERIR', 'ATUALIZAR'],
        ]));

        $this->assertTrue($aclService->isAllowed([1], Recurso::GERENCIAR_PESSOA, Privilegio::INSERIR));
        $this->assertFalse($aclService->isAllowed([1], Recurso::GERENCIAR_PESSOA, Privilegio::DELETAR));
        $this->assertFalse($aclService->isAllowed([1], Recurso::GERENCIAR_CURSO, Privilegio::ACESSAR));
    }

    public function testCacheCorrompidoCaiParaOBancoEmVezDeExplodir()
    {
        $aclService = $this->getContainer()->get(AclService::class);
        $redis = $this->getContainer()->get(Redis::class);

        $redis->setex('acl:perfil:1', 3600, 'nao-e-json');

        $this->assertFalse($aclService->isAllowed([1], Recurso::GERENCIAR_PESSOA, Privilegio::ACESSAR));
    }
}
