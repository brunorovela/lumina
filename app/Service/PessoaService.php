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

namespace App\Service;

use App\Repository\PessoaRepository;
use Psr\SimpleCache\CacheInterface;

class PessoaService extends AbstractCrudService
{
    public function __construct(PessoaRepository $repository, CacheInterface $cache)
    {
        parent::__construct($repository, $cache);
    }

    protected function getResourceKey(): string
    {
        return 'pessoa';
    }
}
