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

namespace App\Repository;

use Hyperf\DbConnection\Db;

class AclRepository
{
    /**
     * Busca todas as permissões de um perfil e transforma em Bitmask.
     */
    public function getPermissionsByProfile(int $cdPerfil): array
    {
        // Query baseada nas tabelas lgin_perfil, lgin_perfil_recurso_privilegio e ulms_recurso
        $data = Db::table('lgin_perfil_recurso_privilegio as prp')
            ->join('ulms_recurso_privilegio as rp', 'prp.cd_recurso_privilegio', '=', 'rp.cd_recurso_privilegio')
            ->join('ulms_recurso as r', 'rp.cd_recurso', '=', 'r.cd_recurso')
            ->join('ulms_privilegio as priv', 'rp.cd_privilegio', '=', 'priv.cd_privilegio')
            ->where('prp.cd_perfil', $cdPerfil)
            ->select('r.ds_chave as recurso', 'priv.ds_chave as privilegio')
            ->get();

        $aclMap = [];
        foreach ($data as $row) {
            // Converte o nome do privilégio em bit ou usa uma lógica de soma pré-definida
            $bit = $this->mapPrivilegeToBit($row->privilegio);
            if (! isset($aclMap[$row->recurso])) {
                $aclMap[$row->recurso] = 0;
            }
            $aclMap[$row->recurso] |= $bit;
        }

        return $aclMap;
    }

    private function mapPrivilegeToBit(string $priv): int
    {
        return match (strtolower($priv)) {
            'acessar' => 1,
            'inserir' => 2,
            'alterar' => 4,
            'remover' => 8,
            'especial' => 16,
            default => 0,
        };
    }
}
