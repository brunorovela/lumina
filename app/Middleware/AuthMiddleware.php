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

namespace App\Middleware;

use App\Service\Auth\AuthService;
use App\Support\ApiResponse;
use App\Support\IdentidadeContext;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthService $authService, private PsrResponseInterface $response)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        $token = str_replace('Bearer ', '', $request->getHeaderLine('Authorization'));

        try {
            $identidade = $token === '' ? null : $this->authService->identidadePorToken($token);
        } catch (Throwable $e) {
            $identidade = null;
        }

        if ($identidade === null) {
            return ApiResponse::erroHttp($this->response, 401, 'Não autenticado.');
        }

        IdentidadeContext::set($identidade);

        return $handler->handle($request);
    }
}
