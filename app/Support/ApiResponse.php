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

class ApiResponse
{
    public static function sucesso(mixed $data, ?array $meta = null): array
    {
        $resposta = ['success' => true, 'data' => $data];

        if ($meta !== null) {
            $resposta['meta'] = $meta;
        }

        return $resposta;
    }

    public static function erro(string $message, ?array $errors = null): array
    {
        $resposta = ['success' => false, 'message' => $message];

        if ($errors !== null) {
            $resposta['errors'] = $errors;
        }

        return $resposta;
    }
}
