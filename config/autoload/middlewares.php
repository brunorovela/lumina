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
use Hyperf\Validation\Middleware\ValidationMiddleware;

return [
    'http' => [
        // Sem isso, FormRequest::validateResolved() nunca é chamado (nenhum framework
        // hook dispara sozinho) — validated() apenas extrai os campos das regras sem checar
        // pass/fail, então toda a validação de CreatePessoaRequest/UpdatePessoaRequest/
        // PatchPessoaRequest/ListPessoaRequest/LoginRequest ficava sem efeito prático via
        // HTTP (descoberto na Task 14 ao testar de verdade a rota PATCH com payload vazio).
        ValidationMiddleware::class,
        // AclMiddleware é referenciado por rota individualmente (ver Task 14), não aqui.
    ],
];
