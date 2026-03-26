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

use App\Service\Auth\AclService;
use App\Support\AclRouteOptions;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AclMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected AclService $acl,
        protected HttpResponse $response
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        [$resource, $level] = AclRouteOptions::resolve($request);

        if ($resource === null || $resource === '') {
            return $handler->handle($request);
        }

        $cdPerfil = (int) $request->getAttribute('user_profile_id', 0);
        if ($cdPerfil <= 0) {
            return $this->response->json(['error' => 'Não autenticado'])->withStatus(401);
        }

        if (! $this->acl->isAllowed($cdPerfil, $resource, $level)) {
            return $this->response->json(['error' => 'Acesso negado para este recurso'])->withStatus(403);
        }

        return $handler->handle($request);
    }
}
