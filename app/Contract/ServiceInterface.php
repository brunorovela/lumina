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

namespace App\Contract;

use App\Model\Model;

/**
 * Contrato para serviços de domínio (SOLID - Interface Segregation).
 */
interface ServiceInterface
{
    public function listagem(array $filtros = []): array;

    public function buscarPorId(int $id): ?Model;

    public function criar(array $dados): Model;

    public function atualizar(int $id, array $dados): bool;

    public function remover(int $id): bool;
}
