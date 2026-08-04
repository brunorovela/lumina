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
 * @property int $cd_estado
 * @property int $cd_pais
 * @property null|string $ds_estado
 * @property null|string $ds_uf
 */
class SaasEstado extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'saas_estado';

    protected string $primaryKey = 'cd_estado';
}
