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

use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;

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

    /**
     * Monta a resposta HTTP completa (status + Content-Type + corpo) para um erro no
     * envelope padrão da API. Ponto único usado por middlewares e exception handlers —
     * antes de existir, cada um montava `->withBody(new SwooleStream(json_encode(...)))`
     * na mão e nenhum setava `Content-Type`, então toda resposta de erro saía como
     * `text/html` (whole-branch review, Finding 2).
     */
    public static function erroHttp(
        ResponseInterface $response,
        int $status,
        string $message,
        ?array $errors = null
    ): ResponseInterface {
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new SwooleStream(json_encode(self::erro($message, $errors))));
    }
}
