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
 * A ordem importa: `ds_cpf` com máscara ("123.456.789-09") reprovaria na regra de formato
 * se chegasse cru às regras. Normalizando antes, a regra vê só dígitos e validated() já
 * devolve o valor que vai para o banco.
 *
 * Consequência para quem consome: a resposta traz o valor normalizado, não o enviado.
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
     * que esta feature introduziu (de ds_nome_social a cd_estado_civil) mais ds_cpf --
     * não qualquer `ds_*`.
     *
     * ds_cpf entra porque tem `nullable` nas três classes: "" vira null e a regra de
     * formato nem chega a rodar, igual aos outros dez. ds_cnpj fica de fora de propósito:
     * não tem `nullable`, e a regra `required_if` precisa VER a string vazia para reprovar
     * corretamente uma pessoa jurídica sem CNPJ -- convertê-la para null antes faria
     * `required_if` reprovar pelo motivo errado (campo ausente) e, pior, deixaria a mesma
     * validação passar batido para pessoa física (onde CNPJ nunca é obrigatório). Os
     * demais campos pré-existentes (ds_nome, ds_login, ds_senha, ds_nome_oficial,
     * ds_nome_fantasia) ficam de fora porque não têm `nullable` e são obrigatórios em pelo
     * menos um verbo -- esta lista não é o lugar para mudar esse contrato antigo.
     *
     * @var string[]
     */
    private const CAMPOS_VAZIO_VIRA_NULO = [
        'ds_cpf',
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
            // gravado nunca passa pela limpeza de máscara/tipo -- mesmo raciocínio de
            // ValidaDocumentosDePessoa.
            if (isset($dados[$campo]) && is_scalar($dados[$campo])) {
                $textoOriginal = Tipo::texto($dados[$campo]);
                $soDigitos = Documento::apenasDigitos($textoOriginal);

                // Não sobrescreve com "" quando a entrada NÃO era vazia: "abc" tem de
                // continuar "abc" para a regra de formato reprovar com 422. Se a limpeza
                // sobrescrevesse para "", o campo (nullable em ds_cpf) seria tratado como
                // ausência e a regra de formato nunca rodaria -- entrada não numérica
                // aceita em silêncio.
                $dados[$campo] = ($textoOriginal !== '' && $soDigitos === '') ? $textoOriginal : $soDigitos;
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
