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
    schema: 'Estado',
    description: 'Estado (unidade federativa) do catálogo global saas_estado. Sem escopo de cliente.',
    properties: [
        new OA\Property(property: 'cd_estado', description: 'Identificador do estado, usado em cd_estado e cd_estado_nascimento do endereço.', type: 'integer', example: 26),
        new OA\Property(property: 'cd_pais', type: 'integer', example: 1),
        new OA\Property(property: 'ds_estado', type: 'string', example: 'São Paulo', nullable: true),
        new OA\Property(property: 'ds_uf', type: 'string', example: 'SP', nullable: true),
    ],
    type: 'object'
)]
final class EstadoSchema
{
}
