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

use App\Enum\Privilegio;
use App\Enum\Recurso;
use App\Repository\Acl\AclRepository;
use Hyperf\Redis\Redis;

class AclService
{
    private const TTL_CACHE = 86400;

    public function __construct(private AclRepository $aclRepository, private Redis $redis)
    {
    }

    /**
     * Recurso/privilégio são enums (e não string livre) de propósito: as chaves aceitas
     * são exatamente as de ulms_recurso.ds_chave / ulms_privilegio.ds_chave. Antes disso
     * o projeto passava strings inventadas ('pessoa', 'listar'), que não existem no banco
     * e faziam toda checagem negar em silêncio.
     *
     * @param int[] $cdPerfis
     */
    public function isAllowed(array $cdPerfis, Recurso $recurso, Privilegio $privilegio): bool
    {
        foreach ($cdPerfis as $cdPerfil) {
            $permissoes = $this->permissoesDoPerfil((int) $cdPerfil);

            if (in_array($privilegio->value, $permissoes[$recurso->value] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    public function invalidar(int $cdPerfil): void
    {
        $this->redis->del($this->chave($cdPerfil));
    }

    /**
     * @return array<string, string[]> ds_chave do recurso => lista de ds_chave de privilégio
     */
    private function permissoesDoPerfil(int $cdPerfil): array
    {
        $cacheado = $this->redis->get($this->chave($cdPerfil));

        if (is_string($cacheado)) {
            $decodificado = json_decode($cacheado, true);

            // Cache corrompido cai para o banco em vez de virar null e explodir no in_array().
            if (is_array($decodificado)) {
                return self::normalizar($decodificado);
            }
        }

        $permissoes = $this->aclRepository->buscarPermissoesPorPerfil($cdPerfil);

        $this->redis->setex($this->chave($cdPerfil), self::TTL_CACHE, json_encode($permissoes));

        return $permissoes;
    }

    private function chave(int $cdPerfil): string
    {
        return "acl:perfil:{$cdPerfil}";
    }

    /**
     * O cache é JSON solto no Redis: qualquer coisa pode estar lá (versão antiga do
     * formato, escrita manual em debug). Normaliza para recurso => lista de privilégios
     * em string, descartando o que não encaixa.
     *
     * @param array<mixed> $bruto
     *
     * @return array<string, string[]>
     */
    private static function normalizar(array $bruto): array
    {
        $permissoes = [];

        foreach ($bruto as $recurso => $privilegios) {
            if (! is_array($privilegios)) {
                continue;
            }

            $permissoes[(string) $recurso] = array_values(array_filter($privilegios, 'is_string'));
        }

        return $permissoes;
    }
}
