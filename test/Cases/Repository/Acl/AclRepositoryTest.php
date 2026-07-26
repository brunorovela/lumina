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

namespace HyperfTest\Cases\Repository\Acl;

use App\Repository\Acl\AclRepository;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AclRepositoryTest extends TestCase
{
    public function testBuscarPermissoesPorPerfilAgrupaPorRecurso()
    {
        $repository = $this->getContainer()->get(AclRepository::class);

        $permissoes = $repository->buscarPermissoesPorPerfil(1);

        $this->assertNotEmpty($permissoes);

        foreach ($permissoes as $recurso => $privilegios) {
            $this->assertIsString($recurso);
            $this->assertIsArray($privilegios);
        }
    }
}
