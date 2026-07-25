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

namespace App\Service\Auth;

use App\Exception\Auth\CredenciaisInvalidasException;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;

class AuthService
{
    private const TTL_SESSAO = 8 * 60 * 60;

    public function __construct(private Redis $redis)
    {
    }

    public function autenticar(int $cdCliente, string $dsLogin, string $dsSenha): string
    {
        $pessoa = Db::table('unim_pessoa')
            ->where('cd_cliente', $cdCliente)
            ->where('ds_login', $dsLogin)
            ->whereNull('dt_excluido')
            ->first();

        if ($pessoa === null) {
            throw new CredenciaisInvalidasException();
        }

        $senhaBate = $this->verificarSenha($dsSenha, $pessoa->ds_senha);

        if (! $senhaBate) {
            throw new CredenciaisInvalidasException();
        }

        $this->atualizarHashSeNecessario($pessoa->cd_pessoa, $dsSenha, $pessoa->ds_senha);

        $token = bin2hex(random_bytes(32));

        $this->redis->setex(
            $this->chaveSessao($token),
            self::TTL_SESSAO,
            json_encode([
                'cd_pessoa' => $pessoa->cd_pessoa,
                'cd_cliente' => $pessoa->cd_cliente,
                'cd_perfis' => $this->buscarPerfisDaPessoa($pessoa->cd_pessoa, $pessoa->cd_cliente),
            ])
        );

        return $token;
    }

    public function logout(string $token): void
    {
        $this->redis->del($this->chaveSessao($token));
    }

    public function identidadePorToken(string $token): ?array
    {
        $bruto = $this->redis->get($this->chaveSessao($token));

        return $bruto === false ? null : json_decode($bruto, true);
    }

    /**
     * Uma pessoa pode ter varios perfis simultaneos (confirmado com dado real: contas de
     * teste no banco de dev tem 5 perfis cada). O vinculo eh lgin_pessoa_perfil -> unim_coligada
     * (unim_coligada.cd_cliente escopa por cliente; unim_coligada.cd_pessoa NAO filtra aqui —
     * eh o "dono" da coligada, nao quem tem perfil nela).
     *
     * @return int[]
     */
    private function buscarPerfisDaPessoa(int $cdPessoa, int $cdCliente): array
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

    private function verificarSenha(string $senhaInformada, string $senhaBanco): bool
    {
        if (password_verify($senhaInformada, $senhaBanco)) {
            return true;
        }

        if (md5($senhaInformada) === $senhaBanco) {
            return true;
        }

        return $senhaInformada === $senhaBanco;
    }

    private function atualizarHashSeNecessario(int $cdPessoa, string $senhaInformada, string $senhaBanco): void
    {
        if (password_verify($senhaInformada, $senhaBanco)) {
            return;
        }

        Db::table('unim_pessoa')
            ->where('cd_pessoa', $cdPessoa)
            ->update(['ds_senha' => password_hash($senhaInformada, PASSWORD_BCRYPT)]);
    }

    private function chaveSessao(string $token): string
    {
        return "session:{$token}";
    }
}
