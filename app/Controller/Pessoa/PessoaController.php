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
use App\Resource\Pessoa\MapaDeCamposPessoa;
use App\Resource\Pessoa\PessoaResource;
use App\Service\Pessoa\PessoaService;
use App\Support\ApiResponse;
use App\Support\IdentidadeContext;
use App\Support\Tipo;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Swagger\Annotation as OA;
use Psr\Http\Message\ResponseInterface;

#[OA\HyperfServer(name: 'http')]
class PessoaController extends AbstractController
{
    #[Inject]
    protected PessoaService $pessoaService;

    #[OA\Post(path: '/pessoas', summary: 'Cria uma nova pessoa para o cliente autenticado', tags: ['Pessoa'])]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['ds_nome', 'ds_login', 'ds_senha', 'sn_pessoa_juridica'],
            properties: [
                new OA\Property(property: 'ds_nome', type: 'string', maxLength: 255),
                new OA\Property(property: 'ds_login', type: 'string', maxLength: 100),
                new OA\Property(property: 'ds_senha', type: 'string', minLength: 6),
                new OA\Property(property: 'sn_pessoa_juridica', type: 'boolean'),
                new OA\Property(property: 'ds_nome_oficial', type: 'string', maxLength: 255, description: 'Obrigatório quando sn_pessoa_juridica = false'),
                new OA\Property(property: 'ds_cpf', type: 'string', nullable: true),
                new OA\Property(property: 'ds_cnpj', type: 'string', description: 'Obrigatório quando sn_pessoa_juridica = true'),
                new OA\Property(property: 'ds_nome_fantasia', type: 'string', maxLength: 255, description: 'Obrigatório quando sn_pessoa_juridica = true'),
            ]
        )
    )]
    #[OA\Response(response: 201, description: 'Pessoa criada')]
    #[OA\Response(response: 401, description: 'Não autenticado')]
    #[OA\Response(response: 403, description: 'Sem permissão')]
    #[OA\Response(response: 409, description: 'Já existe pessoa com esse login para este cliente')]
    #[OA\Response(response: 422, description: 'Dados inválidos')]
    public function criar(CreatePessoaRequest $request): ResponseInterface
    {
        $pessoa = $this->pessoaService->criar(IdentidadeContext::cdCliente(), Tipo::mapa($request->validated()));

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)))->withStatus(201);
    }

    #[OA\Put(path: '/pessoas/{id}', summary: 'Atualiza (substitui) uma pessoa existente', tags: ['Pessoa'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['ds_nome', 'ds_login', 'sn_pessoa_juridica'],
            properties: [
                new OA\Property(property: 'ds_nome', type: 'string', maxLength: 255),
                new OA\Property(property: 'ds_login', type: 'string', maxLength: 100),
                new OA\Property(property: 'ds_senha', type: 'string', minLength: 6, nullable: true),
                new OA\Property(property: 'sn_pessoa_juridica', type: 'boolean'),
                new OA\Property(property: 'ds_nome_oficial', type: 'string', maxLength: 255),
                new OA\Property(property: 'ds_cpf', type: 'string', nullable: true),
                new OA\Property(property: 'ds_cnpj', type: 'string'),
                new OA\Property(property: 'ds_nome_fantasia', type: 'string', maxLength: 255),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Pessoa atualizada')]
    #[OA\Response(response: 401, description: 'Não autenticado')]
    #[OA\Response(response: 403, description: 'Sem permissão')]
    #[OA\Response(response: 404, description: 'Pessoa não encontrada')]
    #[OA\Response(response: 422, description: 'Dados inválidos')]
    public function atualizar(int $id, UpdatePessoaRequest $request): ResponseInterface
    {
        $pessoa = $this->pessoaService->atualizar($id, IdentidadeContext::cdCliente(), Tipo::mapa($request->validated()));

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)));
    }

    #[OA\Patch(path: '/pessoas/{id}', summary: 'Atualiza parcialmente uma pessoa existente', tags: ['Pessoa'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'ds_nome', type: 'string', maxLength: 255),
                new OA\Property(property: 'ds_login', type: 'string', maxLength: 100),
                new OA\Property(property: 'ds_senha', type: 'string', minLength: 6),
                new OA\Property(property: 'ds_nome_oficial', type: 'string', maxLength: 255),
                new OA\Property(property: 'ds_cpf', type: 'string', nullable: true),
                new OA\Property(property: 'ds_cnpj', type: 'string'),
                new OA\Property(property: 'ds_nome_fantasia', type: 'string', maxLength: 255),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Pessoa atualizada')]
    #[OA\Response(response: 401, description: 'Não autenticado')]
    #[OA\Response(response: 403, description: 'Sem permissão')]
    #[OA\Response(response: 404, description: 'Pessoa não encontrada')]
    #[OA\Response(response: 422, description: 'Dados inválidos (ou nenhum campo enviado)')]
    public function atualizarParcial(int $id, PatchPessoaRequest $request): ResponseInterface
    {
        $pessoa = $this->pessoaService->atualizarParcial($id, IdentidadeContext::cdCliente(), Tipo::mapa($request->validated()));

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)));
    }

    #[OA\Get(path: '/pessoas/{id}', summary: 'Busca uma pessoa pelo identificador', tags: ['Pessoa'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Pessoa encontrada')]
    #[OA\Response(response: 401, description: 'Não autenticado')]
    #[OA\Response(response: 403, description: 'Sem permissão')]
    #[OA\Response(response: 404, description: 'Pessoa não encontrada')]
    public function buscar(int $id): ResponseInterface
    {
        $pessoa = $this->pessoaService->buscar($id, IdentidadeContext::cdCliente());

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)));
    }

    #[OA\Get(path: '/pessoas', summary: 'Lista pessoas do cliente autenticado', tags: ['Pessoa'])]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100))]
    #[OA\Parameter(name: 'nome', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'tipo_pessoa', in: 'query', schema: new OA\Schema(type: 'string', enum: ['fisica', 'juridica']))]
    #[OA\Parameter(
        name: 'fields',
        in: 'query',
        description: 'Campos a devolver, separados por vírgula. Campo de relação usa ponto (fisica.ds_cpf), e relação inteira usa curinga (fisica.*). `fields=*` devolve tudo. '
            . 'ATENÇÃO: sem este parâmetro a LISTA devolve apenas cd_pessoa, ds_nome, ds_login e sn_pessoa_juridica — diferente de GET /pessoas/{id}, que devolve o registro completo. '
            . 'Campos disponíveis: cd_pessoa, cd_cliente, ds_nome, ds_login, sn_pessoa_juridica, fisica.ds_nome_oficial, fisica.ds_cpf, juridica.ds_cnpj, juridica.ds_nome_fantasia.',
        schema: new OA\Schema(type: 'string', example: 'ds_nome,fisica.ds_cpf')
    )]
    #[OA\Response(response: 200, description: 'Lista paginada de pessoas')]
    #[OA\Response(response: 401, description: 'Não autenticado')]
    #[OA\Response(response: 403, description: 'Sem permissão')]
    #[OA\Response(response: 422, description: 'Dados inválidos')]
    public function listar(ListPessoaRequest $request): ResponseInterface
    {
        $validado = Tipo::mapa($request->validated());

        $filtros = array_intersect_key($validado, array_flip(['nome', 'tipo_pessoa']));
        $page = Tipo::inteiro($validado['page'] ?? null, 1);
        $perPage = Tipo::inteiro($validado['per_page'] ?? null, 20);

        $fields = $validado['fields'] ?? null;
        $selecao = MapaDeCamposPessoa::selecao(is_string($fields) ? $fields : null);

        $resultado = $this->pessoaService->listar(IdentidadeContext::cdCliente(), $filtros, $page, $perPage, $selecao);

        return $this->response->json(ApiResponse::sucesso(
            PessoaResource::muitos($resultado['itens'], $selecao),
            [
                'total' => $resultado['total'],
                'per_page' => $resultado['per_page'],
                'current_page' => $page,
                'last_page' => (int) ceil($resultado['total'] / $resultado['per_page']),
            ]
        ));
    }

    #[OA\Delete(path: '/pessoas/{id}', summary: 'Exclui uma pessoa existente', tags: ['Pessoa'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(response: 200, description: 'Pessoa excluída')]
    #[OA\Response(response: 401, description: 'Não autenticado')]
    #[OA\Response(response: 403, description: 'Sem permissão')]
    #[OA\Response(response: 404, description: 'Pessoa não encontrada')]
    public function excluir(int $id): ResponseInterface
    {
        $this->pessoaService->excluir($id, IdentidadeContext::cdCliente());

        return $this->response->json(ApiResponse::sucesso(null));
    }
}
