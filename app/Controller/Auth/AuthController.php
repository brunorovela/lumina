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

namespace App\Controller\Auth;

use App\Controller\AbstractController;
use App\Request\Auth\LoginRequest;
use App\Service\Auth\AuthService;
use App\Support\ApiResponse;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Swagger\Annotation as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\HyperfServer(name: 'http')]
class AuthController extends AbstractController
{
    #[Inject]
    protected AuthService $authService;

    #[OA\Post(path: '/auth/login', summary: 'Autentica uma pessoa e retorna um token', tags: ['Auth'])]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['cd_cliente', 'ds_login', 'ds_senha'],
            properties: [
                new OA\Property(property: 'cd_cliente', type: 'integer'),
                new OA\Property(property: 'ds_login', type: 'string'),
                new OA\Property(property: 'ds_senha', type: 'string'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Autenticado com sucesso')]
    #[OA\Response(response: 401, description: 'Login ou senha inválidos')]
    #[OA\Response(response: 422, description: 'Dados inválidos')]
    public function login(LoginRequest $request): ResponseInterface
    {
        $dados = $request->validated();

        $token = $this->authService->autenticar($dados['cd_cliente'], $dados['ds_login'], $dados['ds_senha']);

        return $this->response->json(ApiResponse::sucesso(['token' => $token]));
    }

    #[OA\Post(path: '/auth/logout', summary: 'Invalida o token de autenticação atual', tags: ['Auth'])]
    #[OA\Response(response: 200, description: 'Logout realizado com sucesso')]
    #[OA\Response(response: 401, description: 'Não autenticado')]
    public function logout(): ResponseInterface
    {
        $token = str_replace('Bearer ', '', $this->request->getHeaderLine('Authorization'));

        $this->authService->logout($token);

        return $this->response->json(ApiResponse::sucesso(null));
    }
}
