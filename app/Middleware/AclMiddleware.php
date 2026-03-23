<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Auth\AclService;
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
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Aqui você obteria o perfil do usuário logado (via JWT ou sessão)
        $cdPerfil = (int) $request->getAttribute('user_profile_id');
        
        // Estes atributos podem ser passados na definição da rota
        $resource = $request->getAttribute('acl_resource');
        $level = (int) $request->getAttribute('acl_level');

        if ($resource && ! $this->acl->isAllowed($cdPerfil, $resource, $level)) {
            return $this->response->json(['error' => 'Acesso negado para este recurso'])->withStatus(403);
        }

        return $handler->handle($request);
    }
}