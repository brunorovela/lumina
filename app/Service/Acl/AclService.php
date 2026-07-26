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

namespace App\Service\Acl;

use App\Repository\Acl\AclRepository;
use Hyperf\Redis\Redis;

class AclService
{
    private const TTL_CACHE = 86400;

    public function __construct(private AclRepository $aclRepository, private Redis $redis)
    {
    }

    public function isAllowed(array $cdPerfis, string $recurso, string $privilegio): bool
    {
        foreach ($cdPerfis as $cdPerfil) {
            $permissoes = $this->permissoesDoPerfil($cdPerfil);

            if (in_array($privilegio, $permissoes[$recurso] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    public function invalidar(int $cdPerfil): void
    {
        $this->redis->del($this->chave($cdPerfil));
    }

    private function permissoesDoPerfil(int $cdPerfil): array
    {
        $cacheado = $this->redis->get($this->chave($cdPerfil));

        if ($cacheado !== false) {
            return json_decode($cacheado, true);
        }

        $permissoes = $this->aclRepository->buscarPermissoesPorPerfil($cdPerfil);

        $this->redis->setex($this->chave($cdPerfil), self::TTL_CACHE, json_encode($permissoes));

        return $permissoes;
    }

    private function chave(int $cdPerfil): string
    {
        return "acl:perfil:{$cdPerfil}";
    }
}
