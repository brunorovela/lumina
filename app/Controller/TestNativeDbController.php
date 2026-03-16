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

namespace App\Controller;

use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;

use function Hyperf\Config\config;

#[Controller]
class TestNativeDbController
{
    #[GetMapping(path: '/test-native')]
    public function index()
    {
        // O Db::connection('default') utiliza o Pool configurado no seu databases.php
        // $user = Db::table('unim_pessoa')->select('ds_nome')->first();

        return [
            'status' => 'Conectado via Hyperf Database Nativo',
            'data' => '$user',
            'config' => [
                'driver' => config('databases.default.driver'),
                'pool_max' => config('databases.default.pool.max_connections'),
            ],
        ];
    }
}
