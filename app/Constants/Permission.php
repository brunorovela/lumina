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

namespace App\Constants;

class Permission
{
    public const ACESSAR = 1;

    public const INSERIR = 2;

    public const ALTERAR = 4;

    public const REMOVER = 8;

    public const ESPECIAL = 16;
}
