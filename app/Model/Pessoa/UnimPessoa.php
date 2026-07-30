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
use Hyperf\Database\Model\Relations\HasOne;
use Hyperf\Database\Model\SoftDeletes;

/**
 * Colunas espelham unim_pessoa (schema compartilhado com o LMS legado). Sem as anotações
 * abaixo o phpstan acusa property.notFound em todo acesso a atributo, porque o Eloquent
 * resolve coluna via __get.
 *
 * @property int $cd_pessoa
 * @property int $cd_cliente
 * @property null|string $ds_nome
 * @property null|string $ds_login
 * @property null|string $ds_senha
 * @property null|bool $sn_pessoa_juridica
 * @property null|string $me_qualificacao
 * @property null|int $cd_imagem
 * @property null|string $ds_seguimento
 * @property null|string $ds_marca
 * @property null|string $ds_unidade
 * @property null|string $ds_turma
 * @property null|Carbon $dt_cadastro
 * @property null|Carbon $dt_base
 * @property null|Carbon $dt_excluido
 * @property null|UnimPessoaFisica $fisica
 * @property null|UnimPessoaJuridica $juridica
 */
class UnimPessoa extends Model
{
    use SoftDeletes;

    public const DELETED_AT = 'dt_excluido';

    public bool $timestamps = false;

    protected ?string $table = 'unim_pessoa';

    protected string $primaryKey = 'cd_pessoa';

    /**
     * @var string[]
     */
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

    /**
     * @var string[]
     */
    protected array $hidden = ['ds_senha'];

    /**
     * @var array<string, string>
     */
    protected array $casts = [
        'sn_pessoa_juridica' => 'boolean',
        'dt_cadastro' => 'datetime',
        'dt_base' => 'datetime',
        'dt_excluido' => 'datetime',
    ];

    /**
     * @return HasOne<UnimPessoaFisica, $this>
     */
    public function fisica(): HasOne
    {
        return $this->hasOne(UnimPessoaFisica::class, 'cd_pessoa', 'cd_pessoa');
    }

    /**
     * @return HasOne<UnimPessoaJuridica, $this>
     */
    public function juridica(): HasOne
    {
        return $this->hasOne(UnimPessoaJuridica::class, 'cd_pessoa', 'cd_pessoa');
    }
}
