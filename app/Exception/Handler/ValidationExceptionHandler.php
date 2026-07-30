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

namespace App\Exception\Handler;

use App\Support\ApiResponse;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class ValidationExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        $this->stopPropagation();

        // instanceof em vez de anotação: o `/** @var */` que estava aqui foi degradado
        // para `/* @var */` pelo php-cs-fixer, forma que o phpstan ignora — então o tipo
        // nunca era estreitado de verdade. isValid() já garante o tipo; o guard só torna
        // isso verificável.
        if (! $throwable instanceof ValidationException) {
            return ApiResponse::erroHttp($response, 422, 'Dados inválidos.');
        }

        // ValidationException::errors() é declarado `: array` e devolve o mesmo conteúdo de
        // validator->errors()->toArray(); a diferença é que toArray() não existe no
        // contrato Hyperf\Contract\MessageBag, só na implementação concreta.
        return ApiResponse::erroHttp($response, 422, 'Dados inválidos.', $throwable->errors());
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof ValidationException;
    }
}
