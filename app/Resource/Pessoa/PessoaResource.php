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

/**
 * Serializa APENAS colunas de unim_pessoa. Não toca relação nenhuma: pessoa física e
 * jurídica são recursos próprios, e o mapa de campos não tem mais `fisica.*`/`juridica.*`
 * (ver MapaDeCamposPessoa). Por consequência não existe mais risco de N+1 aqui — antes o
 * Resource precisava de relationLoaded() antes de tocar a relação para não disparar lazy
 * load uma vez por linha da listagem.
 */
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
        // ESCRITA, e ali campo marcado sensível tem de vir — filtrar esconderia o que o
        // servidor gravou.
        $selecao ??= SelecaoDeCampos::completa(MapaDeCamposPessoa::mapa(), MapaDeCamposPessoa::CHAVE_LOCAL);

        $saida = [];

        foreach ($selecao->campos() as $chave) {
            $saida[$chave] = self::valor($pessoa->getAttribute($selecao->campo($chave)->coluna));
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
     * Hoje o mapa de pessoa não expõe coluna de data nenhuma (as datas de nascimento e de
     * expedição saíram com pessoa física), então esta conversão está sem uso efetivo — fica
     * porque é o contrato de saída de data desta API, e coluna de data de unim_pessoa
     * (dt_cadastro, dt_base) é candidata natural a entrar no mapa. No dia em que um
     * datetime entrar, esta regra precisa passar a distinguir data de data-e-hora.
     */
    private static function valor(mixed $valor): mixed
    {
        return $valor instanceof DateTimeInterface ? $valor->format('Y-m-d') : $valor;
    }
}
