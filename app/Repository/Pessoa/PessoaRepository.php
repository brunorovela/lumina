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

namespace App\Repository\Pessoa;

use App\Exception\Pessoa\PessoaNaoEncontradaException;
use App\Model\Pessoa\UnimPessoa;
use App\Resource\Pessoa\MapaDeCamposPessoa;
use App\Support\Campos\SelecaoDeCampos;
use App\Support\Tipo;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Collection;
use RuntimeException;

/**
 * Só unim_pessoa. Não existe mais transação com filho, nem eager load de
 * fisica/juridica: quem grava e lê unim_pessoa_fisica/unim_pessoa_juridica é o recurso
 * dessas tabelas, não este.
 *
 * CONSEQUÊNCIA CONHECIDA de tirar a escrita do filho daqui: um PUT que inverte
 * sn_pessoa_juridica não apaga mais a linha do tipo antigo. Antes isso era feito nesta
 * classe (e destruía CPF sem confirmação). Agora uma pessoa que vira jurídica pode ficar
 * com a linha de unim_pessoa_fisica preenchida — dado do outro recurso, que só o outro
 * recurso pode apagar.
 */
class PessoaRepository implements PessoaRepositoryInterface
{
    /**
     * @param array<string, mixed> $dadosPessoa
     */
    public function criar(array $dadosPessoa): UnimPessoa
    {
        // fresh() para a resposta refletir a linha gravada (colunas com default no banco
        // inclusas), e não só o que o payload mandou. Pode devolver null se a linha
        // desaparecer no meio; o guard troca um TypeError opaco por erro explícito.
        return self::garantirPessoa(UnimPessoa::create($dadosPessoa)->fresh());
    }

    /**
     * @param array<string, mixed> $dadosPessoa
     */
    public function atualizar(int $cdPessoa, int $cdCliente, array $dadosPessoa): UnimPessoa
    {
        // cd_cliente no WHERE: sem ele um id de outro tenant seria atualizável.
        $pessoa = UnimPessoa::where('cd_pessoa', $cdPessoa)->where('cd_cliente', $cdCliente)->first();

        if ($pessoa === null) {
            throw new PessoaNaoEncontradaException();
        }

        $pessoa->update($dadosPessoa);

        return self::garantirPessoa($pessoa->fresh());
    }

    public function buscarPorId(int $cdPessoa, int $cdCliente): ?UnimPessoa
    {
        // Todas as colunas do mapa, sempre: o recorte de ?fields= do detalhe acontece na
        // serialização, porque o cache do endpoint é por entidade (uma chave por pessoa,
        // ver App\Service\Pessoa\CachePessoa). SELECT parcial aqui faria cada combinação de
        // fields precisar da própria chave de cache.
        $query = UnimPessoa::query();
        $query->select(MapaDeCamposPessoa::colunas());

        return $query
            ->where('cd_pessoa', $cdPessoa)
            ->where('cd_cliente', $cdCliente)
            ->first();
    }

    /**
     * @param array<string, mixed> $filtros
     * @param null|SelecaoDeCampos $selecao null significa contrato completo
     *
     * @return array{itens: Collection<int, UnimPessoa>, total: int}
     */
    public function listar(int $cdCliente, array $filtros, int $page, int $perPage, ?SelecaoDeCampos $selecao = null): array
    {
        // completa(): hoje nenhum chamador interno passa null aqui — PessoaController
        // sempre resolve e passa uma SelecaoDeCampos explícita. Este fallback só existe
        // para o método ter comportamento definido se um dia alguém chamar listar() sem
        // seleção.
        $selecao ??= SelecaoDeCampos::completa(MapaDeCamposPessoa::mapa(), MapaDeCamposPessoa::CHAVE_LOCAL);

        $query = self::consulta($selecao)->where('cd_cliente', $cdCliente);

        if (! empty($filtros['nome'])) {
            $query->where('ds_nome', 'like', '%' . Tipo::texto($filtros['nome']) . '%');
        }

        if (! empty($filtros['tipo_pessoa'])) {
            $query->where('sn_pessoa_juridica', $filtros['tipo_pessoa'] === 'juridica');
        }

        $total = (clone $query)->count();

        // Ordem-base determinística: sem ORDER BY a ordem vem do plano de acesso, que muda
        // com as colunas projetadas — dois clientes paginando com `fields` diferentes veriam
        // ordens diferentes, e LIMIT/OFFSET sem ordem total pode repetir ou pular linha.
        $itens = $query->orderBy('cd_pessoa')->forPage($page, $perPage)->get();

        return ['itens' => $itens, 'total' => $total];
    }

    public function excluir(int $cdPessoa, int $cdCliente): bool
    {
        $pessoa = UnimPessoa::where('cd_pessoa', $cdPessoa)->where('cd_cliente', $cdCliente)->first();

        if ($pessoa === null) {
            return false;
        }

        return (bool) $pessoa->delete();
    }

    public function loginExiste(int $cdCliente, string $dsLogin, ?int $ignorarCdPessoa = null): bool
    {
        // withTrashed(): o índice UNIQUE (cd_cliente, ds_login) do banco não sabe o que é
        // soft-delete — ele existe sobre TODAS as linhas, inclusive as com dt_excluido
        // preenchido. Sem withTrashed() aqui, criar->excluir->recriar com o mesmo login
        // passava por esta checagem (SoftDeletes filtra dt_excluido por padrão) e só
        // estourava lá na frente como erro de banco genérico (23000, via
        // DatabaseExceptionHandler) em vez de LoginJaExisteException com mensagem clara.
        $query = UnimPessoa::withTrashed()->where('cd_cliente', $cdCliente)->where('ds_login', $dsLogin);

        if ($ignorarCdPessoa !== null) {
            $query->where('cd_pessoa', '!=', $ignorarCdPessoa);
        }

        return $query->exists();
    }

    private static function garantirPessoa(mixed $pessoa): UnimPessoa
    {
        if (! $pessoa instanceof UnimPessoa) {
            throw new RuntimeException('Escrita de pessoa não devolveu o registro gravado.');
        }

        return $pessoa;
    }

    /**
     * Monta a consulta da listagem com o SELECT parcial. É o ponto onde a seleção deixa de
     * ser contrato de API e passa a ser SQL. Não há mais `with()`: o mapa não tem relação.
     *
     * @return Builder<UnimPessoa>
     */
    private static function consulta(SelecaoDeCampos $selecao): Builder
    {
        $query = UnimPessoa::query();

        // select() só existe em Query\Builder e chega ao Model\Builder via @mixin (mesmo
        // gap de tipagem do forPage() já documentado no ignoreErrors do phpstan.neon.dist):
        // encadear a chamada faria o phpstan inferir o retorno como Query\Builder. Chamar e
        // devolver $query separadamente preserva o Builder<UnimPessoa> que
        // UnimPessoa::query() já garante.
        $query->select($selecao->colunas());

        return $query;
    }
}
