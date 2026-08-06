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

namespace App\Swagger;

use Hyperf\Swagger\Annotation as OA;

/**
 * Classe só de documentação. Ver App\Swagger\PessoaSchema.
 */
#[OA\Schema(
    schema: 'EstadoCivil',
    description: 'Estado civil do catálogo global saas_estado_civil. É o destino da FK '
        . 'de fisica.cd_estado_civil: use esta rota para traduzir o código, porque a leitura '
        . 'de pessoa devolve o código e não o rótulo.',
    properties: [
        new OA\Property(property: 'cd_estado_civil', description: 'Código em saas_estado_civil. É o valor gravado em fisica.cd_estado_civil.', type: 'integer', example: 37),
        new OA\Property(property: 'ds_estado_civil', type: 'string', example: 'Solteiro(a)', nullable: true),
    ],
    type: 'object'
)]
final class EstadoCivilSchema
{
}
