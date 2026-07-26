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

namespace App\Repository\Auth;

interface AuthRepositoryInterface
{
    /**
     * Busca a pessoa (não excluída) pelo par cd_cliente/ds_login. Devolve o registro cru
     * de unim_pessoa (stdClass) — quem decide o que fazer com ele (verificar senha, etc.)
     * é o Service, não o Repository.
     */
    public function buscarPessoaAtivaPorLoginECliente(int $cdCliente, string $dsLogin): ?object;

    /**
     * Uma pessoa pode ter vários perfis simultâneos. O vínculo é
     * lgin_pessoa_perfil -> unim_coligada (unim_coligada.cd_cliente escopa por cliente;
     * unim_coligada.cd_pessoa NÃO filtra aqui — é o "dono" da coligada, não quem tem
     * perfil nela).
     *
     * @return int[]
     */
    public function buscarPerfisDaPessoa(int $cdPessoa, int $cdCliente): array;

    public function atualizarSenha(int $cdPessoa, string $hashSenha): void;
}
