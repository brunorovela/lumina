<?php

declare(strict_types=1);

namespace App\Model;

use Carbon\Carbon;

/**
 * @property int $cd_pessoa
 * @property int $cd_estado_civil
 * @property string $ds_nome_oficial
 * @property string $ds_nome_social
 * @property Carbon $dt_nascimento
 * @property string $ds_cpf
 * @property string $ds_sexo
 */
class PessoaFisica extends Model
{
    protected ?string $table = 'unim_pessoa_fisica';
    protected string $primaryKey = 'cd_pessoa';
    public bool $incrementing = false;
    public bool $timestamps = false;

    protected array $fillable = [
        'cd_pessoa', 'cd_estado_civil', 'ds_nome_oficial',
        'ds_nome_social', 'ds_cpf', 'dt_nascimento', 'ds_sexo'
    ];

    protected array $casts = [
        'cd_pessoa' => 'integer',
        'cd_estado_civil' => 'integer',
        'dt_nascimento' => 'date', // Carbon converterá automaticamente
    ];

    public function pessoa()
    {
        return $this->belongsTo(Pessoa::class, 'cd_pessoa', 'cd_pessoa');
    }
}