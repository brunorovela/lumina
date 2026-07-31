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
    schema: 'ErroValidacao',
    description: 'Envelope de erro 422, com uma lista de mensagens por campo rejeitado. '
        . 'Um campo inexistente em ?fields= e um campo não permitido (ds_senha) recebem a MESMA mensagem, '
        . 'de propósito: a resposta não revela quais colunas existem no banco.',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'message', type: 'string', example: 'Dados inválidos.'),
        new OA\Property(
            property: 'errors',
            description: 'Chave = nome do campo rejeitado; valor = lista de mensagens.',
            type: 'object',
            example: [
                'fields' => ['Campo não permitido: ds_nomee.'],
                'ds_login' => ['The ds login field is required.'],
            ],
            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string'))
        ),
    ],
    type: 'object'
)]
final class ErroValidacaoSchema
{
}
