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
use Hyperf\HttpMessage\Exception\HttpException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Substitui Hyperf\HttpServer\Exception\Handler\HttpExceptionHandler (nativo) na pilha de
 * config/autoload/exceptions.php. O handler nativo devolve o corpo cru (ex: "Not Found",
 * "Method Not Allowed") sem o envelope ApiResponse e sem Content-Type: application/json —
 * inconsistente com o resto da API (whole-branch review, Finding 3). Cobre 404 (rota
 * inexistente) e 405 (verbo HTTP errado numa rota que existe), entre outras
 * Hyperf\HttpMessage\Exception\HttpException lançadas pelo próprio framework antes de
 * qualquer controller rodar.
 */
class RouteExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response): ResponseInterface
    {
        $this->stopPropagation();

        // instanceof em vez de anotação: o `/** @var */` original virou `/* @var */` na mão
        // do php-cs-fixer, e nessa forma o phpstan não estreita o tipo — getName() e
        // getStatusCode() ficavam "método inexistente em Throwable".
        if (! $throwable instanceof HttpException) {
            return ApiResponse::erroHttp($response, 500, 'Erro interno.');
        }

        // NotFoundHttpException/MethodNotAllowedHttpException nascem sem mensagem (ex:
        // handleNotFound() em Hyperf\HttpServer\CoreMiddleware faz `throw new
        // NotFoundHttpException()`); getName() cai pro reason phrase HTTP do status
        // ("Not Found", "Method Not Allowed") em vez de devolver "message": "".
        $mensagem = $throwable->getMessage() !== '' ? $throwable->getMessage() : $throwable->getName();

        return ApiResponse::erroHttp($response, $throwable->getStatusCode(), $mensagem);
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof HttpException;
    }
}
