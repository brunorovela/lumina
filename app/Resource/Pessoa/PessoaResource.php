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
        $selecao ??= MapaDeCamposPessoa::selecao(null, padraoEhTudo: true);

        $saida = [];

        foreach ($selecao->campos() as $chave) {
            $campo = $selecao->campo($chave);

            if (! $campo->ehDeRelacao()) {
                $saida[$chave] = $pessoa->getAttribute($campo->coluna);

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
            $valores[$campo->coluna] = $filho->getAttribute($campo->coluna);
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
}
