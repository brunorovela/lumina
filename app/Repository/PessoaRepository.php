<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Pessoa;
use Hyperf\Di\Annotation\Inject;

class PessoaRepository extends AbstractRepository
{
    #[Inject]
    public function __construct(Pessoa $model)
    {
        parent::__construct($model);
    }
}
