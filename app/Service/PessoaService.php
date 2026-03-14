<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PessoaRepository;
use Hyperf\Di\Annotation\Inject;

class PessoaService extends AbstractCrudService
{
    #[Inject]
    public function __construct(PessoaRepository $repository, \Psr\SimpleCache\CacheInterface $cache)
    {
        parent::__construct($repository, $cache);
    }

    protected function getResourceKey(): string
    {
        return 'pessoa';
    }
}
