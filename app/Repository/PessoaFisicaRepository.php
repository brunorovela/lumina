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

namespace App\Repository;

use App\Model\PessoaFisica;

class PessoaFisicaRepository extends AbstractRepository
{
    public function __construct(PessoaFisica $model)
    {
        parent::__construct($model);
    }
}
