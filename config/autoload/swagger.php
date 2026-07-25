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
use function Hyperf\Support\env;

return [
    // `enable` liga o listener que sobe um servidor HTTP dedicado (porta abaixo) servindo
    // a Swagger UI + o JSON. Mantido desativado de propósito: aqui só queremos gerar o
    // arquivo estático via `php bin/hyperf.php gen:swagger`; quem serve o JSON/UI é o
    // container de documentação (Task 16), lendo storage/swagger/http.json direto do disco.
    'enable' => (bool) env('SWAGGER_ENABLE_SERVER', false),
    'port' => 9500,
    'json_dir' => BASE_PATH . '/storage/swagger',
    'html' => null,
    'url' => env('SWAGGER_URL_PATH', '/swagger'),
    // Só tem efeito quando `enable` é true (o comando `gen:swagger` sempre gera o
    // arquivo na chamada, independente deste valor).
    'auto_generate' => false,
    'scan' => [
        'paths' => [BASE_PATH . '/app'],
    ],
    'processors' => [
        // usuários podem anexar processors próprios aqui
    ],
    // Uma entrada por nome de servidor — o nome vem do atributo
    // #[OA\HyperfServer(name: '...')] nos controllers. O servidor HTTP principal deste
    // projeto se chama 'http' (ver config/autoload/server.php), por isso o arquivo
    // gerado é storage/swagger/http.json.
    'server' => [
        'http' => [
            'info' => [
                'title' => 'Lumina API',
                'description' => 'Orquestrador de serviços e integrações — rotas de pessoa e autenticação.',
                'version' => '1.0.0',
            ],
        ],
    ],
];
