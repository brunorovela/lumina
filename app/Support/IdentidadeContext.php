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

use Hyperf\Context\Context;
use LogicException;

final class IdentidadeContext
{
    private const CHAVE = 'identidade.autenticada';

    /**
     * @param array<string, mixed> $identidade
     */
    public static function set(array $identidade): void
    {
        Context::set(self::CHAVE, $identidade);
    }

    /**
     * @return null|array<string, mixed>
     */
    public static function get(): ?array
    {
        $identidade = Context::get(self::CHAVE);

        // Context::get() é mixed (guarda qualquer coisa por chave string). Sem este
        // is_array, todo acessor abaixo trabalha em cima de mixed.
        if (! is_array($identidade)) {
            return null;
        }

        $normalizado = [];

        foreach ($identidade as $chave => $valor) {
            $normalizado[(string) $chave] = $valor;
        }

        return $normalizado;
    }

    public static function cdCliente(): int
    {
        return self::inteiro('cd_cliente');
    }

    /**
     * @return int[]
     */
    public static function cdPerfis(): array
    {
        $cdPerfis = self::get()['cd_perfis'] ?? [];

        if (! is_array($cdPerfis)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $cdPerfil): int => (int) self::escalar($cdPerfil), $cdPerfis));
    }

    public static function cdPessoa(): int
    {
        return self::inteiro('cd_pessoa');
    }

    /**
     * Antes era `(int) self::get()['cd_cliente']`: sem identidade no contexto isso virava
     * warning de offset em null e devolvia 0 — ou seja, um vazamento silencioso para
     * "cliente 0" em vez de erro. Estes acessores só são chamados depois do
     * AuthMiddleware, então a ausência da chave é bug de programação, não entrada de
     * usuário: falha alto.
     */
    private static function inteiro(string $chave): int
    {
        $identidade = self::get();

        if ($identidade === null || ! array_key_exists($chave, $identidade)) {
            throw new LogicException("Identidade autenticada não tem '{$chave}' no contexto.");
        }

        return (int) self::escalar($identidade[$chave]);
    }

    private static function escalar(mixed $valor): float|int|string
    {
        if (! is_int($valor) && ! is_string($valor) && ! is_float($valor)) {
            throw new LogicException('Valor de identidade não é numérico nem string.');
        }

        return $valor;
    }
}
