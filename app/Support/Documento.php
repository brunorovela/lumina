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

namespace App\Support;

/**
 * Dígito verificador de CPF e CNPJ, e remoção de máscara.
 *
 * Recebe valor já sem máscara nos dois validadores: quem normaliza é
 * validationData() no FormRequest, antes de as regras rodarem. Sequência de dígito
 * repetido é rejeitada à parte porque fecha na aritmética do DV ("11111111111" é
 * aritmeticamente válido) e não é documento.
 *
 * O legado tem CPF com DV inválido gravado. Isto vale só para escrita nova — leitura
 * devolve o que está no banco, sem validar.
 */
final class Documento
{
    public static function apenasDigitos(string $valor): string
    {
        return preg_replace('/\D/', '', $valor) ?? '';
    }

    public static function cpfEhValido(string $cpf): bool
    {
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        foreach ([9, 10] as $posicao) {
            $soma = 0;

            for ($i = 0; $i < $posicao; ++$i) {
                $soma += (int) $cpf[$i] * ($posicao + 1 - $i);
            }

            if ((int) $cpf[$posicao] !== self::digito($soma)) {
                return false;
            }
        }

        return true;
    }

    public static function cnpjEhValido(string $cnpj): bool
    {
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        // Pesos do CNPJ: 5..2 seguido de 9..2 para o primeiro DV; o segundo repete a
        // sequência com um peso a mais na frente.
        $pesosPrimeiro = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $pesosSegundo = [6, ...$pesosPrimeiro];

        foreach ([[12, $pesosPrimeiro], [13, $pesosSegundo]] as [$posicao, $pesos]) {
            $soma = 0;

            foreach ($pesos as $i => $peso) {
                $soma += (int) $cnpj[$i] * $peso;
            }

            if ((int) $cnpj[$posicao] !== self::digito($soma)) {
                return false;
            }
        }

        return true;
    }

    private static function digito(int $soma): int
    {
        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }
}
