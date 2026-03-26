<?php

declare(strict_types=1);

use function Hyperf\Support\env;

/**
 * @see https://hyperf.wiki/3.0/#/en/swagger
 * UI: http://<host>:<swagger.port><url>  (padrão porta 9500, path /swagger)
 * JSON: mesmo host/porta — arquivo em json_dir (ex.: /http.json → storage/swagger/http.json)
 */
$swaggerUiHtml = BASE_PATH . '/storage/swagger/swagger-ui.html';

return [
    'enable' => env('SWAGGER_ENABLE', true),
    'port' => (int) env('SWAGGER_PORT', 9500),
    'json_dir' => BASE_PATH . '/storage/swagger',
    /** HTML custom: evita CDN unpkg.hyperf.wiki (costuma travar / falhar fora da rede deles) */
    'html' => is_readable($swaggerUiHtml) ? (string) file_get_contents($swaggerUiHtml) : null,
    'url' => env('SWAGGER_URL_PATH', '/swagger'),
    'auto_generate' => filter_var(env('SWAGGER_AUTO_GENERATE', true), FILTER_VALIDATE_BOOLEAN),
    'scan' => [
        'paths' => [
            BASE_PATH . '/app',
        ],
    ],
    'processors' => [],
    'server' => [
        'http' => [
            'servers' => [
                [
                    'url' => env('SWAGGER_SERVER_URL', 'http://127.0.0.1:9501'),
                    'description' => 'Lumina (API principal)',
                ],
            ],
            'info' => [
                'title' => 'Lumina API',
                'description' => 'LMS / integrações — OpenAPI gerado a partir de atributos em app/',
                'version' => env('APP_VERSION', '1.0.0'),
            ],
        ],
    ],
];
