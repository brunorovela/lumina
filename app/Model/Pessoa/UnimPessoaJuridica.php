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
use Carbon\Carbon;

/**
 * @property int $cd_pessoa
 * @property string $ds_cnpj
 * @property string $ds_nome_fantasia
 * @property bool $sn_excluido
 * @property null|Carbon $dt_excluido
 */
class UnimPessoaJuridica extends Model
{
    public bool $incrementing = false;

    public bool $timestamps = false;

    protected ?string $table = 'unim_pessoa_juridica';

    protected string $primaryKey = 'cd_pessoa';

    /**
     * @var string[]
     */
    protected array $fillable = [
        'cd_pessoa',
        'ds_cnpj',
        'ds_nome_fantasia',
    ];

    /**
     * @var array<string, string>
     */
    protected array $casts = [
        'sn_excluido' => 'boolean',
        'dt_excluido' => 'datetime',
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $attributes = [
        'sn_excluido' => false,
    ];
}
