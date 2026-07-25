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

namespace App\Service\Pessoa;

use App\Exception\Pessoa\LoginJaExisteException;
use App\Exception\Pessoa\PessoaNaoEncontradaException;
use App\Model\Pessoa\UnimPessoa;
use App\Repository\Pessoa\PessoaRepositoryInterface;

class PessoaService
{
    private const LOGINS_ISENTOS_DE_FISICA_JURIDICA = ['admin', 'administrador'];

    public function __construct(private PessoaRepositoryInterface $pessoaRepository)
    {
    }

    public function criar(int $cdCliente, array $dados): UnimPessoa
    {
        if ($this->pessoaRepository->loginExiste($cdCliente, $dados['ds_login'])) {
            throw new LoginJaExisteException();
        }

        [$dadosPessoa, $dadosFisica, $dadosJuridica] = $this->separarDados($cdCliente, $dados);

        return $this->pessoaRepository->criar($dadosPessoa, $dadosFisica, $dadosJuridica);
    }

    public function atualizar(int $cdPessoa, int $cdCliente, array $dados): UnimPessoa
    {
        $this->garantirLoginDisponivel($cdPessoa, $cdCliente, $dados['ds_login']);

        [$dadosPessoa, $dadosFisica, $dadosJuridica] = $this->separarDados($cdCliente, $dados);

        return $this->pessoaRepository->atualizar($cdPessoa, $cdCliente, $dadosPessoa, $dadosFisica, $dadosJuridica);
    }

    public function atualizarParcial(int $cdPessoa, int $cdCliente, array $dados): UnimPessoa
    {
        if (isset($dados['ds_login'])) {
            $this->garantirLoginDisponivel($cdPessoa, $cdCliente, $dados['ds_login']);
        }

        $dadosPessoa = array_intersect_key($dados, array_flip(['ds_nome', 'ds_login', 'ds_senha']));

        if (isset($dadosPessoa['ds_senha'])) {
            $dadosPessoa['ds_senha'] = password_hash($dadosPessoa['ds_senha'], PASSWORD_BCRYPT);
        }

        $dadosFisica = array_intersect_key($dados, array_flip(['ds_nome_oficial', 'ds_cpf']));
        $dadosJuridica = array_intersect_key($dados, array_flip(['ds_cnpj', 'ds_nome_fantasia']));

        return $this->pessoaRepository->atualizar(
            $cdPessoa,
            $cdCliente,
            $dadosPessoa,
            $dadosFisica ?: null,
            $dadosJuridica ?: null
        );
    }

    public function buscar(int $cdPessoa, int $cdCliente): UnimPessoa
    {
        $pessoa = $this->pessoaRepository->buscarPorId($cdPessoa, $cdCliente);

        if ($pessoa === null) {
            throw new PessoaNaoEncontradaException();
        }

        return $pessoa;
    }

    public function listar(int $cdCliente, array $filtros, int $page, int $perPage): array
    {
        $perPage = min($perPage, 100);

        return $this->pessoaRepository->listar($cdCliente, $filtros, $page, $perPage);
    }

    public function excluir(int $cdPessoa, int $cdCliente): void
    {
        if (! $this->pessoaRepository->excluir($cdPessoa, $cdCliente)) {
            throw new PessoaNaoEncontradaException();
        }
    }

    private function garantirLoginDisponivel(int $cdPessoa, int $cdCliente, string $dsLogin): void
    {
        if ($this->pessoaRepository->loginExiste($cdCliente, $dsLogin, ignorarCdPessoa: $cdPessoa)) {
            throw new LoginJaExisteException();
        }
    }

    private function separarDados(int $cdCliente, array $dados): array
    {
        $dadosPessoa = [
            'cd_cliente' => $cdCliente,
            'ds_nome' => $dados['ds_nome'],
            'ds_login' => $dados['ds_login'],
            'sn_pessoa_juridica' => $dados['sn_pessoa_juridica'],
        ];

        if (isset($dados['ds_senha'])) {
            $dadosPessoa['ds_senha'] = password_hash($dados['ds_senha'], PASSWORD_BCRYPT);
        }

        $ehIsentoDeFisicaJuridica = in_array(strtolower($dados['ds_login']), self::LOGINS_ISENTOS_DE_FISICA_JURIDICA, true);

        if ($ehIsentoDeFisicaJuridica) {
            return [$dadosPessoa, null, null];
        }

        if ($dados['sn_pessoa_juridica']) {
            $dadosJuridica = [
                'ds_cnpj' => $dados['ds_cnpj'],
                'ds_nome_fantasia' => $dados['ds_nome_fantasia'],
            ];

            return [$dadosPessoa, null, $dadosJuridica];
        }

        $dadosFisica = ['ds_nome_oficial' => $dados['ds_nome_oficial']];

        if (isset($dados['ds_cpf'])) {
            $dadosFisica['ds_cpf'] = $dados['ds_cpf'];
        }

        return [$dadosPessoa, $dadosFisica, null];
    }
}
