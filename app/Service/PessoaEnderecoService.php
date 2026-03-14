<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PessoaEnderecoRepository;
use Hyperf\Di\Annotation\Inject;

class PessoaEnderecoService extends AbstractCrudService
{
    #[Inject]
    public function __construct(PessoaEnderecoRepository $repository, \Psr\SimpleCache\CacheInterface $cache)
    {
        parent::__construct($repository, $cache);
    }

    protected function getResourceKey(): string
    {
        return 'pessoa_endereco';
    }
}
