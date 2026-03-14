<?php

declare(strict_types=1);

namespace App\Contract;

use App\Model\Model;

/**
 * Contrato para repositórios (SOLID - Dependency Inversion).
 * Permite trocar implementação e testar com mocks.
 */
interface RepositoryInterface
{
    public function all(array $columns = ['*']): array;

    public function find(int $id, array $columns = ['*']): ?Model;

    public function create(array $data): Model;

    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    public function getModel(): Model;
}
