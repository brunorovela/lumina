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
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthService $authService, private PsrResponseInterface $response)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        $token = str_replace('Bearer ', '', $request->getHeaderLine('Authorization'));

        $identidade = $token === '' ? null : $this->authService->identidadePorToken($token);

        if ($identidade === null) {
            return $this->response
                ->withStatus(401)
                ->withBody(new SwooleStream(json_encode(ApiResponse::erro('Não autenticado.'))));
        }

        IdentidadeContext::set($identidade);

        return $handler->handle($request);
    }
}
