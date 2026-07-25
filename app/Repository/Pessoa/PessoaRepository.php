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

namespace App\Repository\Pessoa;

use App\Model\Pessoa\UnimPessoa;
use App\Model\Pessoa\UnimPessoaFisica;
use App\Model\Pessoa\UnimPessoaJuridica;
use Hyperf\DbConnection\Db;

class PessoaRepository implements PessoaRepositoryInterface
{
    public function criar(array $dadosPessoa, ?array $dadosFisica, ?array $dadosJuridica): UnimPessoa
    {
        return Db::transaction(function () use ($dadosPessoa, $dadosFisica, $dadosJuridica) {
            $pessoa = UnimPessoa::create($dadosPessoa);

            if ($dadosFisica !== null) {
                UnimPessoaFisica::create(['cd_pessoa' => $pessoa->cd_pessoa, ...$dadosFisica]);
            }

            if ($dadosJuridica !== null) {
                UnimPessoaJuridica::create(['cd_pessoa' => $pessoa->cd_pessoa, ...$dadosJuridica]);
            }

            return $pessoa->fresh(['fisica', 'juridica']);
        });
    }

    public function atualizar(
        int $cdPessoa,
        int $cdCliente,
        array $dadosPessoa,
        ?array $dadosFisica,
        ?array $dadosJuridica
    ): UnimPessoa {
        return Db::transaction(function () use ($cdPessoa, $cdCliente, $dadosPessoa, $dadosFisica, $dadosJuridica) {
            $pessoa = UnimPessoa::where('cd_pessoa', $cdPessoa)->where('cd_cliente', $cdCliente)->firstOrFail();
            $pessoa->update($dadosPessoa);

            if ($dadosFisica !== null) {
                UnimPessoaFisica::updateOrCreate(['cd_pessoa' => $cdPessoa], $dadosFisica);
            }

            if ($dadosJuridica !== null) {
                UnimPessoaJuridica::updateOrCreate(['cd_pessoa' => $cdPessoa], $dadosJuridica);
            }

            return $pessoa->fresh(['fisica', 'juridica']);
        });
    }

    public function buscarPorId(int $cdPessoa, int $cdCliente): ?UnimPessoa
    {
        return UnimPessoa::with(['fisica', 'juridica'])
            ->where('cd_pessoa', $cdPessoa)
            ->where('cd_cliente', $cdCliente)
            ->first();
    }

    public function listar(int $cdCliente, array $filtros, int $page, int $perPage): array
    {
        $query = UnimPessoa::with(['fisica', 'juridica'])->where('cd_cliente', $cdCliente);

        if (! empty($filtros['nome'])) {
            $query->where('ds_nome', 'like', '%' . $filtros['nome'] . '%');
        }

        if (! empty($filtros['tipo_pessoa'])) {
            $query->where('sn_pessoa_juridica', $filtros['tipo_pessoa'] === 'juridica');
        }

        $total = (clone $query)->count();
        $itens = $query->forPage($page, $perPage)->get();

        return ['itens' => $itens, 'total' => $total];
    }

    public function excluir(int $cdPessoa, int $cdCliente): bool
    {
        $pessoa = UnimPessoa::where('cd_pessoa', $cdPessoa)->where('cd_cliente', $cdCliente)->first();

        if ($pessoa === null) {
            return false;
        }

        return (bool) $pessoa->delete();
    }

    public function loginExiste(int $cdCliente, string $dsLogin, ?int $ignorarCdPessoa = null): bool
    {
        $query = UnimPessoa::where('cd_cliente', $cdCliente)->where('ds_login', $dsLogin);

        if ($ignorarCdPessoa !== null) {
            $query->where('cd_pessoa', '!=', $ignorarCdPessoa);
        }

        return $query->exists();
    }
}
