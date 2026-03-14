<?php

declare(strict_types=1);

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
