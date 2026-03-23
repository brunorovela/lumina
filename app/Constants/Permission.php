<?php

declare(strict_types=1);

namespace App\Constants;

class Permission
{
    public const ACESSAR = 1;
    public const INSERIR = 2;
    public const ALTERAR = 4;
    public const REMOVER = 8;
    public const ESPECIAL = 16;
}