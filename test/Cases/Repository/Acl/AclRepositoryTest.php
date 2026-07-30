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

use App\Enum\Privilegio;
use App\Enum\Recurso;
use App\Repository\Acl\AclRepository;
use Hyperf\DbConnection\Db;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AclRepositoryTest extends TestCase
{
    /**
     * Antes este teste consultava cd_perfil=1, que não existe em lgin_perfil nesta base
     * (os perfis reais começam em 79) — vinha array vazio e o teste só provava que a
     * query não estoura. Agora ele descobre um perfil que realmente tem concessão.
     */
    public function testBuscarPermissoesPorPerfilAgrupaPorRecurso()
    {
        $repository = $this->getContainer()->get(AclRepository::class);

        $permissoes = $repository->buscarPermissoesPorPerfil($this->cdPerfilComPermissao());

        $this->assertNotEmpty($permissoes);

        foreach ($permissoes as $recurso => $privilegios) {
            $this->assertIsString($recurso);
            $this->assertIsArray($privilegios);
            $this->assertNotEmpty($privilegios);
        }
    }

    /**
     * O ponto do bug: as chaves agrupadas são ds_chave crua do banco. Se o Repository
     * devolvesse qualquer coisa fora de ulms_recurso/ulms_privilegio, os enums não
     * resolveriam e o middleware negaria tudo.
     */
    public function testChavesRetornadasSaoAsMesmasDosEnums()
    {
        $repository = $this->getContainer()->get(AclRepository::class);

        $permissoes = $repository->buscarPermissoesPorPerfil($this->cdPerfilComPermissao());

        foreach ($permissoes as $recurso => $privilegios) {
            $this->assertNotNull(Recurso::tryFrom($recurso), "Recurso '{$recurso}' não está em App\\Enum\\Recurso.");

            foreach ($privilegios as $privilegio) {
                $this->assertNotNull(
                    Privilegio::tryFrom($privilegio),
                    "Privilégio '{$privilegio}' não está em App\\Enum\\Privilegio."
                );
            }
        }
    }

    public function testPerfilSemConcessaoDevolveArrayVazio()
    {
        $repository = $this->getContainer()->get(AclRepository::class);

        $cdPerfilInexistente = ((int) Db::table('lgin_perfil')->max('cd_perfil')) + 1000;

        $this->assertSame([], $repository->buscarPermissoesPorPerfil($cdPerfilInexistente));
    }

    private function cdPerfilComPermissao(): int
    {
        $cdPerfil = Db::table('lgin_perfil_recurso_privilegio')->min('cd_perfil');

        if ($cdPerfil === null) {
            $this->markTestSkipped('Base sem nenhuma concessão em lgin_perfil_recurso_privilegio.');
        }

        return (int) $cdPerfil;
    }
}
