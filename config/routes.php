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

use App\Constants\Permission;
use App\Controller\PessoaController;
use App\Controller\PessoaEnderecoController;
use App\Controller\PessoaFisicaController;
use App\Middleware\AclMiddleware;
use Hyperf\HttpServer\Router\Router;

Router::addRoute(['GET', 'POST', 'HEAD'], '/', 'App\Controller\IndexController@index');

// CRUD Pessoa
Router::get('/pessoa', [PessoaController::class, 'index'],[
    'middleware' => [AclMiddleware::class],
    'options' => [
        'acl_resource' => 'Pessoa.Gerenciamento',
        'acl_level' => Permission::ACESSAR
    ]
]);

Router::get('/pessoa/{id:\d+}', [PessoaController::class, 'show']);
Router::post('/pessoa', [PessoaController::class, 'store']);
Router::put('/pessoa/{id:\d+}', [PessoaController::class, 'update']);
Router::delete('/pessoa/{id:\d+}', [PessoaController::class, 'destroy']);

// CRUD PessoaFisica
Router::get('/pessoa-fisica', [PessoaFisicaController::class, 'index']);
Router::get('/pessoa-fisica/{id:\d+}', [PessoaFisicaController::class, 'show']);
Router::post('/pessoa-fisica', [PessoaFisicaController::class, 'store']);
Router::put('/pessoa-fisica/{id:\d+}', [PessoaFisicaController::class, 'update']);
Router::delete('/pessoa-fisica/{id:\d+}', [PessoaFisicaController::class, 'destroy']);

// CRUD PessoaEndereco
Router::get('/pessoa-endereco', [PessoaEnderecoController::class, 'index']);
Router::get('/pessoa-endereco/{id:\d+}', [PessoaEnderecoController::class, 'show']);
Router::post('/pessoa-endereco', [PessoaEnderecoController::class, 'store']);
Router::put('/pessoa-endereco/{id:\d+}', [PessoaEnderecoController::class, 'update']);
Router::delete('/pessoa-endereco/{id:\d+}', [PessoaEnderecoController::class, 'destroy']);

Router::get('/favicon.ico', function () {
    return '';
});
