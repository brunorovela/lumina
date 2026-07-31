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
    schema: 'MetaPaginacao',
    description: 'Metadados de paginação. per_page reflete o valor EFETIVO usado na consulta, '
        . 'já limitado a 100 — pedir per_page=500 devolve 100 aqui, não 500.',
    properties: [
        new OA\Property(property: 'total', description: 'Total de registros que casam o filtro, ignorando a paginação.', type: 'integer', example: 137),
        new OA\Property(property: 'per_page', description: 'Tamanho de página efetivo (máximo 100).', type: 'integer', example: 20),
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'last_page', type: 'integer', example: 7),
    ],
    type: 'object'
)]
final class MetaPaginacaoSchema
{
}
