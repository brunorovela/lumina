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
 * Espelha ulms_privilegio.ds_chave — as chaves são as MESMAS usadas pelo LMS atual
 * (Lms\Enum\UlmsPrivilegio). Repare que não existe "listar" nem "visualizar":
 * leitura (listagem e detalhe) usa ACESSAR, como em
 * Admin\Controller\GerenciarPessoaController::listarAction().
 *
 * Parity com o banco é coberta por HyperfTest\Cases\Enum\AclEnumParidadeTest.
 */
enum Privilegio: string
{
    case ACESSAR = 'ACESSAR';
    case INSERIR = 'INSERIR';
    case ATUALIZAR = 'ATUALIZAR';
    case DELETAR = 'DELETAR';
    case COPIAR = 'COPIAR';
    case SALVAR_NOTA = 'SALVAR_NOTA';
    case SALVAR_FEEDBACK = 'SALVAR_FEEDBACK';
    case SALVAR_TENTATIVA_EXTRA = 'SALVAR_TENTATIVA_EXTRA';
    case VISUALIZAR_RESPOSTA = 'VISUALIZAR_RESPOSTA';
    case INCLUIR_CURSO_MANUALMENTE = 'INCLUIR_CURSO_MANUALMENTE';
    case INCLUIR_MATRICULA_MANUALMENTE = 'INCLUIR_MATRICULA_MANUALMENTE';
    case VISUALIZAR_TODAS_PESSOAS_BUSCA = 'VISUALIZAR_TODAS_PESSOAS_BUSCA';
    case ATRIBUIR_NOTA = 'ATRIBUIR_NOTA';
}
