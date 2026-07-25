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
use Hyperf\Validation\Middleware\ValidationMiddleware;

Router::addRoute(['GET', 'POST', 'HEAD'], '/', 'App\Controller\IndexController@index');

Router::get('/favicon.ico', function () {
    return '';
});

// ValidationMiddleware roda por rota (não global — ver config/autoload/middlewares.php).
// Em /pessoas, ele é declarado DEPOIS de AuthMiddleware/AclMiddleware na mesma lista de
// 'middleware' do grupo: RouteCollector::mergeOptions() concatena (array_merge_recursive)
// as opções do grupo com as da rota mantendo a ordem de aparição, e
// Hyperf\Testing\Http\Client::execute() (usado por todo o harness de teste) preserva essa
// ordem ao montar a pipeline final — sem precisar de PriorityMiddleware/sortMiddlewares()
// (que o harness de teste não chama). Resultado: token inválido/ausente barra em 401 antes
// da validação rodar, mesmo com payload inválido (Finding 8, whole-branch review).
// Em /auth/login não há Auth/Acl antes (rota pública), então ValidationMiddleware entra
// sozinho.
Router::post('/auth/login', [AuthController::class, 'login'], ['middleware' => [ValidationMiddleware::class]]);
Router::post('/auth/logout', [AuthController::class, 'logout'], ['middleware' => [AuthMiddleware::class]]);

Router::addGroup('/pessoas', function () {
    Router::post('', [PessoaController::class, 'criar'], [
        'acl' => ['recurso' => 'pessoa', 'privilegio' => 'criar'],
        'middleware' => [ValidationMiddleware::class],
    ]);
    Router::put('/{id}', [PessoaController::class, 'atualizar'], [
        'acl' => ['recurso' => 'pessoa', 'privilegio' => 'atualizar'],
        'middleware' => [ValidationMiddleware::class],
    ]);
    Router::patch('/{id}', [PessoaController::class, 'atualizarParcial'], [
        'acl' => ['recurso' => 'pessoa', 'privilegio' => 'atualizar'],
        'middleware' => [ValidationMiddleware::class],
    ]);
    Router::get('/{id}', [PessoaController::class, 'buscar'], ['acl' => ['recurso' => 'pessoa', 'privilegio' => 'visualizar']]);
    Router::get('', [PessoaController::class, 'listar'], [
        'acl' => ['recurso' => 'pessoa', 'privilegio' => 'listar'],
        'middleware' => [ValidationMiddleware::class],
    ]);
    Router::delete('/{id}', [PessoaController::class, 'excluir'], ['acl' => ['recurso' => 'pessoa', 'privilegio' => 'excluir']]);
}, ['middleware' => [AuthMiddleware::class, AclMiddleware::class]]);
