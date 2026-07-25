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

namespace App\Model\Pessoa;

use App\Model\Model;

class UnimPessoaJuridica extends Model
{
    public bool $incrementing = false;

    public bool $timestamps = false;

    protected ?string $table = 'unim_pessoa_juridica';

    protected string $primaryKey = 'cd_pessoa';

    protected array $fillable = [
        'cd_pessoa',
        'ds_cnpj',
        'ds_nome_fantasia',
    ];

    protected array $casts = [
        'sn_excluido' => 'boolean',
        'dt_excluido' => 'datetime',
    ];

    protected array $attributes = [
        'sn_excluido' => false,
    ];
}
