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
    schema: 'Pais',
    description: 'País do catálogo global saas_pais. Catálogo NÃO tem escopo de cliente: '
        . 'a mesma lista vale para todos os tenants, diferente de tudo em /pessoas.',
    properties: [
        new OA\Property(property: 'cd_pais', description: 'Identificador do país, usado em cd_pais e cd_pais_nascimento do endereço.', type: 'integer', example: 1),
        new OA\Property(property: 'ds_pais', type: 'string', example: 'Brasil', nullable: true),
        new OA\Property(property: 'ds_nacionalidade', type: 'string', example: 'Brasileira', nullable: true),
    ],
    type: 'object'
)]
final class PaisSchema
{
}
