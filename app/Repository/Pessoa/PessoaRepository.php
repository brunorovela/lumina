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

    public function buscarPorId(int $cdPessoa, int $cdCliente): ?UnimPessoa
    {
        return UnimPessoa::with(['fisica', 'juridica'])
            ->where('cd_pessoa', $cdPessoa)
            ->where('cd_cliente', $cdCliente)
            ->first();
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
