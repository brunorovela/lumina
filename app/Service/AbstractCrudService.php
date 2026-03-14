<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\RepositoryInterface;
use App\Contract\ServiceInterface;
use App\Model\Model;
use Hyperf\Di\Annotation\Inject;
use Psr\SimpleCache\CacheInterface;

/**
 * Serviço CRUD base com cache (SOLID - Open/Closed).
 * Cache em listagem e busca por ID; invalidação em criar/atualizar/remover.
 */
abstract class AbstractCrudService implements ServiceInterface
{
    protected string $cachePrefix = 'crud';

    protected int $cacheTtlSeconds = 300;

    public function __construct(
        protected RepositoryInterface $repository,
        #[Inject]
        protected CacheInterface $cache
    ) {
    }

    /**
     * Chave única do recurso para cache (ex: pessoa, pessoa_fisica).
     */
    abstract protected function getResourceKey(): string;

    protected function cacheKey(string $suffix): string
    {
        return $this->cachePrefix . ':' . $this->getResourceKey() . ':' . $suffix;
    }

    public function listagem(array $filtros = []): array
    {
        $key = $this->cacheKey('list');
        if (empty($filtros)) {
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                return $cached;
            }
        }
        $items = $this->repository->all();
        $result = array_map(fn ($model) => $model->toArray(), $items);
        if (empty($filtros)) {
            $this->cache->set($key, $result, $this->cacheTtlSeconds);
        }
        return $result;
    }

    public function buscarPorId(int $id): ?Model
    {
        $key = $this->cacheKey('id_' . $id);
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            $model = $this->repository->getModel()->newInstance();
            $model->setRawAttributes($cached);
            $model->exists = true;
            return $model;
        }
        $model = $this->repository->find($id);
        if ($model !== null) {
            $this->cache->set($key, $model->getAttributes(), $this->cacheTtlSeconds);
        }
        return $model;
    }

    public function criar(array $dados): Model
    {
        $model = $this->repository->getModel();
        $fillable = array_flip($model->getFillable());
        $dados = array_intersect_key($dados, $fillable);
        $dados = array_filter($dados, fn ($v) => $v !== null);
        $model = $this->repository->create($dados);
        $this->invalidarCacheListagem();
        return $model;
    }

    public function atualizar(int $id, array $dados): bool
    {
        $ok = $this->repository->update($id, $dados);
        if ($ok) {
            $this->invalidarCacheItem($id);
            $this->invalidarCacheListagem();
        }
        return $ok;
    }

    public function remover(int $id): bool
    {
        $ok = $this->repository->delete($id);
        if ($ok) {
            $this->invalidarCacheItem($id);
            $this->invalidarCacheListagem();
        }
        return $ok;
    }

    protected function invalidarCacheItem(int $id): void
    {
        $this->cache->delete($this->cacheKey('id_' . $id));
    }

    protected function invalidarCacheListagem(): void
    {
        $this->cache->delete($this->cacheKey('list'));
    }
}
