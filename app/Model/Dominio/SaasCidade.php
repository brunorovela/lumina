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

namespace App\Model\Dominio;

use App\Model\Model;

/**
 * Catálogo global. Ver App\Model\Dominio\SaasPais.
 *
 * 4928 linhas: a leitura sempre passa por cd_estado, nunca varre a tabela inteira.
 *
 * @property int $cd_cidade
 * @property int $cd_estado
 * @property null|string $ds_cidade
 */
class SaasCidade extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'saas_cidade';

    protected string $primaryKey = 'cd_cidade';
}
