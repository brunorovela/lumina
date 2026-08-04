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

namespace HyperfTest\Cases\Repository\Dominio;

use App\Repository\Dominio\DominioRepository;
use Hyperf\DbConnection\Db;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DominioRepositoryTest extends TestCase
{
    private DominioRepository $repositorio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositorio = new DominioRepository();
    }

    public function testPaisesTrazApenasAsColunasExpostas()
    {
        $pais = $this->repositorio->paises()->first();

        $this->assertNotNull($pais);
        $this->assertSame(
            ['cd_pais', 'ds_pais', 'ds_nacionalidade'],
            array_keys($pais->getAttributes())
        );
    }

    public function testEstadosFiltradosPorPaisSoTrazemAquelePais()
    {
        $cdPais = (int) Db::table('saas_estado')->min('cd_pais');

        $estados = $this->repositorio->estados($cdPais);

        $this->assertGreaterThan(0, $estados->count());

        foreach ($estados as $estado) {
            $this->assertSame($cdPais, $estado->cd_pais);
        }
    }

    public function testEstadosSemFiltroTrazMaisDoQueUmPaisSozinhoOuIgual()
    {
        $cdPais = (int) Db::table('saas_estado')->min('cd_pais');

        $this->assertGreaterThanOrEqual(
            $this->repositorio->estados($cdPais)->count(),
            $this->repositorio->estados()->count()
        );
    }

    public function testCidadesExigemEstadoENuncaVazamOutroEstado()
    {
        $cdEstado = (int) Db::table('saas_cidade')->min('cd_estado');

        $cidades = $this->repositorio->cidades($cdEstado);

        $this->assertGreaterThan(0, $cidades->count());

        foreach ($cidades as $cidade) {
            $this->assertSame($cdEstado, $cidade->cd_estado);
        }
    }

    public function testCidadesFiltradasPorTermoCasamOTermo()
    {
        $cdEstado = (int) Db::table('saas_cidade')->min('cd_estado');
        $primeira = $this->repositorio->cidades($cdEstado)->first();

        $this->assertNotNull($primeira);

        $termo = mb_substr((string) $primeira->ds_cidade, 0, 3);
        $cidades = $this->repositorio->cidades($cdEstado, $termo);

        $this->assertGreaterThan(0, $cidades->count());

        foreach ($cidades as $cidade) {
            $this->assertStringContainsStringIgnoringCase($termo, (string) $cidade->ds_cidade);
        }
    }

    public function testEstadosCivisNaoTrazemColunaDeControleDoLms()
    {
        $estadoCivil = $this->repositorio->estadosCivis()->first();

        $this->assertNotNull($estadoCivil);
        $this->assertArrayNotHasKey('dt_base', $estadoCivil->getAttributes());
    }

    public function testTiposDeContatoTrazemChaveEDescricao()
    {
        $tipos = $this->repositorio->tiposDeContato();

        $this->assertGreaterThan(0, $tipos->count());

        $chaves = $tipos->pluck('ds_chave')->all();
        $this->assertContains('EMAIL', $chaves);
    }
}
