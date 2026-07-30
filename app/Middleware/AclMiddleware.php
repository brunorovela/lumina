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

use App\Enum\Privilegio;
use App\Enum\Recurso;
use App\Service\Acl\AclService;
use App\Support\ApiResponse;
use App\Support\IdentidadeContext;
use Hyperf\HttpServer\Router\Dispatched;
use LogicException;
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
        $opcoesAcl = $dispatched instanceof Dispatched
            ? ($dispatched->handler?->options['acl'] ?? null)
            : null;

        if (! is_array($opcoesAcl)) {
            return $handler->handle($request);
        }

        $permitido = $this->aclService->isAllowed(
            IdentidadeContext::cdPerfis(),
            self::recurso($opcoesAcl['recurso'] ?? null),
            self::privilegio($opcoesAcl['privilegio'] ?? null)
        );

        if (! $permitido) {
            return ApiResponse::erroHttp($this->response, 403, 'Sem permissão para esta ação.');
        }

        return $handler->handle($request);
    }

    /**
     * Aceita o enum direto (forma recomendada em config/routes.php) ou a ds_chave em
     * string. Chave desconhecida estoura em vez de negar em silêncio — erro de rota tem
     * que aparecer, não virar 403 misterioso (foi exatamente assim que 'pessoa'/'listar'
     * passaram batido).
     */
    private static function recurso(mixed $valor): Recurso
    {
        if ($valor instanceof Recurso) {
            return $valor;
        }

        if (is_string($valor)) {
            return Recurso::from($valor);
        }

        throw new LogicException('Opção de rota acl.recurso precisa ser App\Enum\Recurso ou a ds_chave em string.');
    }

    private static function privilegio(mixed $valor): Privilegio
    {
        if ($valor instanceof Privilegio) {
            return $valor;
        }

        if (is_string($valor)) {
            return Privilegio::from($valor);
        }

        throw new LogicException('Opção de rota acl.privilegio precisa ser App\Enum\Privilegio ou a ds_chave em string.');
    }
}
