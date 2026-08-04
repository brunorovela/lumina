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

/**
 * Leitura dos catálogos que alimentam o cadastro de pessoa. Todos globais: nenhuma das
 * tabelas tem cd_cliente, então não existe escopo de tenant aqui — diferente de tudo em
 * App\Repository\Pessoa.
 */
interface DominioRepositoryInterface
{
    /**
     * @return Collection<int, SaasPais>
     */
    public function paises(): Collection;

    /**
     * @param null|int $cdPais null devolve todos os estados
     *
     * @return Collection<int, SaasEstado>
     */
    public function estados(?int $cdPais = null): Collection;

    /**
     * @param int $cdEstado obrigatório: sem ele a consulta varreria 4928 linhas
     * @param null|string $q filtra ds_cidade por LIKE %q%
     *
     * @return Collection<int, SaasCidade>
     */
    public function cidades(int $cdEstado, ?string $q = null): Collection;

    /**
     * @return Collection<int, SaasEstadoCivil>
     */
    public function estadosCivis(): Collection;

    /**
     * @return Collection<int, UnimPessoaContatoTipo>
     */
    public function tiposDeContato(): Collection;
}
