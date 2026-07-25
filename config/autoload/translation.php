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
    // Sem este arquivo, Hyperf\Translation\TranslatorFactory cai no default do pacote
    // (zh_CN) — e agora que Hyperf\Validation\Middleware\ValidationMiddleware está
    // registrado de verdade (Task 14), toda mensagem de erro 422 saía em chinês pra
    // qualquer cliente da API. 'en' é o "menos pior" default seguro; tradução completa
    // pra pt_BR é escopo novo, fora desta task.
    'locale' => 'en',
    'fallback_locale' => 'en',
    'path' => BASE_PATH . '/storage/languages',
];
