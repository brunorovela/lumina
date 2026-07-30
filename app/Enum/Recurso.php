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

namespace App\Enum;

/**
 * Espelha ulms_recurso.ds_chave — as chaves são as MESMAS usadas pelo LMS atual
 * (Lms\Enum\UlmsRecurso). Não invente chaves aqui: o ACL resolve permissão por
 * ds_chave, então uma chave que não existe na tabela nega tudo silenciosamente.
 *
 * Parity com o banco é coberta por HyperfTest\Cases\Enum\AclEnumParidadeTest.
 */
enum Recurso: string
{
    case GERENCIAR_ADMINISTRADOR = 'GERENCIAR_ADMINISTRADOR';
    case GERENCIAR_CURSO = 'GERENCIAR_CURSO';
    case GERENCIAR_CONTEUDO = 'GERENCIAR_CONTEUDO';
    case GERENCIAR_TOPICO = 'GERENCIAR_TOPICO';
    case GERENCIAR_TURMA = 'GERENCIAR_TURMA';
    case GERENCIAR_CHAT = 'GERENCIAR_CHAT';
    case GERENCIAR_FORUM = 'GERENCIAR_FORUM';
    case GERENCIAR_BANCO_QUESTAO = 'GERENCIAR_BANCO_QUESTAO';
    case GERENCIAR_MATRICULA = 'GERENCIAR_MATRICULA';
    case GERENCIAR_PERMISSAO = 'GERENCIAR_PERMISSAO';
    case GERENCIAR_ACOMPANHAMENTO = 'GERENCIAR_ACOMPANHAMENTO';
    case GERENCIAR_CORRECAO_PROVA = 'GERENCIAR_CORRECAO_PROVA';
    case GERENCIAR_CORRECAO_TRABALHO = 'GERENCIAR_CORRECAO_TRABALHO';
    case GERENCIAR_PESSOA = 'GERENCIAR_PESSOA';
    case GERENCIAR_PROVA_TRABALHO = 'GERENCIAR_PROVA_TRABALHO';
}
