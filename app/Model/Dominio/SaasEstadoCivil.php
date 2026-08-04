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
 * Destino da FK unim_pessoa_fisica.cd_estado_civil.
 *
 * @property int $cd_estado_civil
 * @property null|string $ds_estado_civil
 */
class SaasEstadoCivil extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'saas_estado_civil';

    protected string $primaryKey = 'cd_estado_civil';
}
