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
use Hyperf\Database\Model\Collection;

interface PessoaRepositoryInterface
{
    /**
     * @param array<string, mixed> $dadosPessoa
     * @param null|array<string, mixed> $dadosFisica
     * @param null|array<string, mixed> $dadosJuridica
     */
    public function criar(array $dadosPessoa, ?array $dadosFisica, ?array $dadosJuridica): UnimPessoa;

    /**
     * @param array<string, mixed> $dadosPessoa
     * @param null|array<string, mixed> $dadosFisica
     * @param null|array<string, mixed> $dadosJuridica
     * @param bool $ehIsentoDeFisicaJuridica login admin/administrador nunca tem
     *                                       fisica/juridica de propósito (regra de negócio, não "tipo que mudou") — quando
     *                                       true, o Repository nunca apaga fisica/juridica desta pessoa, independente do que
     *                                       vier em $dadosFisica/$dadosJuridica
     */
    public function atualizar(
        int $cdPessoa,
        int $cdCliente,
        array $dadosPessoa,
        ?array $dadosFisica,
        ?array $dadosJuridica,
        bool $ehIsentoDeFisicaJuridica = false
    ): UnimPessoa;

    public function buscarPorId(int $cdPessoa, int $cdCliente): ?UnimPessoa;

    /**
     * @param array<string, mixed> $filtros
     *
     * @return array{itens: Collection<int, UnimPessoa>, total: int}
     */
    public function listar(int $cdCliente, array $filtros, int $page, int $perPage): array;

    public function excluir(int $cdPessoa, int $cdCliente): bool;

    public function loginExiste(int $cdCliente, string $dsLogin, ?int $ignorarCdPessoa = null): bool;
}
