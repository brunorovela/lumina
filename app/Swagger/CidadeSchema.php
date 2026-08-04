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
    schema: 'Cidade',
    description: 'Cidade do catálogo global saas_cidade. Sem escopo de cliente. '
        . 'A tabela tem quase 5 mil linhas, por isso a rota exige cd_estado e nunca devolve o catálogo inteiro.',
    properties: [
        new OA\Property(property: 'cd_cidade', description: 'Identificador da cidade, usado em cd_cidade e cd_cidade_nascimento do endereço.', type: 'integer', example: 5270),
        new OA\Property(property: 'cd_estado', type: 'integer', example: 26),
        new OA\Property(property: 'ds_cidade', type: 'string', example: 'São Paulo', nullable: true),
    ],
    type: 'object'
)]
final class CidadeSchema
{
}
