<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\RateLimit\Annotation\RateLimit;
use function Hyperf\Support\env;

class IndexController extends AbstractController
{
    #[RateLimit(create: 1, capacity: 5)]
    public function index(): array
    {
        $user = $this->request->input('user', 'Hyperf');
        $method = $this->request->getMethod();

        return [
            'method' => $method,
            'message' => env('REDIS_HOST'),
        ];
    }
}
