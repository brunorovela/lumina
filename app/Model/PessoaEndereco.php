<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $cd_pessoa
 * @property int $cd_pais
 * @property int $cd_estado
 * @property int $cd_cidade
 * @property string $ds_cep
 * @property string $ds_bairro
 * @property string $ds_logradouro
 * @property string $ds_numero
 * @property string $ds_complemento
 */
class PessoaEndereco extends Model
{
    protected ?string $table = 'unim_pessoa_endereco';

    /**
     * Como a tabela não possui uma coluna auto-incremento convencional (usa cd_pessoa),
     * definimos que a chave primária não é incrementável.
     */
    protected string $primaryKey = 'cd_pessoa';
    public bool $incrementing = false;
    public bool $timestamps = false;

    protected array $fillable = [
        'cd_pessoa', 'cd_pais', 'cd_estado', 'cd_cidade',
        'ds_cep', 'ds_bairro', 'ds_logradouro', 'ds_numero', 'ds_complemento'
    ];

    protected array $casts = [
        'cd_pessoa' => 'integer',
        'cd_pais' => 'integer',
        'cd_estado' => 'integer',
        'cd_cidade' => 'integer',
    ];

    public function pessoa()
    {
        return $this->belongsTo(Pessoa::class, 'cd_pessoa', 'cd_pessoa');
    }
}