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
    schema: 'PessoaResumida',
    description: 'O que GET /pessoas devolve por padrão, sem ?fields=: apenas estes quatro campos, '
        . 'e nenhuma relação (o que também poupa duas consultas por página). '
        . 'Para receber mais, peça explicitamente — `?fields=*` devolve o registro completo.',
    properties: [
        new OA\Property(property: 'cd_pessoa', type: 'integer', example: 1512099),
        new OA\Property(property: 'ds_nome', type: 'string', example: 'Ana Souza', nullable: true),
        new OA\Property(property: 'ds_login', type: 'string', example: 'ana.souza', nullable: true),
        new OA\Property(property: 'sn_pessoa_juridica', type: 'boolean', example: false, nullable: true),
    ],
    type: 'object'
)]
final class PessoaResumidaSchema
{
}
