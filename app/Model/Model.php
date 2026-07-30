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

namespace App\Model;

use Hyperf\DbConnection\Model\Model as BaseModel;

/**
 * Hyperf resolve create()/updateOrCreate()/where() por __callStatic, então o phpstan não
 * as enxerga: sem estas declarações ele acusa staticMethod.notFound e, pior, trata o
 * retorno como mixed — o que contamina toda a cadeia depois (->first(), ->delete(),
 * ->fresh() viram "método em mixed"). Declarar uma vez na base cobre todos os models.
 *
 * Só entram aqui os estáticos realmente usados no projeto. with() e withTrashed() já vêm
 * anotados pelo próprio Hyperf, não precisam de shim.
 *
 * @method static static create(array<string, mixed> $attributes = [])
 * @method static static updateOrCreate(array<string, mixed> $attributes, array<string, mixed> $values = [])
 * @method static \Hyperf\Database\Model\Builder<static> where(array<mixed>|\Closure|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
 */
abstract class Model extends BaseModel
{
}
