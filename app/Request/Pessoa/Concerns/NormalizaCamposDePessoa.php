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

namespace App\Request\Pessoa\Concerns;

use App\Support\Documento;
use App\Support\Tipo;

/**
 * Normaliza o payload ANTES de as regras rodarem, sobrepondo validationData().
 *
 * A ordem importa: `ds_cpf` com máscara ("123.456.789-09") reprovaria em digits:11 se
 * chegasse cru às regras. Normalizando antes, a regra vê só dígitos e validated() já
 * devolve o valor que vai para o banco.
 *
 * Consequência para quem consome: a resposta traz o valor normalizado, não o enviado.
 * Ainda não documentado no Swagger dos endpoints de escrita -- essa tarefa (Task 7) não
 * toca `#[OA\...]` de propósito; documentar fica para a Task 9.
 */
trait NormalizaCamposDePessoa
{
    /**
     * Documentos guardados só com dígitos: máscara na coluna quebraria busca por CPF e
     * deixaria a mesma pessoa gravada de duas formas.
     *
     * @var string[]
     */
    private const CAMPOS_SO_DIGITOS = ['ds_cpf', 'ds_cnpj'];

    /**
     * Só nestes campos string vazia vira null. São os dez campos novos de pessoa física
     * que esta feature introduziu (o mesmo conjunto das dez regras em CreatePessoaRequest,
     * de ds_nome_social a cd_estado_civil) -- não qualquer `ds_*`.
     *
     * Campos pré-existentes (ds_nome, ds_login, ds_senha, ds_nome_oficial, ds_cpf,
     * ds_cnpj, ds_nome_fantasia) ficam de fora de propósito: nem todos têm `nullable` na
     * regra, e uma regra sem `nullable` roda contra `null` normalmente (Validator não pula
     * por causa de `null` sem essa marca) -- então converter "" pra null neles faria
     * `digits:14`/`string`/`required_if` reprovar um valor que hoje passa batido nas
     * regras implícitas do Laravel/Hyperf para string vazia. Isso mudaria contrato de
     * campo que já existia antes desta tarefa (Important 3 da revisão da Task 7);
     * restrito à lista explícita, o contrato antigo não muda.
     *
     * @var string[]
     */
    private const CAMPOS_VAZIO_VIRA_NULO = [
        'ds_nome_social',
        'ds_nome_mae',
        'ds_nome_pai',
        'ds_identidade',
        'ds_orgao_estado',
        'ds_identidade_orgao_exp',
        'dt_identidade_expedicao',
        'dt_nascimento',
        'ds_sexo',
        'cd_estado_civil',
    ];

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    protected function normalizarCamposDePessoa(array $dados): array
    {
        foreach (self::CAMPOS_SO_DIGITOS as $campo) {
            // is_scalar(), não is_string(): um inteiro sem aspas no JSON ("ds_cpf":
            // 12345678900) tem de ser normalizado igual a uma string, senão o valor
            // gravado nunca passa pela limpeza de máscara/tipo (Critical 1 da revisão da
            // Task 7 -- o mesmo raciocínio de ValidaDocumentosDePessoa).
            if (isset($dados[$campo]) && is_scalar($dados[$campo])) {
                $dados[$campo] = Documento::apenasDigitos(Tipo::texto($dados[$campo]));
            }
        }

        // O legado gravou 'f' e 'm' minúsculos (291k e 218k linhas). Aceitar 'F' e baixar
        // é mais gentil que recusar, e mantém a coluna homogênea.
        if (isset($dados['ds_sexo']) && is_string($dados['ds_sexo'])) {
            $dados['ds_sexo'] = strtolower(trim($dados['ds_sexo']));
        }

        // String vazia é ausência de dado, não dado vazio -- mas só nos campos novos desta
        // feature (ver CAMPOS_VAZIO_VIRA_NULO). O legado tem 27k linhas com '' em ds_sexo
        // justamente por não fazer isto.
        foreach (self::CAMPOS_VAZIO_VIRA_NULO as $campo) {
            if (($dados[$campo] ?? null) === '') {
                $dados[$campo] = null;
            }
        }

        return $dados;
    }
}
