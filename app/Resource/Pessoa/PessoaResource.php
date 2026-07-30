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

namespace App\Resource\Pessoa;

use App\Model\Pessoa\UnimPessoa;

class PessoaResource
{
    /**
     * @return array<string, mixed>
     */
    public static function um(UnimPessoa $pessoa): array
    {
        return [
            'cd_pessoa' => $pessoa->cd_pessoa,
            'cd_cliente' => $pessoa->cd_cliente,
            'ds_nome' => $pessoa->ds_nome,
            'ds_login' => $pessoa->ds_login,
            'sn_pessoa_juridica' => $pessoa->sn_pessoa_juridica,
            'fisica' => $pessoa->fisica ? [
                'ds_nome_oficial' => $pessoa->fisica->ds_nome_oficial,
                'ds_cpf' => $pessoa->fisica->ds_cpf,
            ] : null,
            'juridica' => $pessoa->juridica ? [
                'ds_cnpj' => $pessoa->juridica->ds_cnpj,
                'ds_nome_fantasia' => $pessoa->juridica->ds_nome_fantasia,
            ] : null,
        ];
    }

    /**
     * @param iterable<UnimPessoa> $pessoas
     *
     * @return array<int, array<string, mixed>>
     */
    public static function muitos(iterable $pessoas): array
    {
        $itens = [];

        foreach ($pessoas as $pessoa) {
            $itens[] = self::um($pessoa);
        }

        return $itens;
    }
}
