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

namespace App\Support;

use Hyperf\Context\Context;

final class IdentidadeContext
{
    private const CHAVE = 'identidade.autenticada';

    public static function set(array $identidade): void
    {
        Context::set(self::CHAVE, $identidade);
    }

    public static function get(): ?array
    {
        return Context::get(self::CHAVE);
    }

    public static function cdCliente(): int
    {
        return (int) self::get()['cd_cliente'];
    }

    /**
     * @return int[]
     */
    public static function cdPerfis(): array
    {
        return array_map('intval', self::get()['cd_perfis'] ?? []);
    }

    public static function cdPessoa(): int
    {
        return (int) self::get()['cd_pessoa'];
    }
}
