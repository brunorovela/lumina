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

use Hyperf\DbConnection\Db;

/**
 * Extraído de App\Service\Auth\AuthService (Finding 13, whole-branch review) — AuthService
 * falava SQL direto via Hyperf\DbConnection\Db em 3 tabelas, fora do padrão de Repository
 * que o resto do projeto (Pessoa) segue. A decisão original (Task 8) era aceita, mas como
 * este branch é o exemplo-padrão de migração, vale alinhar com o resto.
 */
class AuthRepository implements AuthRepositoryInterface
{
    public function buscarPessoaAtivaPorLoginECliente(int $cdCliente, string $dsLogin): ?object
    {
        return Db::table('unim_pessoa')
            ->where('cd_cliente', $cdCliente)
            ->where('ds_login', $dsLogin)
            ->whereNull('dt_excluido')
            ->first();
    }

    public function buscarPerfisDaPessoa(int $cdPessoa, int $cdCliente): array
    {
        return Db::table('lgin_pessoa_perfil as lpp')
            ->join('unim_coligada as uc', 'uc.cd_coligada', '=', 'lpp.cd_coligada')
            ->where('lpp.cd_pessoa', $cdPessoa)
            ->where('uc.cd_cliente', $cdCliente)
            ->whereNull('uc.dt_excluido')
            ->pluck('lpp.cd_perfil')
            ->map(fn ($cdPerfil) => (int) $cdPerfil)
            ->values()
            ->all();
    }

    public function atualizarSenha(int $cdPessoa, string $hashSenha): void
    {
        Db::table('unim_pessoa')
            ->where('cd_pessoa', $cdPessoa)
            ->update(['ds_senha' => $hashSenha]);
    }
}
