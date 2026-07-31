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
use App\Model\Pessoa\UnimPessoaFisica;
use App\Model\Pessoa\UnimPessoaJuridica;
use App\Resource\Pessoa\MapaDeCamposPessoa;
use App\Support\Campos\SelecaoDeCampos;
use App\Support\Tipo;
use Closure;
use Hyperf\Database\Model\Builder;
use Hyperf\Database\Model\Collection;
use Hyperf\Database\Model\Model;
use Hyperf\Database\Model\Relations\Relation;
use Hyperf\DbConnection\Db;
use RuntimeException;

class PessoaRepository implements PessoaRepositoryInterface
{
    /**
     * @param array<string, mixed> $dadosPessoa
     * @param null|array<string, mixed> $dadosFisica
     * @param null|array<string, mixed> $dadosJuridica
     */
    public function criar(array $dadosPessoa, ?array $dadosFisica, ?array $dadosJuridica): UnimPessoa
    {
        // Db::transaction() devolve mixed (repassa o que a closure retornar), e fresh()
        // pode devolver null se a linha desaparecer no meio. O guard troca um TypeError
        // opaco por erro explícito.
        $pessoa = Db::transaction(function () use ($dadosPessoa, $dadosFisica, $dadosJuridica) {
            $pessoa = UnimPessoa::create($dadosPessoa);

            if ($dadosFisica !== null) {
                UnimPessoaFisica::create(['cd_pessoa' => $pessoa->cd_pessoa, ...$dadosFisica]);
            }

            if ($dadosJuridica !== null) {
                UnimPessoaJuridica::create(['cd_pessoa' => $pessoa->cd_pessoa, ...$dadosJuridica]);
            }

            return $pessoa->fresh(['fisica', 'juridica']);
        });

        return self::garantirPessoa($pessoa);
    }

    /**
     * @param array<string, mixed> $dadosPessoa
     * @param null|array<string, mixed> $dadosFisica
     * @param null|array<string, mixed> $dadosJuridica
     */
    public function atualizar(
        int $cdPessoa,
        int $cdCliente,
        array $dadosPessoa,
        ?array $dadosFisica,
        ?array $dadosJuridica,
        bool $ehIsentoDeFisicaJuridica = false
    ): UnimPessoa {
        $pessoaAtualizada = Db::transaction(function () use (
            $cdPessoa,
            $cdCliente,
            $dadosPessoa,
            $dadosFisica,
            $dadosJuridica,
            $ehIsentoDeFisicaJuridica
        ) {
            $pessoa = UnimPessoa::where('cd_pessoa', $cdPessoa)->where('cd_cliente', $cdCliente)->first();

            if ($pessoa === null) {
                throw new PessoaNaoEncontradaException();
            }

            $pessoa->update($dadosPessoa);

            // Quando quem chamou informa o tipo pessoa (PUT — sempre manda
            // sn_pessoa_juridica) E a pessoa NÃO é isenta de física/jurídica, o filho do
            // tipo que NÃO se aplica mais precisa ser apagado, senão uma pessoa que trocou
            // de física pra jurídica (ou vice-versa) fica com as duas linhas filhas
            // preenchidas ao mesmo tempo (dado órfão, num schema compartilhado com o LMS
            // legado). Isso é seguro mesmo quando o tipo NÃO mudou: a FK
            // unim_pessoa_fisica/unim_pessoa_juridica -> unim_pessoa é ON DELETE RESTRICT
            // no sentido pessoa->filho (apagar o pai com filho vivo é que seria
            // bloqueado); apagar o filho aqui nunca toca o pai.
            //
            // REGRESSÃO CORRIGIDA (re-review pós-fix do Critical 1): pessoas isentas
            // (login admin/administrador) sempre têm $dadosFisica E $dadosJuridica null —
            // não porque o tipo mudou, mas porque a regra de negócio nunca aplica
            // física/jurídica a elas. Sem o guard $ehIsentoDeFisicaJuridica, um PUT válido
            // nessas pessoas apagava qualquer fisica/juridica órfã de dado legado
            // (reproduzido de verdade contra cd_pessoa=1/2, cd_cliente=23). Pessoa isenta
            // NUNCA tem fisica/juridica mexida aqui, independente do que vier nos arrays.
            //
            // No PATCH (atualizarParcial) dadosPessoa nunca contém sn_pessoa_juridica, então
            // este bloco não roda ali — um PATCH que só manda ds_nome não pode apagar o
            // filho existente só porque não reenviou os campos dele.
            if (array_key_exists('sn_pessoa_juridica', $dadosPessoa) && ! $ehIsentoDeFisicaJuridica) {
                if ($dadosFisica === null) {
                    UnimPessoaFisica::where('cd_pessoa', $cdPessoa)->delete();
                }

                if ($dadosJuridica === null) {
                    UnimPessoaJuridica::where('cd_pessoa', $cdPessoa)->delete();
                }
            }

            if ($dadosFisica !== null) {
                UnimPessoaFisica::updateOrCreate(['cd_pessoa' => $cdPessoa], $dadosFisica);
            }

            if ($dadosJuridica !== null) {
                UnimPessoaJuridica::updateOrCreate(['cd_pessoa' => $cdPessoa], $dadosJuridica);
            }

            return $pessoa->fresh(['fisica', 'juridica']);
        });

        return self::garantirPessoa($pessoaAtualizada);
    }

