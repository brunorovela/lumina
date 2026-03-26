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

use Hyperf\RateLimit\Annotation\RateLimit;
use Hyperf\Swagger\Annotation\HyperfServer;
use OpenApi\Attributes as OA;

#[HyperfServer(name: 'http')]
class IndexController extends AbstractController
{
    #[RateLimit(create: 1, capacity: 5)]
    #[OA\Get(path: '/', summary: 'Entrada / health', tags: ['System'])]
    #[OA\Response(response: 200, description: 'OK')]
    public function index(): array
    {
        $user = $this->request->input('user', 'Hyperf');
        $method = $this->request->getMethod();

        return [
            'method' => $method,
            'message' => $user,
        ];
    }
}
