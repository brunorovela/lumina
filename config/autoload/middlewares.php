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
return [
    'http' => [
        // ValidationMiddleware (sem o qual FormRequest::validateResolved() nunca roda —
        // ver histórico na Task 14) NÃO fica aqui: um middleware global roda ANTES de
        // qualquer middleware por rota (AuthMiddleware/AclMiddleware), então um cliente
        // sem token conseguia descobrir a forma do contrato de validação (quais campos
        // existem, quais são obrigatórios) de uma rota antes de se autenticar (Finding 8,
        // whole-branch review). Por isso ValidationMiddleware é declarado por rota, em
        // config/routes.php, sempre DEPOIS de AuthMiddleware/AclMiddleware na mesma lista
        // — ver comentário lá pro porquê disso ser seguro sem depender de
        // Hyperf\HttpServer\PriorityMiddleware/MiddlewareManager::sortMiddlewares()
        // (que Hyperf\Testing\Http\Client, usado por todo o harness de teste deste
        // projeto, não chama).
        // AclMiddleware é referenciado por rota individualmente (ver Task 14), não aqui.
    ],
];
