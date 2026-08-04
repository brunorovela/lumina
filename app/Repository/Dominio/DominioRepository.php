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

namespace App\Repository\Dominio;

use App\Model\Dominio\SaasCidade;
use App\Model\Dominio\SaasEstado;
use App\Model\Dominio\SaasEstadoCivil;
use App\Model\Dominio\SaasPais;
use App\Model\Dominio\UnimPessoaContatoTipo;
use Hyperf\Database\Model\Collection;

class DominioRepository implements DominioRepositoryInterface
{
    /**
     * @return Collection<int, SaasPais>
     */
    public function paises(): Collection
    {
        $query = SaasPais::query();
        $query->select(['cd_pais', 'ds_pais', 'ds_nacionalidade']);
        $query->orderBy('ds_pais');

        return $query->get();
    }

    /**
     * @return Collection<int, SaasEstado>
     */
    public function estados(?int $cdPais = null): Collection
    {
        $query = SaasEstado::query();
        $query->select(['cd_estado', 'cd_pais', 'ds_estado', 'ds_uf']);

        if ($cdPais !== null) {
            $query->where('cd_pais', $cdPais);
        }

        $query->orderBy('ds_estado');

        return $query->get();
    }

    /**
     * @return Collection<int, SaasCidade>
     */
    public function cidades(int $cdEstado, ?string $q = null): Collection
    {
        $query = SaasCidade::query();
        $query->select(['cd_cidade', 'cd_estado', 'ds_cidade']);
        $query->where('cd_estado', $cdEstado);

        if ($q !== null && $q !== '') {
            $query->where('ds_cidade', 'like', '%' . $q . '%');
        }

        $query->orderBy('ds_cidade');

        return $query->get();
    }

    /**
     * @return Collection<int, SaasEstadoCivil>
     */
    public function estadosCivis(): Collection
    {
        $query = SaasEstadoCivil::query();
        $query->select(['cd_estado_civil', 'ds_estado_civil']);
        $query->orderBy('cd_estado_civil');

        return $query->get();
    }

    /**
     * @return Collection<int, UnimPessoaContatoTipo>
     */
    public function tiposDeContato(): Collection
    {
        $query = UnimPessoaContatoTipo::query();
        $query->select(['cd_tipo', 'ds_descricao', 'ds_chave']);
        $query->orderBy('cd_tipo');

        return $query->get();
    }
}
