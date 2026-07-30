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

namespace App\Support\Campos;

/**
 * Um campo exposto pela API e para onde ele aponta no banco.
 *
 * Coluna direta: Campo::coluna('ds_nome').
 * Coluna de relação: Campo::relacao('fisica', 'ds_cpf', 'cd_pessoa') — a chave estrangeira
 * é obrigatória porque sem ela o eager load parcial não casa pai e filho, e o Eloquent
 * falha em silêncio nesse caso (a relação vem null, sem erro).
 */
final class Campo
{
    private function __construct(
        public readonly string $coluna,
        public readonly ?string $relacao,
        public readonly ?string $chaveEstrangeira,
        public readonly bool $noPadrao,
    ) {
    }

    public static function coluna(string $coluna, bool $noPadrao = false): self
    {
        return new self($coluna, null, null, $noPadrao);
    }

    public static function relacao(
        string $relacao,
        string $coluna,
        string $chaveEstrangeira,
        bool $noPadrao = false
    ): self {
        return new self($coluna, $relacao, $chaveEstrangeira, $noPadrao);
    }

    public function ehDeRelacao(): bool
    {
        return $this->relacao !== null;
    }
}
