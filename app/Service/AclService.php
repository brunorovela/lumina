<?php
declare(strict_types=1);

namespace App\Service\Auth;

use Hyperf\Redis\Redis;
use App\Repository\AclRepository;

class AclService
{
    private string $cachePrefix = 'lms:acl:profile:';

    public function __construct(
        protected Redis $redis,
        protected AclRepository $repository
    ) {}

    public function isAllowed(int $cdPerfil, string $resource, int $permission): bool
    {
        $key = $this->cachePrefix . $cdPerfil;
        
        // HGET busca apenas o recurso específico do perfil no Redis
        $bitmask = $this->redis->hGet($key, $resource);

        if ($bitmask === false) {
            $this->reloadProfileCache($cdPerfil);
            $bitmask = $this->redis->hGet($key, $resource);
        }

        return ((int)$bitmask & $permission) === $permission;
    }

    public function reloadProfileCache(int $cdPerfil): void
    {
        $key = $this->cachePrefix . $cdPerfil;
        $permissions = $this->repository->getPermissionsByProfile($cdPerfil);
        
        if (!empty($permissions)) {
            $this->redis->hMSet($key, $permissions);
            $this->redis->expire($key, 3600); // 1 hora de cache
        }
    }
}