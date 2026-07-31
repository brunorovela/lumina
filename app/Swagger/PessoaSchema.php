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
 * Classe só de documentação: existe para declarar o schema reutilizável de pessoa em
 * components. Não tem comportamento e nada a instancia.
 *
 * MANUTENÇÃO: campo novo em App\Resource\Pessoa\MapaDeCamposPessoa entra aqui também.
 */
#[OA\Schema(
    schema: 'Pessoa',
    description: 'Registro completo de pessoa, como GET /pessoas/{id} devolve por padrão. '
        . 'Quais chaves aparecem de fato depende de ?fields=: pedir `fields=ds_nome` devolve só ds_nome. '
        . 'Uma chave de relação (fisica/juridica) só aparece se foi pedida, e vem null quando a pessoa é do outro tipo.',
    properties: [
        new OA\Property(property: 'cd_pessoa', description: 'Identificador da pessoa.', type: 'integer', example: 1512099),
        new OA\Property(property: 'cd_cliente', description: 'Cliente (tenant) dono do registro. Vem sempre da identidade autenticada, nunca do payload.', type: 'integer', example: 20),
        new OA\Property(property: 'ds_nome', description: 'Nome de exibição.', type: 'string', example: 'Ana Souza', nullable: true),
        new OA\Property(property: 'ds_login', description: 'Login, único por cliente.', type: 'string', example: 'ana.souza', nullable: true),
        new OA\Property(property: 'sn_pessoa_juridica', description: 'false = pessoa física, true = pessoa jurídica.', type: 'boolean', example: false, nullable: true),
        new OA\Property(
            property: 'fisica',
            description: 'Dados de pessoa física. null quando a pessoa é jurídica.',
            properties: [
                new OA\Property(property: 'ds_nome_oficial', type: 'string', example: 'Ana Souza'),
                new OA\Property(property: 'ds_cpf', type: 'string', example: '12345678901', nullable: true),
            ],
            type: 'object',
            nullable: true
        ),
        new OA\Property(
            property: 'juridica',
            description: 'Dados de pessoa jurídica. null quando a pessoa é física.',
            properties: [
                new OA\Property(property: 'ds_cnpj', type: 'string', example: '00000000000191'),
                new OA\Property(property: 'ds_nome_fantasia', type: 'string', example: 'ACME Servicos'),
            ],
            type: 'object',
            nullable: true
        ),
    ],
    type: 'object'
)]
final class PessoaSchema
{
}
