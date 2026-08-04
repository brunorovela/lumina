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

/**
 * Normaliza o payload ANTES de as regras rodarem, sobrepondo validationData().
 *
 * A ordem importa: `ds_cpf` com máscara ("123.456.789-09") reprovaria em digits:11 se
 * chegasse cru às regras. Normalizando antes, a regra vê só dígitos e validated() já
 * devolve o valor que vai para o banco.
 *
 * Consequência para quem consome: a resposta traz o valor normalizado, não o enviado.
 * Está dito no Swagger dos três endpoints de escrita.
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
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    protected function normalizarCamposDePessoa(array $dados): array
    {
        foreach (self::CAMPOS_SO_DIGITOS as $campo) {
            if (isset($dados[$campo]) && is_string($dados[$campo])) {
                $dados[$campo] = Documento::apenasDigitos($dados[$campo]);
            }
        }

        // O legado gravou 'f' e 'm' minúsculos (291k e 218k linhas). Aceitar 'F' e baixar
        // é mais gentil que recusar, e mantém a coluna homogênea.
        if (isset($dados['ds_sexo']) && is_string($dados['ds_sexo'])) {
            $dados['ds_sexo'] = strtolower(trim($dados['ds_sexo']));
        }

        // String vazia é ausência de dado, não dado vazio: a coluna é nullable e o legado
        // tem 27k linhas com '' em ds_sexo justamente por não fazer isto.
        foreach ($dados as $campo => $valor) {
            if ($valor === '') {
                $dados[$campo] = null;
            }
        }

        return $dados;
    }
}
