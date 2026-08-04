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

namespace App\Resource\Dominio;

use App\Model\Dominio\SaasEstadoCivil;
use App\Model\Dominio\SaasPais;
use App\Model\Dominio\UnimPessoaContatoTipo;

/**
 * Recorte da saída dos catálogos.
 *
 * Campo por campo de propósito: toArray() do model devolveria dt_base (controle do LMS) e
 * exporia qualquer coluna nova que aparecesse na tabela sem ninguém ter decidido expor.
 */
class DominioResource
{
    /**
     * @param iterable<SaasPais> $paises
     *
     * @return array<int, array<string, mixed>>
     */
    public static function paises(iterable $paises): array
    {
        $itens = [];

        foreach ($paises as $pais) {
            $itens[] = [
                'cd_pais' => $pais->cd_pais,
                'ds_pais' => $pais->ds_pais,
                'ds_nacionalidade' => $pais->ds_nacionalidade,
            ];
        }

        return $itens;
    }

    /**
     * @param iterable<SaasEstadoCivil> $estadosCivis
     *
     * @return array<int, array<string, mixed>>
     */
    public static function estadosCivis(iterable $estadosCivis): array
    {
        $itens = [];

        foreach ($estadosCivis as $estadoCivil) {
            $itens[] = [
                'cd_estado_civil' => $estadoCivil->cd_estado_civil,
                'ds_estado_civil' => $estadoCivil->ds_estado_civil,
            ];
        }

        return $itens;
    }

    /**
     * @param iterable<UnimPessoaContatoTipo> $tipos
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tiposDeContato(iterable $tipos): array
    {
        $itens = [];

        foreach ($tipos as $tipo) {
            $itens[] = [
                'cd_tipo' => $tipo->cd_tipo,
                'ds_descricao' => $tipo->ds_descricao,
                'ds_chave' => $tipo->ds_chave,
            ];
        }

        return $itens;
    }
}
