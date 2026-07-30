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

use App\Support\Tipo;

class IndexController extends AbstractController
{
    /**
     * @return array<string, string>
     */
    public function index(): array
    {
        // input() devolve mixed (vem da query string/corpo, sem garantia de tipo); sem o
        // cast a interpolação abaixo é uma conversão implícita de mixed para string.
        $user = Tipo::texto($this->request->input('user', 'Hyperf'), 'Hyperf');
        $method = $this->request->getMethod();

        return [
            'method' => $method,
            'message' => "Hello {$user}.",
        ];
    }
}
