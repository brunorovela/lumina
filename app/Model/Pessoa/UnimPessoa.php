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
use Hyperf\Database\Model\Relations\HasOne;
use Hyperf\Database\Model\SoftDeletes;

class UnimPessoa extends Model
{
    use SoftDeletes;

    public const DELETED_AT = 'dt_excluido';

    public bool $timestamps = false;

    protected ?string $table = 'unim_pessoa';

    protected string $primaryKey = 'cd_pessoa';

    protected array $fillable = [
        'cd_cliente',
        'cd_imagem',
        'ds_nome',
        'ds_login',
        'ds_senha',
        'sn_pessoa_juridica',
        'me_qualificacao',
        'ds_seguimento',
        'ds_marca',
        'ds_unidade',
        'ds_turma',
        'dt_cadastro',
        'dt_base',
    ];

    protected array $hidden = ['ds_senha'];

    protected array $casts = [
        'sn_pessoa_juridica' => 'boolean',
        'dt_cadastro' => 'datetime',
        'dt_base' => 'datetime',
        'dt_excluido' => 'datetime',
    ];

    public function fisica(): HasOne
    {
        return $this->hasOne(UnimPessoaFisica::class, 'cd_pessoa', 'cd_pessoa');
    }

    public function juridica(): HasOne
    {
        return $this->hasOne(UnimPessoaJuridica::class, 'cd_pessoa', 'cd_pessoa');
    }
}
