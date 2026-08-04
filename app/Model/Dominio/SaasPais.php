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
 * Catálogo global: saas_pais não tem cd_cliente, logo não há escopo de tenant a aplicar.
 * Leitura apenas — sem $fillable de propósito, nada nesta API escreve em domínio.
 *
 * dt_base existe na tabela e NÃO é exposta: é controle do LMS legado
 * (ON UPDATE CURRENT_TIMESTAMP), não dado de negócio.
 *
 * @property int $cd_pais
 * @property null|string $ds_pais
 * @property null|string $ds_nacionalidade
 */
class SaasPais extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'saas_pais';

    protected string $primaryKey = 'cd_pais';
}
