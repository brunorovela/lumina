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
    /**
     * @param null|array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    public static function sucesso(mixed $data, ?array $meta = null): array
    {
        $resposta = ['success' => true, 'data' => $data];

        if ($meta !== null) {
            $resposta['meta'] = $meta;
        }

        return $resposta;
    }

    /**
     * Chaves de $errors vêm de MessageBag (Hyperf\Contract\MessageBag::getMessages()
     * declara só `array`), por isso array<mixed> e não array<string, mixed>: o conteúdo
     * apenas atravessa para o json_encode.
     *
     * @param null|array<mixed> $errors
     *
     * @return array<string, mixed>
     */
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
     *
     * @param null|array<mixed> $errors
     */
    public static function erroHttp(
        ResponseInterface $response,
        int $status,
        string $message,
        ?array $errors = null
    ): ResponseInterface {
        // JSON_THROW_ON_ERROR: sem ele json_encode() devolve false num payload inválido
        // (ex: UTF-8 malformado vindo do banco legado) e o SwooleStream recebia false onde
        // espera string — TypeError opaco no meio do handler de erro. Falhar explícito é
        // melhor que responder corpo vazio.
        $corpo = json_encode(self::erro($message, $errors), JSON_THROW_ON_ERROR);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new SwooleStream($corpo));
    }
}
