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

/** @return array<string, mixed> */
$acl = static function (string $resource, int $level): array {
    return [
        'middleware' => [AclMiddleware::class],
        'acl_resource' => $resource,
        'acl_level' => $level,
    ];
};

// CRUD Pessoa
Router::get('/pessoa', [PessoaController::class, 'index'], $acl('Pessoa.Gerenciamento', Permission::ACESSAR));
Router::get('/pessoa/{id:\d+}', [PessoaController::class, 'show'], $acl('Pessoa.Gerenciamento', Permission::ACESSAR));
Router::post('/pessoa', [PessoaController::class, 'store'], $acl('Pessoa.Gerenciamento', Permission::INSERIR));
Router::put('/pessoa/{id:\d+}', [PessoaController::class, 'update'], $acl('Pessoa.Gerenciamento', Permission::ALTERAR));
Router::delete('/pessoa/{id:\d+}', [PessoaController::class, 'destroy'], $acl('Pessoa.Gerenciamento', Permission::REMOVER));

// CRUD PessoaFisica
Router::get('/pessoa-fisica', [PessoaFisicaController::class, 'index'], $acl('PessoaFisica.Gerenciamento', Permission::ACESSAR));
Router::get('/pessoa-fisica/{id:\d+}', [PessoaFisicaController::class, 'show'], $acl('PessoaFisica.Gerenciamento', Permission::ACESSAR));
Router::post('/pessoa-fisica', [PessoaFisicaController::class, 'store'], $acl('PessoaFisica.Gerenciamento', Permission::INSERIR));
Router::put('/pessoa-fisica/{id:\d+}', [PessoaFisicaController::class, 'update'], $acl('PessoaFisica.Gerenciamento', Permission::ALTERAR));
Router::delete('/pessoa-fisica/{id:\d+}', [PessoaFisicaController::class, 'destroy'], $acl('PessoaFisica.Gerenciamento', Permission::REMOVER));

// CRUD PessoaEndereco
Router::get('/pessoa-endereco', [PessoaEnderecoController::class, 'index'], $acl('PessoaEndereco.Gerenciamento', Permission::ACESSAR));
Router::get('/pessoa-endereco/{id:\d+}', [PessoaEnderecoController::class, 'show'], $acl('PessoaEndereco.Gerenciamento', Permission::ACESSAR));
Router::post('/pessoa-endereco', [PessoaEnderecoController::class, 'store'], $acl('PessoaEndereco.Gerenciamento', Permission::INSERIR));
Router::put('/pessoa-endereco/{id:\d+}', [PessoaEnderecoController::class, 'update'], $acl('PessoaEndereco.Gerenciamento', Permission::ALTERAR));
Router::delete('/pessoa-endereco/{id:\d+}', [PessoaEnderecoController::class, 'destroy'], $acl('PessoaEndereco.Gerenciamento', Permission::REMOVER));

Router::get('/favicon.ico', function () {
    return '';
});
