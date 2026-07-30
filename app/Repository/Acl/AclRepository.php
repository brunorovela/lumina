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

namespace App\Repository\Acl;

use Hyperf\DbConnection\Db;

class AclRepository
{
    /**
     * @return array<string, string[]> ds_chave do recurso => lista de ds_chave de privilégio
     */
    public function buscarPermissoesPorPerfil(int $cdPerfil): array
    {
        $linhas = Db::table('lgin_perfil_recurso_privilegio as lprp')
            ->join('ulms_recurso_privilegio as urp', 'urp.cd_recurso_privilegio', '=', 'lprp.cd_recurso_privilegio')
            ->join('ulms_recurso as ur', 'ur.cd_recurso', '=', 'urp.cd_recurso')
            ->join('ulms_privilegio as up', 'up.cd_privilegio', '=', 'urp.cd_privilegio')
            ->where('lprp.cd_perfil', $cdPerfil)
            ->select('ur.ds_chave as recurso', 'up.ds_chave as privilegio')
            ->get();

        $permissoes = [];

        foreach ($linhas as $linha) {
            /** @var array{recurso: string, privilegio: string} $registro */
            $registro = (array) $linha;

            $permissoes[$registro['recurso']][] = $registro['privilegio'];
        }

        return $permissoes;
    }
}
