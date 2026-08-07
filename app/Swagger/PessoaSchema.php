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
    description: 'Forma de um registro de pessoa — as colunas de unim_pessoa e nada mais. Usado por GET /pessoas/{id} '
        . '(sujeito a ?fields=) e pelas respostas de POST/PUT/PATCH (sempre completas, porque escrita ignora ?fields=). '
        . 'Quais chaves aparecem de fato no detalhe depende de ?fields=: pedir `fields=ds_nome` devolve só ds_nome. '
        . 'MUDANÇA DE CONTRATO: as propriedades `fisica` e `juridica` não existem mais — pessoa física '
        . '(unim_pessoa_fisica, onde estão CPF, RG, filiação e nascimento) e pessoa jurídica (unim_pessoa_juridica, '
        . 'CNPJ e nome fantasia) são recursos próprios, e /pessoas não lê nem escreve essas tabelas. Pedi-las em '
        . '?fields= responde 422.',
    properties: [
        new OA\Property(property: 'cd_pessoa', description: 'Identificador da pessoa. É o :id de GET/PUT/PATCH/DELETE /pessoas/{id}.', type: 'integer', example: 1512099),
        new OA\Property(property: 'cd_cliente', description: 'Cliente (tenant) dono do registro. Vem sempre da identidade autenticada, nunca do payload.', type: 'integer', example: 20),
        new OA\Property(property: 'ds_nome', description: 'Nome de exibição.', type: 'string', example: 'Ana Souza', nullable: true),
        new OA\Property(property: 'ds_login', description: 'Login, único por cliente.', type: 'string', example: 'ana.souza', nullable: true),
        new OA\Property(
            property: 'sn_pessoa_juridica',
            description: 'false = pessoa física, true = pessoa jurídica. É só a declaração de tipo gravada em '
                . 'unim_pessoa: não garante que exista (nem que não exista) registro em unim_pessoa_fisica ou '
                . 'unim_pessoa_juridica, porque esta API não mexe nessas tabelas.',
            type: 'boolean',
            example: false,
            nullable: true
        ),
    ],
    type: 'object'
)]
final class PessoaSchema
{
}
