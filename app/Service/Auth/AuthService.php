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
use App\Repository\Auth\AuthRepositoryInterface;
use Hyperf\Redis\Redis;

class AuthService
{
    private const TTL_SESSAO = 8 * 60 * 60;

    public function __construct(private Redis $redis, private AuthRepositoryInterface $authRepository)
    {
    }

    public function autenticar(int $cdCliente, string $dsLogin, string $dsSenha): string
    {
        $pessoa = $this->authRepository->buscarPessoaAtivaPorLoginECliente($cdCliente, $dsLogin);

        if ($pessoa === null) {
            throw new CredenciaisInvalidasException();
        }

        $mecanismo = $this->verificarSenha($dsSenha, $pessoa->ds_senha);

        if ($mecanismo === null) {
            throw new CredenciaisInvalidasException();
        }

        if ($mecanismo !== 'bcrypt') {
            $this->authRepository->atualizarSenha($pessoa->cd_pessoa, password_hash($dsSenha, PASSWORD_BCRYPT));
        }

        $token = bin2hex(random_bytes(32));

        $this->redis->setex(
            $this->chaveSessao($token),
            self::TTL_SESSAO,
            json_encode([
                'cd_pessoa' => $pessoa->cd_pessoa,
                'cd_cliente' => $pessoa->cd_cliente,
                'cd_perfis' => $this->authRepository->buscarPerfisDaPessoa($pessoa->cd_pessoa, $pessoa->cd_cliente),
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
     * Computa a cascata de verificacao uma unica vez e devolve qual mecanismo bateu, para
     * que autenticar() decida o upgrade sem re-chamar password_verify() (BCrypt eh
     * deliberadamente caro — recalcular no hot path de login dobraria o custo de CPU).
     *
     * @return null|'bcrypt'|'md5'|'texto_puro'
     */
    private function verificarSenha(string $senhaInformada, string $senhaBanco): ?string
    {
        if (password_verify($senhaInformada, $senhaBanco)) {
            return 'bcrypt';
        }

        if (md5($senhaInformada) === $senhaBanco) {
            return 'md5';
        }

        if ($senhaInformada === $senhaBanco) {
            return 'texto_puro';
        }

        return null;
    }

    private function chaveSessao(string $token): string
    {
        return "session:{$token}";
    }
}
