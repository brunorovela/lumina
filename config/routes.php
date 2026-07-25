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
use App\Controller\Auth\AuthController;
use App\Controller\Pessoa\PessoaController;
use App\Middleware\AclMiddleware;
use App\Middleware\AuthMiddleware;
use Hyperf\HttpServer\Router\Router;

Router::addRoute(['GET', 'POST', 'HEAD'], '/', 'App\Controller\IndexController@index');

Router::get('/favicon.ico', function () {
    return '';
});

Router::post('/auth/login', [AuthController::class, 'login']);
Router::post('/auth/logout', [AuthController::class, 'logout'], ['middleware' => [AuthMiddleware::class]]);

Router::addGroup('/pessoas', function () {
    Router::post('', [PessoaController::class, 'criar'], ['acl' => ['recurso' => 'pessoa', 'privilegio' => 'criar']]);
    Router::put('/{id}', [PessoaController::class, 'atualizar'], ['acl' => ['recurso' => 'pessoa', 'privilegio' => 'atualizar']]);
    Router::patch('/{id}', [PessoaController::class, 'atualizarParcial'], ['acl' => ['recurso' => 'pessoa', 'privilegio' => 'atualizar']]);
    Router::get('/{id}', [PessoaController::class, 'buscar'], ['acl' => ['recurso' => 'pessoa', 'privilegio' => 'visualizar']]);
    Router::get('', [PessoaController::class, 'listar'], ['acl' => ['recurso' => 'pessoa', 'privilegio' => 'listar']]);
    Router::delete('/{id}', [PessoaController::class, 'excluir'], ['acl' => ['recurso' => 'pessoa', 'privilegio' => 'excluir']]);
}, ['middleware' => [AuthMiddleware::class, AclMiddleware::class]]);
