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

use App\Service\Acl\AclService;
use App\Support\ApiResponse;
use App\Support\IdentidadeContext;
use Hyperf\HttpServer\Router\Dispatched;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AclMiddleware implements MiddlewareInterface
{
    public function __construct(private AclService $aclService, private PsrResponseInterface $response)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        $dispatched = $request->getAttribute(Dispatched::class);
        $opcoesAcl = $dispatched->handler->options['acl'] ?? null;

        if ($opcoesAcl === null) {
            return $handler->handle($request);
        }

        $permitido = $this->aclService->isAllowed(
            IdentidadeContext::cdPerfis(),
            $opcoesAcl['recurso'],
            $opcoesAcl['privilegio']
        );

        if (! $permitido) {
            return ApiResponse::erroHttp($this->response, 403, 'Sem permissão para esta ação.');
        }

        return $handler->handle($request);
    }
}
