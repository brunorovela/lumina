<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\PessoaEndereco;
use Hyperf\Di\Annotation\Inject;

class PessoaEnderecoRepository extends AbstractRepository
{
    #[Inject]
    public function __construct(PessoaEndereco $model)
    {
        parent::__construct($model);
    }
}
