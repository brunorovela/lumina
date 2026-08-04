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

use LogicException;

/**
 * Traduz o parâmetro `fields` da query string em três coisas diferentes: o que devolver na
 * resposta (campos()), o que pedir no SELECT do pai (colunas()) e quais relações carregar
 * com quais colunas (relacoes()).
 *
 * colunas() e relacoes() podem conter mais do que campos(): a chave local e a chave
 * estrangeira entram no SQL por necessidade do eager load e são removidas da resposta,
 * para o contrato não vazar detalhe do ORM.
 */
final class SelecaoDeCampos
{
    /**
     * @param array<string, Campo> $mapa
     * @param string[] $campos
     */
    private function __construct(
        private array $mapa,
        private array $campos,
        private string $chaveLocal,
        private bool $tudo,
    ) {
    }

    /**
     * @param array<string, Campo> $mapa
     * @param bool $padraoEhTudo quando `fields` está ausente, define se o default é o mapa
     *                           inteiro (item) ou só os campos marcados noPadrao (lista)
     */
    public static function de(
        ?string $fields,
        array $mapa,
        string $chaveLocal,
        bool $padraoEhTudo = false
    ): self {
        $tokens = self::tokens($fields);

        if ($tokens === []) {
            // padraoEhTudo é o default do ITEM, e default não é pedido: campo sensível fica
            // fora. Pedir por nome ou por curinga é pedido explícito e traz. Resposta de
            // escrita usa completa(), que ignora essa distinção.
            return $padraoEhTudo
                ? new self($mapa, self::naoSensiveis($mapa), $chaveLocal, true)
                : new self($mapa, self::doPadrao($mapa), $chaveLocal, false);
        }

        if (in_array('*', $tokens, true)) {
            return self::completa($mapa, $chaveLocal);
        }

        $campos = [];

        foreach ($tokens as $token) {
            foreach (self::expandir($token, $mapa) as $campo) {
                $campos[$campo] = true;
            }
        }

        return new self($mapa, array_keys($campos), $chaveLocal, false);
    }

    /**
     * Mapa inteiro, campo sensível incluso. É o que a resposta de POST/PUT/PATCH usa:
     * filtrar a resposta de escrita esconderia o que o servidor acabou de gravar.
     *
     * @param array<string, Campo> $mapa
     */
    public static function completa(array $mapa, string $chaveLocal): self
    {
        return new self($mapa, array_keys($mapa), $chaveLocal, true);
    }

    /**
     * Tokens que não existem no mapa. Curinga válido não é reportado; curinga de relação
     * inexistente é.
     *
     * @param array<string, Campo> $mapa
     *
     * @return string[]
     */
    public static function invalidos(?string $fields, array $mapa): array
    {
        $invalidos = [];

        foreach (self::tokens($fields) as $token) {
            if ($token === '*' || isset($mapa[$token]) || self::expandir($token, $mapa) !== []) {
                continue;
            }

            $invalidos[$token] = true;
        }

        return array_keys($invalidos);
    }

    /**
     * @return string[]
     */
    public function campos(): array
    {
        return $this->campos;
    }

    /**
     * @return string[]
     */
    public function colunas(): array
    {
        $colunas = [];

        foreach ($this->campos as $campo) {
            $definicao = $this->campo($campo);

            if (! $definicao->ehDeRelacao()) {
                $colunas[$definicao->coluna] = true;
            }
        }

        // Relação pedida exige a chave local no SELECT do pai: sem ela o Eloquent não tem
        // com o que montar o `where <fk> in (...)` do eager load.
        if ($this->relacoes() !== []) {
            $colunas[$this->chaveLocal] = true;
        }

        $colunas = array_keys($colunas);

        if ($colunas === []) {
            throw new LogicException(
                'Seleção de campos vazia: select([]) geraria SQL inválido ("select  from"). '
                . 'Ou o mapa não tem nenhum campo marcado noPadrao, ou a validação de fields não rodou antes.'
            );
        }

        return $colunas;
    }

    /**
     * @return array<string, string[]>
     */
    public function relacoes(): array
    {
        $relacoes = [];

        foreach ($this->campos as $campo) {
            $definicao = $this->campo($campo);

            if (! $definicao->ehDeRelacao() || $definicao->relacao === null || $definicao->chaveEstrangeira === null) {
                continue;
            }

            $relacoes[$definicao->relacao][$definicao->chaveEstrangeira] = true;
            $relacoes[$definicao->relacao][$definicao->coluna] = true;
        }

        return array_map(static fn (array $colunas): array => array_keys($colunas), $relacoes);
    }

    /**
     * Verdadeiro quando o cliente NÃO recortou nada — default do item ou curinga. Não
     * significa "todos os campos do mapa": no default do item os sensíveis ficam fora.
     */
    public function tudo(): bool
    {
        return $this->tudo;
    }

    public function inclui(string $campo): bool
    {
        return in_array($campo, $this->campos, true);
    }

    public function campo(string $chave): Campo
    {
        if (! isset($this->mapa[$chave])) {
            throw new LogicException("Campo '{$chave}' não existe no mapa.");
        }

        return $this->mapa[$chave];
    }

    /**
     * @return string[]
     */
    private static function tokens(?string $fields): array
    {
        if ($fields === null) {
            return [];
        }

        $tokens = array_map('trim', explode(',', $fields));

        return array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
    }

    /**
     * @param array<string, Campo> $mapa
     *
     * @return string[]
     */
    private static function expandir(string $token, array $mapa): array
    {
        if (isset($mapa[$token])) {
            return [$token];
        }

        if (! str_ends_with($token, '.*')) {
            return [];
        }

        // 'fisica.*' -> prefixo 'fisica.'
        $prefixo = substr($token, 0, -1);

        return array_values(array_filter(
            array_keys($mapa),
            static fn (string $chave): bool => str_starts_with($chave, $prefixo)
        ));
    }

    /**
     * @param array<string, Campo> $mapa
     *
     * @return string[]
     */
    private static function doPadrao(array $mapa): array
    {
        $padrao = [];

        foreach ($mapa as $chave => $campo) {
            if ($campo->noPadrao) {
                $padrao[] = $chave;
            }
        }

        return $padrao;
    }

    /**
     * @param array<string, Campo> $mapa
     *
     * @return string[]
     */
    private static function naoSensiveis(array $mapa): array
    {
        $campos = [];

        foreach ($mapa as $chave => $campo) {
            if (! $campo->sensivel) {
                $campos[] = $chave;
            }
        }

        return $campos;
    }
}
