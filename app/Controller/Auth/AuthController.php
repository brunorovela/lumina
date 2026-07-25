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
use Psr\Http\Message\ResponseInterface;

class AuthController extends AbstractController
{
    #[Inject]
    protected AuthService $authService;

    public function login(LoginRequest $request): ResponseInterface
    {
        $dados = $request->validated();

        $token = $this->authService->autenticar($dados['cd_cliente'], $dados['ds_login'], $dados['ds_senha']);

        return $this->response->json(ApiResponse::sucesso(['token' => $token]));
    }

    public function logout(): ResponseInterface
    {
        $token = str_replace('Bearer ', '', $this->request->getHeaderLine('Authorization'));

        $this->authService->logout($token);

        return $this->response->json(ApiResponse::sucesso(null));
    }
}