    /**
     * @param null|SelecaoDeCampos $selecao null significa contrato completo
     */
    public function buscarPorId(int $cdPessoa, int $cdCliente, ?SelecaoDeCampos $selecao = null): ?UnimPessoa
    {
        $selecao ??= MapaDeCamposPessoa::selecao(null, padraoEhTudo: true);

        return self::consulta($selecao)
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
        $selecao ??= MapaDeCamposPessoa::selecao(null, padraoEhTudo: true);

        $query = self::consulta($selecao)->where('cd_cliente', $cdCliente);

        if (! empty($filtros['nome'])) {
            $query->where('ds_nome', 'like', '%' . Tipo::texto($filtros['nome']) . '%');
        }

        if (! empty($filtros['tipo_pessoa'])) {
            $query->where('sn_pessoa_juridica', $filtros['tipo_pessoa'] === 'juridica');
        }

        $total = (clone $query)->count();
        $itens = $query->forPage($page, $perPage)->get();

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
            throw new RuntimeException('Transação de pessoa não devolveu o registro gravado.');
        }

        return $pessoa;
    }

    /**
     * Monta a consulta com o SELECT parcial e só as relações pedidas. É o ponto onde a
     * seleção deixa de ser contrato de API e passa a ser SQL.
     *
     * @return Builder<UnimPessoa>
     */
    private static function consulta(SelecaoDeCampos $selecao): Builder
    {
        $query = UnimPessoa::query();

        foreach ($selecao->relacoes() as $relacao => $colunas) {
            $query->with([$relacao => self::selecionar($colunas)]);
        }

        // select() só existe em Query\Builder e chega ao Model\Builder via @mixin (mesmo
        // gap de tipagem do forPage() já documentado no ignoreErrors do phpstan.neon.dist):
        // encadear a chamada faria o phpstan inferir o retorno como Query\Builder. Chamar e
        // devolver $query separadamente preserva o Builder<UnimPessoa> que
        // UnimPessoa::query() já garante.
        $query->select($selecao->colunas());

        return $query;
    }

    /**
     * O callback do with() recebe a Relation (ex.: HasOne), não o Builder: o Eloquent
     * chama $constraints($relation) em eagerLoadRelation(). Tipar o parâmetro como Builder
     * derruba em runtime com TypeError — Relation não é um Builder, apenas repassa select()
     * a ele via @mixin/__call.
     *
     * @param string[] $colunas
     *
     * @return Closure(Relation<Model, Model, mixed>): void
     */
    private static function selecionar(array $colunas): Closure
    {
        return static function (Relation $consulta) use ($colunas): void {
            $consulta->select($colunas);
        };
    }
}
