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
use App\Controller\Dominio\DominioController;
use App\Controller\Pessoa\PessoaController;
use App\Enum\Privilegio;
use App\Enum\Recurso;
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

// As chaves de ACL são as MESMAS do LMS atual (ulms_recurso.ds_chave /
// ulms_privilegio.ds_chave). O LMS não tem privilégio de "listar"/"visualizar":
// leitura é ACESSAR (ver Admin\Controller\GerenciarPessoaController::listarAction()).
Router::addGroup('/pessoas', function () {
    Router::post('', [PessoaController::class, 'criar'], [
        'acl' => ['recurso' => Recurso::GERENCIAR_PESSOA, 'privilegio' => Privilegio::INSERIR],
        'middleware' => [ValidationMiddleware::class],
    ]);
    Router::put('/{id}', [PessoaController::class, 'atualizar'], [
        'acl' => ['recurso' => Recurso::GERENCIAR_PESSOA, 'privilegio' => Privilegio::ATUALIZAR],
        'middleware' => [ValidationMiddleware::class],
    ]);
    Router::patch('/{id}', [PessoaController::class, 'atualizarParcial'], [
        'acl' => ['recurso' => Recurso::GERENCIAR_PESSOA, 'privilegio' => Privilegio::ATUALIZAR],
        'middleware' => [ValidationMiddleware::class],
    ]);
    Router::get('/{id}', [PessoaController::class, 'buscar'], [
        'acl' => ['recurso' => Recurso::GERENCIAR_PESSOA, 'privilegio' => Privilegio::ACESSAR],
        'middleware' => [ValidationMiddleware::class],
    ]);
    Router::get('', [PessoaController::class, 'listar'], [
        'acl' => ['recurso' => Recurso::GERENCIAR_PESSOA, 'privilegio' => Privilegio::ACESSAR],
        'middleware' => [ValidationMiddleware::class],
    ]);
    // GERENCIAR_PESSOA + DELETAR não existia em ulms_recurso_privilegio (o LMS não expõe
    // exclusão de pessoa); o par é criado pela migration
    // 2026_07_30_000000_adiciona_privilegio_deletar_em_gerenciar_pessoa.
    Router::delete('/{id}', [PessoaController::class, 'excluir'], [
        'acl' => ['recurso' => Recurso::GERENCIAR_PESSOA, 'privilegio' => Privilegio::DELETAR],
    ]);
}, ['middleware' => [AuthMiddleware::class, AclMiddleware::class]]);

// Catálogos que alimentam o cadastro de pessoa. Globais: nenhuma das tabelas tem
// cd_cliente, então não há escopo de tenant aqui — ao contrário de /pessoas.
// O ACL reusa GERENCIAR_PESSOA + ACESSAR porque é o único par existente em
// ulms_recurso_privilegio para este domínio, e chave inventada nega tudo em silêncio.
// Sem ValidationMiddleware nestas três: não recebem parâmetro nenhum.
Router::get('/paises', [DominioController::class, 'paises'], [
    'acl' => ['recurso' => Recurso::GERENCIAR_PESSOA, 'privilegio' => Privilegio::ACESSAR],
    'middleware' => [AuthMiddleware::class, AclMiddleware::class],
]);

Router::get('/estados-civis', [DominioController::class, 'estadosCivis'], [
    'acl' => ['recurso' => Recurso::GERENCIAR_PESSOA, 'privilegio' => Privilegio::ACESSAR],
    'middleware' => [AuthMiddleware::class, AclMiddleware::class],
]);

Router::get('/contato-tipos', [DominioController::class, 'tiposDeContato'], [
    'acl' => ['recurso' => Recurso::GERENCIAR_PESSOA, 'privilegio' => Privilegio::ACESSAR],
    'middleware' => [AuthMiddleware::class, AclMiddleware::class],
]);
