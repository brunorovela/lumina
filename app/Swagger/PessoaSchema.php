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
    description: 'Forma de um registro de pessoa. Usado tanto por GET /pessoas/{id} (sujeito a ?fields=, e SEM dado '
        . 'pessoal no default — ver a propriedade fisica) quanto pelas respostas de POST/PUT/PATCH (sempre completas, '
        . 'dado pessoal incluso, porque escrita ignora ?fields=). '
        . 'Quais chaves aparecem de fato no detalhe depende de ?fields=: pedir `fields=ds_nome` devolve só ds_nome. '
        . 'Uma chave de relação (fisica/juridica) só aparece se foi pedida, e vem null quando a pessoa é do outro tipo.',
    properties: [
        new OA\Property(property: 'cd_pessoa', description: 'Identificador da pessoa.', type: 'integer', example: 1512099),
        new OA\Property(property: 'cd_cliente', description: 'Cliente (tenant) dono do registro. Vem sempre da identidade autenticada, nunca do payload.', type: 'integer', example: 20),
        new OA\Property(property: 'ds_nome', description: 'Nome de exibição.', type: 'string', example: 'Ana Souza', nullable: true),
        new OA\Property(property: 'ds_login', description: 'Login, único por cliente.', type: 'string', example: 'ana.souza', nullable: true),
        new OA\Property(property: 'sn_pessoa_juridica', description: 'false = pessoa física, true = pessoa jurídica.', type: 'boolean', example: false, nullable: true),
        new OA\Property(
            property: 'fisica',
            description: 'Dados de pessoa física. null quando a pessoa é jurídica. '
                . 'ATENÇÃO: ds_cpf, ds_identidade, ds_nome_mae, ds_nome_pai e dt_nascimento são dado pessoal e '
                . 'NÃO vêm no default de GET /pessoas/{id} — só aparecem se pedidos por nome (fields=fisica.ds_cpf) '
                . 'ou por curinga (fields=fisica.* ou fields=*). Resposta de POST/PUT/PATCH traz todos.',
            properties: [
                new OA\Property(property: 'ds_nome_oficial', description: 'Nome em documento. Obrigatório ao criar pessoa física.', type: 'string', example: 'Ana Souza'),
                new OA\Property(property: 'ds_nome_social', type: 'string', example: 'Ana', nullable: true),
                new OA\Property(property: 'ds_nome_mae', description: 'Dado pessoal: fora do default, só com fields explícito.', type: 'string', example: 'Maria Souza', nullable: true),
                new OA\Property(property: 'ds_nome_pai', description: 'Dado pessoal: fora do default, só com fields explícito.', type: 'string', example: 'Jose Souza', nullable: true),
                new OA\Property(property: 'ds_cpf', description: 'Dado pessoal: fora do default, só com fields explícito. Gravado e devolvido SEM máscara, mesmo que enviado com (ou como número JSON sem aspas).', type: 'string', example: '52998224725', nullable: true),
                new OA\Property(property: 'ds_identidade', description: 'Dado pessoal: fora do default, só com fields explícito.', type: 'string', example: '123456789', nullable: true),
                new OA\Property(property: 'ds_orgao_estado', description: 'UF do órgão expedidor da identidade.', type: 'string', example: 'SP', nullable: true),
                new OA\Property(property: 'ds_identidade_orgao_exp', description: 'Órgão expedidor da identidade.', type: 'string', example: 'SSP', nullable: true),
                new OA\Property(property: 'dt_identidade_expedicao', description: 'Data no formato Y-m-d. Não pode ser futura nem anterior a dt_nascimento quando as duas vêm no mesmo payload.', type: 'string', format: 'date', example: '2015-03-01', nullable: true),
                new OA\Property(property: 'dt_nascimento', description: 'Data no formato Y-m-d, não pode ser futura. Dado pessoal: fora do default, só com fields explícito.', type: 'string', format: 'date', example: '1990-05-12', nullable: true),
                new OA\Property(property: 'ds_sexo', description: 'Na escrita aceita apenas f, m ou null (F e M são aceitos e gravados em minúsculo). A LEITURA pode devolver outros valores: o banco legado tem dado fora desse domínio e a API não mente sobre o que está gravado.', type: 'string', enum: ['f', 'm'], example: 'f', nullable: true),
                new OA\Property(property: 'cd_estado_civil', description: 'Código de saas_estado_civil. Traduza o rótulo em GET /estados-civis — a leitura de pessoa devolve o código, não o nome.', type: 'integer', example: 37, nullable: true),
            ],
            type: 'object',
            nullable: true
        ),
        new OA\Property(
            property: 'juridica',
            description: 'Dados de pessoa jurídica. null quando a pessoa é física.',
            properties: [
                new OA\Property(property: 'ds_cnpj', description: 'Gravado e devolvido SEM máscara, mesmo que enviado com (ou como número JSON sem aspas).', type: 'string', example: '00000000000191'),
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
