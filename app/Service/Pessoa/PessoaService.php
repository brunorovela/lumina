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

/**
 * Regras de pessoa — unim_pessoa e nada além. Pessoa física e jurídica são recursos
 * próprios: esta classe não separa mais o payload em três tabelas, não conhece a isenção de
 * física/jurídica dos logins admin/administrador (regra que só existia para decidir o que
 * gravar nas tabelas filhas) e não apaga linha de outro recurso quando o tipo muda.
 */
class PessoaService
{
    /**
     * Colunas de unim_pessoa que a API escreve, fora cd_cliente (que vem da identidade
     * autenticada, nunca do payload) e ds_senha (tratada à parte porque passa por hash).
     *
     * FONTE ÚNICA de POST/PUT (criar/atualizar) e PATCH (atualizarParcial). Coluna que
     * valida no request mas não entra aqui responde 200/201 e nunca grava — falha
     * silenciosa, o pior modo de falha.
     *
     * @var string[]
     */
    private const CAMPOS_PESSOA = ['ds_nome', 'ds_login', 'sn_pessoa_juridica'];

    public function __construct(
        private PessoaRepositoryInterface $pessoaRepository,
        private CachePessoa $cachePessoa
    ) {
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function criar(int $cdCliente, array $dados): UnimPessoa
    {
        if ($this->pessoaRepository->loginExiste($cdCliente, Tipo::texto($dados['ds_login'] ?? null))) {
            throw new LoginJaExisteException();
        }

        return $this->pessoaRepository->criar($this->dadosDePessoa($cdCliente, $dados));
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function atualizar(int $cdPessoa, int $cdCliente, array $dados): UnimPessoa
    {
        $this->garantirLoginDisponivel($cdPessoa, $cdCliente, Tipo::texto($dados['ds_login'] ?? null));

        $pessoa = $this->pessoaRepository->atualizar($cdPessoa, $cdCliente, $this->dadosDePessoa($cdCliente, $dados));

        // Depois da escrita, não antes: se a gravação falhar (404, login duplicado, erro de
        // banco), o cache continua válido porque nada mudou.
        $this->cachePessoa->esquecer($cdCliente, $cdPessoa);

        return $pessoa;
    }

    /**
     * @param array<string, mixed> $dados
     */
    public function atualizarParcial(int $cdPessoa, int $cdCliente, array $dados): UnimPessoa
    {
        if (isset($dados['ds_login'])) {
            $this->garantirLoginDisponivel($cdPessoa, $cdCliente, Tipo::texto($dados['ds_login']));
        }

        // PATCH não precisa mais ler a pessoa antes de gravar: aquela leitura existia só
        // para descobrir o tipo (física/jurídica) e decidir em qual tabela filha escrever.
        // O 404 continua garantido — quem o levanta é o Repository, dentro do WHERE com
        // cd_cliente.
        $dadosPessoa = self::somenteCamposConhecidos($dados, self::CAMPOS_PESSOA);

        if (isset($dados['ds_senha'])) {
            $dadosPessoa['ds_senha'] = password_hash(Tipo::texto($dados['ds_senha']), PASSWORD_BCRYPT);
        }

        $pessoa = $this->pessoaRepository->atualizar($cdPessoa, $cdCliente, $dadosPessoa);

        $this->cachePessoa->esquecer($cdCliente, $cdPessoa);

        return $pessoa;
    }

    /**
     * Leitura de detalhe: cache primeiro, banco só no miss. O cache guarda a ENTIDADE (todas
     * as colunas do mapa), então o recorte de ?fields= é aplicado depois pelo
     * PessoaResource, no Controller — não existe uma chave de cache por combinação de fields.
     */
    public function buscar(int $cdPessoa, int $cdCliente): UnimPessoa
    {
        $cacheada = $this->cachePessoa->buscar($cdCliente, $cdPessoa);

        if ($cacheada !== null) {
            return $cacheada;
        }

        $pessoa = $this->pessoaRepository->buscarPorId($cdPessoa, $cdCliente);

        if ($pessoa === null) {
            // 404 não é cacheado de propósito — ver CachePessoa.
            throw new PessoaNaoEncontradaException();
        }

        $this->cachePessoa->guardar($cdCliente, $cdPessoa, $pessoa);

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

        // A LISTAGEM NÃO É CACHEADA: a resposta depende de filtro, página e fields, e
        // invalidar isso a cada escrita é outro problema. Só o detalhe tem cache.
        $resultado = $this->pessoaRepository->listar($cdCliente, $filtros, $page, $perPage, $selecao);

        return [...$resultado, 'per_page' => $perPage];
    }

    public function excluir(int $cdPessoa, int $cdCliente): void
    {
        if (! $this->pessoaRepository->excluir($cdPessoa, $cdCliente)) {
            throw new PessoaNaoEncontradaException();
        }

        // Sem isto, a pessoa excluída continuaria respondendo 200 no detalhe por até uma
        // hora — o soft delete some do banco na hora, o cache não.
        $this->cachePessoa->esquecer($cdCliente, $cdPessoa);
    }

    private function garantirLoginDisponivel(int $cdPessoa, int $cdCliente, string $dsLogin): void
    {
        if ($this->pessoaRepository->loginExiste($cdCliente, $dsLogin, ignorarCdPessoa: $cdPessoa)) {
            throw new LoginJaExisteException();
        }
    }

    /**
     * Payload de POST/PUT, onde ds_nome, ds_login e sn_pessoa_juridica são obrigatórios pelo
     * FormRequest.
     *
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    private function dadosDePessoa(int $cdCliente, array $dados): array
    {
        $dadosPessoa = ['cd_cliente' => $cdCliente, ...self::somenteCamposConhecidos($dados, self::CAMPOS_PESSOA)];

        if (isset($dados['ds_senha'])) {
            $dadosPessoa['ds_senha'] = password_hash(Tipo::texto($dados['ds_senha']), PASSWORD_BCRYPT);
        }

        return $dadosPessoa;
    }

    /**
     * Recorta do payload apenas as chaves presentes e conhecidas. `array_intersect_key` faria
     * o mesmo, mas com a lista invertida (flip) em cada chamada — aqui a intenção fica
     * explícita e a lista de campos continua legível.
     *
     * @param array<string, mixed> $dados
     * @param string[] $campos
     *
     * @return array<string, mixed>
     */
    private static function somenteCamposConhecidos(array $dados, array $campos): array
    {
        $recorte = [];

        foreach ($campos as $campo) {
            if (array_key_exists($campo, $dados)) {
                $recorte[$campo] = $dados[$campo];
            }
        }

        return $recorte;
    }
}
