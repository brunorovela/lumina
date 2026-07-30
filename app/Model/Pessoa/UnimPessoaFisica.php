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
 * @property null|int $cd_estado_civil
 * @property string $ds_nome_oficial
 * @property null|string $ds_nome_social
 * @property null|string $ds_nome_mae
 * @property null|string $ds_nome_pai
 * @property null|string $ds_identidade
 * @property null|string $ds_orgao_estado
 * @property null|string $ds_identidade_orgao_exp
 * @property null|Carbon $dt_identidade_expedicao
 * @property null|Carbon $dt_nascimento
 * @property null|string $ds_cpf
 * @property null|string $ds_sexo
 */
class UnimPessoaFisica extends Model
{
    public bool $incrementing = false;

    public bool $timestamps = false;

    protected ?string $table = 'unim_pessoa_fisica';

    protected string $primaryKey = 'cd_pessoa';

    /**
     * @var string[]
     */
    protected array $fillable = [
        'cd_pessoa',
        'ds_nome_oficial',
        'ds_nome_social',
        'ds_nome_mae',
        'ds_nome_pai',
        'ds_identidade',
        'ds_orgao_estado',
        'ds_identidade_orgao_exp',
        'dt_identidade_expedicao',
        'dt_nascimento',
        'ds_cpf',
        'ds_sexo',
        'cd_estado_civil',
    ];

    /**
     * @var array<string, string>
     */
    protected array $casts = [
        'dt_identidade_expedicao' => 'date',
        'dt_nascimento' => 'date',
    ];
}
