<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'default' => [
        'driver' => env('DB_DRIVER', ''),
        'read' => [
            'host' => [env('DB_READ_HOST', '')], // Pode ser um array para Load Balancing
        ],
        'write' => [
            'host' => [env('DB_WRITE_HOST', '')],
        ],
        'sticky'    => true, // VITAL: Garante que se você escreveu algo na mesma requisição, a leitura venha do Master para evitar lag de replicação
        'host' => env('DB_HOST', ''),
        'database' => env('DB_DATABASE', ''),
        'port' => env('DB_PORT', 3306),
        'username' => env('DB_USERNAME', ''),
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
        'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
        'prefix' => env('DB_PREFIX', ''),
        'pool' => [
            'min_connections' => 32,   // Mantém mais conexões prontas para uso imediato
            'max_connections' => 512,  // Aumente significativamente para suportar a fila de corrotinas
            'connect_timeout' => 10.0,
            'wait_timeout'    => 10.0, // Dá mais tempo para a corrotina esperar um slot livre no pool
            'heartbeat'       => -1,
            'max_idle_time'   => (float) env('DB_MAX_IDLE_TIME', 60),
        ],
        'commands' => [
            'gen:model' => [
                'path' => 'app/Model',
                'force_casts' => true,
                'inheritance' => 'Model',
            ],
        ],
    ],
];
