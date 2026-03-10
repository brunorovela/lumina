<?php
declare(strict_types=1);

namespace App\Model;

// O Hyperf já vem com uma classe Model abstrata na pasta app/Model
class Pessoa extends Model
{
    protected ?string $table = 'unim_pessoa';
    protected string $primaryKey = 'cd_pessoa';
    public bool $timestamps = false;

    protected array $fillable = ['cd_cliente', 'ds_nome', 'ds_login', 'ds_senha'];
}