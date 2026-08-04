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

namespace App\Resource\Pessoa;

use App\Model\Pessoa\UnimPessoa;
use App\Support\Campos\SelecaoDeCampos;
use DateTimeInterface;
use Hyperf\Database\Model\Model;

class PessoaResource
{
    /**
     * @param null|SelecaoDeCampos $selecao null significa contrato completo — é o que
     *                                      POST/PUT/PATCH usam, porque resposta de escrita
     *                                      filtrada esconderia o que o servidor gravou
     *
     * @return array<string, mixed>
     */
    public static function um(UnimPessoa $pessoa, ?SelecaoDeCampos $selecao = null): array
    {
        // completa() e não selecao(padraoEhTudo: true): sem seleção significa resposta de
        // ESCRITA, e ali a PII tem de vir — filtrar esconderia o que o servidor gravou.
        $selecao ??= SelecaoDeCampos::completa(MapaDeCamposPessoa::mapa(), MapaDeCamposPessoa::CHAVE_LOCAL);

        $saida = [];

        foreach ($selecao->campos() as $chave) {
            $campo = $selecao->campo($chave);

            if (! $campo->ehDeRelacao()) {
                $saida[$chave] = self::valor($pessoa->getAttribute($campo->coluna));

                continue;
            }

            $relacao = (string) $campo->relacao;

            // A chave existe sempre que foi pedida; o valor é que pode ser nulo (pessoa do
            // outro tipo). Isso mantém a forma da resposta estável para o cliente.
            if (! array_key_exists($relacao, $saida)) {
                $saida[$relacao] = null;
            }

            // relationLoaded() antes de getRelation(): tocar uma relação não carregada
            // dispararia lazy load, uma query por linha da listagem (N+1).
            $filho = $pessoa->relationLoaded($relacao) ? $pessoa->getRelation($relacao) : null;

            if (! $filho instanceof Model) {
                continue;
            }

            $valores = is_array($saida[$relacao]) ? $saida[$relacao] : [];
            $valores[$campo->coluna] = self::valor($filho->getAttribute($campo->coluna));
            $saida[$relacao] = $valores;
        }

        return $saida;
    }

    /**
     * @param iterable<UnimPessoa> $pessoas
     *
     * @return array<int, array<string, mixed>>
     */
    public static function muitos(iterable $pessoas, ?SelecaoDeCampos $selecao = null): array
    {
        $itens = [];

        foreach ($pessoas as $pessoa) {
            $itens[] = self::um($pessoa, $selecao);
        }

        return $itens;
    }

    /**
     * Data vira 'Y-m-d'. getAttribute() devolve Carbon quando a coluna tem cast date, e o
     * formato declarado em $casts (date:Y-m-d) só é aplicado dentro de toArray()
     * (HasAttributes::addCastAttributesToArray) — que este Resource não usa, de propósito,
     * porque toArray() exporia coluna que o mapa não expõe. Sem isto o JSON sairia
     * "1990-05-12T00:00:00.000000Z" onde a documentação promete "1990-05-12".
     *
     * Hoje todo campo de data exposto é data pura. No dia em que um datetime entrar no
     * mapa, esta regra precisa passar a distinguir os dois.
     */
    private static function valor(mixed $valor): mixed
    {
        return $valor instanceof DateTimeInterface ? $valor->format('Y-m-d') : $valor;
    }
}
