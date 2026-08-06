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
    schema: 'Erro',
    description: 'Envelope de erro sem detalhe por campo. Montado por App\Support\ApiResponse::erro().',
    properties: [
        new OA\Property(property: 'success', description: 'Sempre false neste envelope.', type: 'boolean', example: false),
        new OA\Property(property: 'message', description: 'Mensagem pronta para exibição.', type: 'string', example: 'Sem permissão para esta ação.'),
    ],
    type: 'object'
)]
final class ErroSchema
{
}
