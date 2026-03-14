<?php

declare(strict_types=1);

namespace App\Model;

use Carbon\Carbon;

/**
 * @property int $cd_pessoa
 * @property int $cd_cliente
 * @property string $ds_nome
 * @property string $ds_login
 * @property string $ds_senha
 * @property int $sn_pessoa_juridica
 * @property string $me_qualificacao
 * @property int $cd_imagem
 * @property string $ds_seguimento
 * @property string $ds_marca
 * @property string $ds_unidade
 * @property string $ds_turma
 * @property Carbon $dt_cadastro
 * @property Carbon $dt_base
 */
class Pessoa extends Model
{
    /**
     * Tabela associada no banco de dados.
     */
    protected ?string $table = 'unim_pessoa';

    /**
     * Chave primária da tabela.
     */
    protected string $primaryKey = 'cd_pessoa';

    /**
     * Atributos que podem ser preenchidos via Mass Assignment.
     * Importante para segurança e uso do método Pessoa::create().
     */
    protected array $fillable = [
        'cd_cliente',
        'ds_nome',
        'ds_login',
        'ds_senha',
        'sn_pessoa_juridica',
        'me_qualificacao',
        'cd_imagem',
        'ds_seguimento',
        'ds_marca',
        'ds_unidade',
        'ds_turma',
        'dt_cadastro',
    ];

    /**
     * Conversão de tipos (Casting).
     * Garante que os dados retornem do banco com o tipo correto para o PHP.
     */
    protected array $casts = [
        'cd_pessoa' => 'integer',
        'cd_cliente' => 'integer',
        'sn_pessoa_juridica' => 'integer',
        'cd_imagem' => 'integer',
        'dt_cadastro' => 'datetime',
        'dt_base' => 'datetime',
    ];

    /**
     * Desativa os timestamps padrão do Eloquent (created_at/updated_at),
     * já que sua estrutura utiliza dt_cadastro e dt_base manualmente.
     */
    public bool $timestamps = false;

    /**
     * Exemplo de Relacionamento: Pessoa Física.
     * Uma Pessoa pode ter um registro detalhado em unim_pessoa_fisica.
     */
    public function pessoaFisica()
    {
        return $this->hasOne(PessoaFisica::class, 'cd_pessoa', 'cd_pessoa');
    }

    /**
     * Exemplo de Relacionamento: Endereço.
     */
    public function endereco()
    {
        return $this->hasOne(PessoaEndereco::class, 'cd_pessoa', 'cd_pessoa');
    }

    /**
     * Mutator para Criptografia de Senha.
     * Garante que a senha sempre seja salva como hash.
     */
    public function setDsSenhaAttribute($value): void
    {
        if (! empty($value)) {
            $this->attributes['ds_senha'] = password_hash($value, PASSWORD_DEFAULT);
        }
    }
}