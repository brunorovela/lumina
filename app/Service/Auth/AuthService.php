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

        $cdPessoa = $pessoa->cd_pessoa;
        $cdClienteDaPessoa = $pessoa->cd_cliente;

        if ($mecanismo !== 'bcrypt') {
            $this->authRepository->atualizarSenha($cdPessoa, password_hash($dsSenha, PASSWORD_BCRYPT));
        }

        $token = bin2hex(random_bytes(32));

        $this->redis->setex(
            $this->chaveSessao($token),
            self::TTL_SESSAO,
            json_encode([
                'cd_pessoa' => $cdPessoa,
                'cd_cliente' => $cdClienteDaPessoa,
                'cd_perfis' => $this->authRepository->buscarPerfisDaPessoa($cdPessoa, $cdClienteDaPessoa),
            ], JSON_THROW_ON_ERROR)
        );

        return $token;
    }

    public function logout(string $token): void
    {
        $this->redis->del($this->chaveSessao($token));
    }

    /**
     * @return null|array<string, mixed>
     */
    public function identidadePorToken(string $token): ?array
    {
        $bruto = $this->redis->get($this->chaveSessao($token));

        // Redis::get() devolve false quando a chave não existe, mas o tipo declarado é
        // mixed — sem o is_string, json_decode() recebe mixed e o retorno também é mixed.
        if (! is_string($bruto)) {
            return null;
        }

        $identidade = json_decode($bruto, true);

        if (! is_array($identidade)) {
            return null;
        }

        $normalizada = [];

        foreach ($identidade as $chave => $valor) {
            $normalizada[(string) $chave] = $valor;
        }

        return $normalizada;
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
