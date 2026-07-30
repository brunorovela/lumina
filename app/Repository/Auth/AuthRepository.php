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

use App\Support\Tipo;
use Hyperf\DbConnection\Db;

/**
 * Extraído de App\Service\Auth\AuthService (Finding 13, whole-branch review) — AuthService
 * falava SQL direto via Hyperf\DbConnection\Db em 3 tabelas, fora do padrão de Repository
 * que o resto do projeto (Pessoa) segue. A decisão original (Task 8) era aceita, mas como
 * este branch é o exemplo-padrão de migração, vale alinhar com o resto.
 */
class AuthRepository implements AuthRepositoryInterface
{
    /**
     * @return null|object{cd_pessoa: int, cd_cliente: int, ds_senha: string}
     */
    public function buscarPessoaAtivaPorLoginECliente(int $cdCliente, string $dsLogin): ?object
    {
        $linha = Db::table('unim_pessoa')
            ->where('cd_cliente', $cdCliente)
            ->where('ds_login', $dsLogin)
            ->whereNull('dt_excluido')
            ->first();

        if ($linha === null) {
            return null;
        }

        // A linha crua é stdClass sem tipo de propriedade, e o PDO pode devolver inteiro de
        // MySQL como string dependendo da emulação de prepared statement. Montar aqui a
        // forma que o Service consome (só estas três colunas) troca "acesso a propriedade
        // em object" por contrato verificável — e faz cd_pessoa ser int de verdade, que é
        // o que AuthRepositoryTest assume no assertSame.
        $dados = (array) $linha;

        return (object) [
            'cd_pessoa' => Tipo::inteiro($dados['cd_pessoa'] ?? null),
            'cd_cliente' => Tipo::inteiro($dados['cd_cliente'] ?? null),
            'ds_senha' => Tipo::texto($dados['ds_senha'] ?? null),
        ];
    }

    public function buscarPerfisDaPessoa(int $cdPessoa, int $cdCliente): array
    {
        return Db::table('lgin_pessoa_perfil as lpp')
            ->join('unim_coligada as uc', 'uc.cd_coligada', '=', 'lpp.cd_coligada')
            ->where('lpp.cd_pessoa', $cdPessoa)
            ->where('uc.cd_cliente', $cdCliente)
            ->whereNull('uc.dt_excluido')
            ->pluck('lpp.cd_perfil')
            ->map(fn (mixed $cdPerfil): int => Tipo::inteiro($cdPerfil))
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
