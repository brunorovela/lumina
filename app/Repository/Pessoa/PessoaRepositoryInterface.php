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

interface PessoaRepositoryInterface
{
    public function criar(array $dadosPessoa, ?array $dadosFisica, ?array $dadosJuridica): UnimPessoa;

    public function buscarPorId(int $cdPessoa, int $cdCliente): ?UnimPessoa;

    public function loginExiste(int $cdCliente, string $dsLogin, ?int $ignorarCdPessoa = null): bool;
}
