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
use App\Support\Campos\SelecaoDeCampos;
use Hyperf\Database\Model\Collection;

/**
 * Este repositório fala com unim_pessoa e só. unim_pessoa_fisica e unim_pessoa_juridica
 * saíram daqui: cada recurso responde pela própria tabela.
 */
interface PessoaRepositoryInterface
{
    /**
     * @param array<string, mixed> $dadosPessoa
     */
    public function criar(array $dadosPessoa): UnimPessoa;

    /**
     * @param array<string, mixed> $dadosPessoa
     */
    public function atualizar(int $cdPessoa, int $cdCliente, array $dadosPessoa): UnimPessoa;

    /**
     * Traz SEMPRE todas as colunas do mapa (MapaDeCamposPessoa::colunas()), sem recorte por
     * fields: o detalhe é cacheado por entidade e o recorte roda depois, sobre o cache.
     */
    public function buscarPorId(int $cdPessoa, int $cdCliente): ?UnimPessoa;

    /**
     * @param array<string, mixed> $filtros
     * @param null|SelecaoDeCampos $selecao null significa contrato completo
     *
     * @return array{itens: Collection<int, UnimPessoa>, total: int}
     */
    public function listar(int $cdCliente, array $filtros, int $page, int $perPage, ?SelecaoDeCampos $selecao = null): array;

    public function excluir(int $cdPessoa, int $cdCliente): bool;

    public function loginExiste(int $cdCliente, string $dsLogin, ?int $ignorarCdPessoa = null): bool;
}
