<?php

declare(strict_types=1);

namespace App\Repository;

use App\Contract\RepositoryInterface;
use App\Model\Model;
use Hyperf\Database\Model\Builder;

/**
 * Repositório base reutilizável (SOLID - Single Responsibility).
 * Centraliza acesso a dados e pode ser estendido por entidade.
 */
abstract class AbstractRepository implements RepositoryInterface
{
    public function __construct(
        protected Model $model
    ) {
    }

    public function all(array $columns = ['*']): array
    {
        return $this->query()->get($columns)->all();
    }

    public function find(int $id, array $columns = ['*']): ?Model
    {
        return $this->query()->find($id, $columns);
    }

    public function create(array $data): Model
    {
        return $this->model->newQuery()->create($data);
    }

    public function update(int $id, array $data): bool
    {
        $model = $this->find($id);
        if ($model === null) {
            return false;
        }
        return $model->update($data);
    }

    public function delete(int $id): bool
    {
        $model = $this->find($id);
        if ($model === null) {
            return false;
        }
        return (bool) $model->delete();
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Query base para permitir override em repositórios concretos.
     */
    protected function query(): Builder
    {
        return $this->model->newQuery();
    }
}
