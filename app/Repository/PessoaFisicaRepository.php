<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\PessoaFisica;
use Hyperf\Di\Annotation\Inject;

class PessoaFisicaRepository extends AbstractRepository
{
    #[Inject]
    public function __construct(PessoaFisica $model)
    {
        parent::__construct($model);
    }
}
