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

namespace App\Service\Auth;

use App\Repository\AclRepository;
use Hyperf\Redis\Redis;

class AclService
{
    private string $cachePrefix = 'lms:acl:profile:';

    public function __construct(
        protected Redis $redis,
        protected AclRepository $repository
    ) {
    }

    public function isAllowed(int $cdPerfil, string $resource, int $permission): bool
    {
        $key = $this->cachePrefix . $cdPerfil;

        $bitmask = $this->redis->hGet($key, $resource);

        if ($bitmask === false) {
            $this->reloadProfileCache($cdPerfil);
            $bitmask = $this->redis->hGet($key, $resource);
        }

        return ((int) $bitmask & $permission) === $permission;
    }

    public function reloadProfileCache(int $cdPerfil): void
    {
        $key = $this->cachePrefix . $cdPerfil;
        $permissions = $this->repository->getPermissionsByProfile($cdPerfil);

        if (! empty($permissions)) {
            $this->redis->hMSet($key, $permissions);
            $this->redis->expire($key, 3600);
        }
    }
}
