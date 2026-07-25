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

namespace App\Controller\Pessoa;

use App\Controller\AbstractController;
use App\Request\Pessoa\CreatePessoaRequest;
use App\Request\Pessoa\ListPessoaRequest;
use App\Request\Pessoa\PatchPessoaRequest;
use App\Request\Pessoa\UpdatePessoaRequest;
use App\Resource\Pessoa\PessoaResource;
use App\Service\Pessoa\PessoaService;
use App\Support\ApiResponse;
use App\Support\IdentidadeContext;
use Hyperf\Di\Annotation\Inject;
use Psr\Http\Message\ResponseInterface;

class PessoaController extends AbstractController
{
    #[Inject]
    protected PessoaService $pessoaService;

    public function criar(CreatePessoaRequest $request): ResponseInterface
    {
        $pessoa = $this->pessoaService->criar(IdentidadeContext::cdCliente(), $request->validated());

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)))->withStatus(201);
    }

    public function atualizar(int $id, UpdatePessoaRequest $request): ResponseInterface
    {
        $pessoa = $this->pessoaService->atualizar($id, IdentidadeContext::cdCliente(), $request->validated());

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)));
    }

    public function atualizarParcial(int $id, PatchPessoaRequest $request): ResponseInterface
    {
        $pessoa = $this->pessoaService->atualizarParcial($id, IdentidadeContext::cdCliente(), $request->validated());

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)));
    }

    public function buscar(int $id): ResponseInterface
    {
        $pessoa = $this->pessoaService->buscar($id, IdentidadeContext::cdCliente());

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)));
    }

    public function listar(ListPessoaRequest $request): ResponseInterface
    {
        $filtros = array_intersect_key($request->validated(), array_flip(['nome', 'tipo_pessoa']));
        $page = (int) ($request->validated()['page'] ?? 1);
        $perPage = (int) ($request->validated()['per_page'] ?? 20);

        $resultado = $this->pessoaService->listar(IdentidadeContext::cdCliente(), $filtros, $page, $perPage);

        return $this->response->json(ApiResponse::sucesso(
            PessoaResource::muitos($resultado['itens']),
            [
                'total' => $resultado['total'],
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) ceil($resultado['total'] / $perPage),
            ]
        ));
    }

    public function excluir(int $id): ResponseInterface
    {
        $this->pessoaService->excluir($id, IdentidadeContext::cdCliente());

        return $this->response->json(ApiResponse::sucesso(null));
    }
}
