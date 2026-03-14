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
use Hyperf\HttpServer\Router\Router;

Router::addRoute(['GET', 'POST', 'HEAD'], '/', 'App\Controller\IndexController@index');

// CRUD Pessoa
Router::get('/pessoa', [\App\Controller\PessoaController::class, 'index']);
Router::get('/pessoa/{id:\d+}', [\App\Controller\PessoaController::class, 'show']);
Router::post('/pessoa', [\App\Controller\PessoaController::class, 'store']);
Router::put('/pessoa/{id:\d+}', [\App\Controller\PessoaController::class, 'update']);
Router::delete('/pessoa/{id:\d+}', [\App\Controller\PessoaController::class, 'destroy']);

// CRUD PessoaFisica
Router::get('/pessoa-fisica', [\App\Controller\PessoaFisicaController::class, 'index']);
Router::get('/pessoa-fisica/{id:\d+}', [\App\Controller\PessoaFisicaController::class, 'show']);
Router::post('/pessoa-fisica', [\App\Controller\PessoaFisicaController::class, 'store']);
Router::put('/pessoa-fisica/{id:\d+}', [\App\Controller\PessoaFisicaController::class, 'update']);
Router::delete('/pessoa-fisica/{id:\d+}', [\App\Controller\PessoaFisicaController::class, 'destroy']);

// CRUD PessoaEndereco
Router::get('/pessoa-endereco', [\App\Controller\PessoaEnderecoController::class, 'index']);
Router::get('/pessoa-endereco/{id:\d+}', [\App\Controller\PessoaEnderecoController::class, 'show']);
Router::post('/pessoa-endereco', [\App\Controller\PessoaEnderecoController::class, 'store']);
Router::put('/pessoa-endereco/{id:\d+}', [\App\Controller\PessoaEnderecoController::class, 'update']);
Router::delete('/pessoa-endereco/{id:\d+}', [\App\Controller\PessoaEnderecoController::class, 'destroy']);

Router::get('/favicon.ico', function () {
    return '';
});
