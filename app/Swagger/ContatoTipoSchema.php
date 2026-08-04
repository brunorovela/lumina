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
    schema: 'ContatoTipo',
    description: 'Tipo de contato do catálogo global unim_pessoa_contato_tipo. As chaves são '
        . 'as do LMS: TELEFONE, TELEFONE-COMERCIAL, TELEFONE-CELULAR, EMAIL, SITE.',
    properties: [
        new OA\Property(property: 'cd_tipo', type: 'integer', example: 34),
        new OA\Property(property: 'ds_descricao', type: 'string', example: 'E-mail'),
        new OA\Property(property: 'ds_chave', type: 'string', example: 'EMAIL'),
    ],
    type: 'object'
)]
final class ContatoTipoSchema
{
}
