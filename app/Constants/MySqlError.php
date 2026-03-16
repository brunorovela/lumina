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

enum MySqlError: int
{
    case DUPLICATE_ENTRY = 1062;
    case ROW_IS_REFERENCED = 1451; // Não pode deletar/alterar (FK no filho)
    case NO_REFERENCED_ROW = 1452; // Não pode inserir/alterar (FK pai inexiste)

    /**
     * Retorna uma mensagem amigável baseada no erro.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::DUPLICATE_ENTRY => 'Registro duplicado',
            self::ROW_IS_REFERENCED, self::NO_REFERENCED_ROW => 'Violação de relacionamento',
        };
    }
}
