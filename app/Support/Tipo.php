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

use InvalidArgumentException;
use Stringable;

/**
 * Coerção explícita de mixed para escalar.
 *
 * Payload validado, query string e coluna de banco chegam como mixed no PHP: o
 * FormRequest devolve array<string, mixed> mesmo quando a regra garante `string`, e
 * `(string) $mixed` / `(int) $mixed` são conversões que o phpstan (nível 10) acusa com
 * razão — em cima de um array elas produzem "Array" ou 0 em silêncio.
 *
 * Estes helpers existem para dizer, num lugar só, o que fazer com valor fora do formato
 * esperado: número/texto convertem, ausente cai no padrão, e estrutura (array/objeto sem
 * __toString) estoura em vez de virar lixo silencioso.
 */
final class Tipo
{
    public static function texto(mixed $valor, string $padrao = ''): string
    {
        return match (true) {
            is_string($valor) => $valor,
            $valor === null => $padrao,
            is_int($valor), is_float($valor) => (string) $valor,
            is_bool($valor) => $valor ? '1' : '',
            $valor instanceof Stringable => (string) $valor,
            default => throw new InvalidArgumentException('Valor não é convertível para texto.'),
        };
    }

    public static function inteiro(mixed $valor, int $padrao = 0): int
    {
        return match (true) {
            is_int($valor) => $valor,
            $valor === null => $padrao,
            is_float($valor) => (int) $valor,
            is_bool($valor) => $valor ? 1 : 0,
            is_string($valor) => is_numeric($valor) ? (int) $valor : $padrao,
            default => throw new InvalidArgumentException('Valor não é convertível para inteiro.'),
        };
    }

    /**
     * Normaliza um array de chaves quaisquer para array<string, mixed>. FormRequest e
     * json_decode() devolvem `array` sem tipo de chave; sem isso, passar o resultado para
     * um parâmetro array<string, mixed> é erro de tipo no nível 10.
     *
     * @return array<string, mixed>
     */
    public static function mapa(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }

        $mapa = [];

        foreach ($valor as $chave => $item) {
            $mapa[(string) $chave] = $item;
        }

        return $mapa;
    }

    public static function booleano(mixed $valor): bool
    {
        return match (true) {
            is_bool($valor) => $valor,
            $valor === null => false,
            is_int($valor), is_float($valor) => $valor != 0,
            is_string($valor) => ! in_array(strtolower($valor), ['', '0', 'false', 'no'], true),
            default => throw new InvalidArgumentException('Valor não é convertível para booleano.'),
        };
    }
}
