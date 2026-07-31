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

namespace App\Service\Pessoa;

use App\Exception\Pessoa\LoginJaExisteException;
use App\Exception\Pessoa\PessoaNaoEncontradaException;
use App\Model\Pessoa\UnimPessoa;
use App\Repository\Pessoa\PessoaRepositoryInterface;
use App\Support\Campos\SelecaoDeCampos;
use App\Support\Tipo;
use Hyperf\Database\Model\Collection;

class PessoaService
{
    private const LOGINS_ISENTOS_DE_FISICA_JURIDICA = ['admin', 'administrador'];

    public function __construct(private PessoaRepositoryInterface $pessoaRepository)
    {
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function criar(int $cdCliente, array $dados): UnimPessoa
    {
        if ($this->pessoaRepository->loginExiste($cdCliente, Tipo::texto($dados['ds_login'] ?? null))) {
            throw new LoginJaExisteException();
        }

        [$dadosPessoa, $dadosFisica, $dadosJuridica] = $this->separarDados($cdCliente, $dados);

        return $this->pessoaRepository->criar($dadosPessoa, $dadosFisica, $dadosJuridica);
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function atualizar(int $cdPessoa, int $cdCliente, array $dados): UnimPessoa
    {
        $dsLogin = Tipo::texto($dados['ds_login'] ?? null);

        $this->garantirLoginDisponivel($cdPessoa, $cdCliente, $dsLogin);

        [$dadosPessoa, $dadosFisica, $dadosJuridica] = $this->separarDados($cdCliente, $dados);

        // O Repository precisa saber se esta pessoa é isenta de física/jurídica (login
        // admin/administrador) pra NUNCA apagar fisica/juridica dela — mesmo que
        // $dadosFisica/$dadosJuridica venham null aqui (o que pra pessoa isenta não
        // significa "tipo mudou", significa "essa regra nunca se aplicou"). Regressão
        // real encontrada em re-review: sem esse sinal, um PUT válido numa pessoa isenta
        // que tivesse fisica/juridica órfã por dado legado (cd_pessoa=1/2, cd_cliente=23,
        // confirmado contra o banco real) apagava essa linha.
        $ehIsentoDeFisicaJuridica = $this->ehIsentoDeFisicaJuridica($dsLogin);

        return $this->pessoaRepository->atualizar(
            $cdPessoa,
            $cdCliente,
            $dadosPessoa,
            $dadosFisica,
            $dadosJuridica,
            $ehIsentoDeFisicaJuridica
        );
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function atualizarParcial(int $cdPessoa, int $cdCliente, array $dados): UnimPessoa
    {
        if (isset($dados['ds_login'])) {
            $this->garantirLoginDisponivel($cdPessoa, $cdCliente, Tipo::texto($dados['ds_login']));
        }

        // PATCH não muda o tipo pessoa (física/jurídica) — precisa saber o tipo REAL já
        // existente antes de montar os dados parciais. Sem isso, um PATCH com ds_cnpj
        // numa pessoa física criava uma linha jurídica pra ela (Finding 14, whole-branch
        // review; mesmo problema de integridade do Critical 1, por outra porta). buscar()
        // também garante 404 cedo se a pessoa não existir/não for deste cliente.
        $pessoaAtual = $this->buscar($cdPessoa, $cdCliente);

        $dadosPessoa = array_intersect_key($dados, array_flip(['ds_nome', 'ds_login', 'ds_senha']));

        if (isset($dadosPessoa['ds_senha'])) {
            $dadosPessoa['ds_senha'] = password_hash(Tipo::texto($dadosPessoa['ds_senha']), PASSWORD_BCRYPT);
        }

        // Campos do tipo que a pessoa NÃO é são ignorados silenciosamente, mesmo que
        // venham no payload.
        $dadosFisica = $pessoaAtual->sn_pessoa_juridica
            ? []
            : array_intersect_key($dados, array_flip(['ds_nome_oficial', 'ds_cpf']));

        $dadosJuridica = $pessoaAtual->sn_pessoa_juridica
            ? array_intersect_key($dados, array_flip(['ds_cnpj', 'ds_nome_fantasia']))
            : [];

        return $this->pessoaRepository->atualizar(
            $cdPessoa,
            $cdCliente,
            $dadosPessoa,
            $dadosFisica ?: null,
            $dadosJuridica ?: null
        );
    }

    /**
     * @param null|SelecaoDeCampos $selecao null significa contrato completo
     */
    public function buscar(int $cdPessoa, int $cdCliente, ?SelecaoDeCampos $selecao = null): UnimPessoa
    {
        $pessoa = $this->pessoaRepository->buscarPorId($cdPessoa, $cdCliente, $selecao);

        if ($pessoa === null) {
            throw new PessoaNaoEncontradaException();
        }

        return $pessoa;
    }

    /**
     * @param array<string, mixed> $filtros
     * @param null|SelecaoDeCampos $selecao repassada intacta ao Repository — o Service não
     *                                      decide nada sobre seleção de campos, quem define
     *                                      o default por endpoint é o Controller
     *
     * @return array{itens: Collection<int, UnimPessoa>, total: int, per_page: int}
     */
    public function listar(int $cdCliente, array $filtros, int $page, int $perPage, ?SelecaoDeCampos $selecao = null): array
    {
        // O per_page EFETIVO (clampado) precisa voltar pro Controller montar o `meta` --
        // senão meta.per_page/last_page mentem quando o cliente pede per_page > 100
        // (Finding 5, whole-branch review: o Controller usava o per_page ORIGINAL do
        // request pro meta, mas a paginação de fato rodava com o clampado).
        $perPage = min($perPage, 100);

        $resultado = $this->pessoaRepository->listar($cdCliente, $filtros, $page, $perPage, $selecao);

        return [...$resultado, 'per_page' => $perPage];
    }

    public function excluir(int $cdPessoa, int $cdCliente): void
    {
        if (! $this->pessoaRepository->excluir($cdPessoa, $cdCliente)) {
            throw new PessoaNaoEncontradaException();
        }
    }

    private function garantirLoginDisponivel(int $cdPessoa, int $cdCliente, string $dsLogin): void
    {
        if ($this->pessoaRepository->loginExiste($cdCliente, $dsLogin, ignorarCdPessoa: $cdPessoa)) {
            throw new LoginJaExisteException();
        }
    }

    private function ehIsentoDeFisicaJuridica(string $dsLogin): bool
    {
        return in_array(strtolower($dsLogin), self::LOGINS_ISENTOS_DE_FISICA_JURIDICA, true);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array{0: array<string, mixed>, 1: null|array<string, mixed>, 2: null|array<string, mixed>}
     */
    private function separarDados(int $cdCliente, array $dados): array
    {
        $dsLogin = Tipo::texto($dados['ds_login'] ?? null);

        $dadosPessoa = [
            'cd_cliente' => $cdCliente,
            'ds_nome' => $dados['ds_nome'],
            'ds_login' => $dsLogin,
            'sn_pessoa_juridica' => $dados['sn_pessoa_juridica'],
        ];

        if (isset($dados['ds_senha'])) {
            $dadosPessoa['ds_senha'] = password_hash(Tipo::texto($dados['ds_senha']), PASSWORD_BCRYPT);
        }

        if ($this->ehIsentoDeFisicaJuridica($dsLogin)) {
            return [$dadosPessoa, null, null];
        }

        if ($dados['sn_pessoa_juridica']) {
            $dadosJuridica = [
                'ds_cnpj' => $dados['ds_cnpj'],
                'ds_nome_fantasia' => $dados['ds_nome_fantasia'],
            ];

            return [$dadosPessoa, null, $dadosJuridica];
        }

        $dadosFisica = ['ds_nome_oficial' => $dados['ds_nome_oficial']];

        if (isset($dados['ds_cpf'])) {
            $dadosFisica['ds_cpf'] = $dados['ds_cpf'];
        }

        return [$dadosPessoa, $dadosFisica, null];
    }
}
