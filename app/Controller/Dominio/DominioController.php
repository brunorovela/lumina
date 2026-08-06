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

namespace App\Controller\Dominio;

use App\Controller\AbstractController;
use App\Repository\Dominio\DominioRepositoryInterface;
use App\Request\Dominio\ListCidadeRequest;
use App\Request\Dominio\ListEstadoRequest;
use App\Resource\Dominio\DominioResource;
use App\Support\ApiResponse;
use App\Support\Tipo;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Swagger\Annotation as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * Catálogos que alimentam o cadastro de pessoa.
 *
 * Sem Service no meio de propósito: não há regra de negócio nenhuma a hospedar aqui, e um
 * Service seria repasse vazio do repositório.
 *
 * Nenhuma destas rotas tem escopo de tenant, porque nenhuma das tabelas tem cd_cliente.
 * ACL reusa GERENCIAR_PESSOA + ACESSAR: é o único par que existe em
 * ulms_recurso_privilegio para este domínio, e chave inventada nega tudo em silêncio.
 */
#[OA\HyperfServer(name: 'http')]
class DominioController extends AbstractController
{
    #[Inject]
    protected DominioRepositoryInterface $dominioRepository;

    #[OA\Get(
        path: '/paises',
        summary: 'Lista os países do catálogo global',
        description: 'Catálogo global, sem escopo de cliente e sem paginação — são poucas linhas. '
            . 'A resposta NÃO tem `meta`, diferente de GET /pessoas.',
        tags: ['Domínio']
    )]
    #[OA\Response(
        response: 200,
        description: 'Lista completa de países, ordenada por ds_pais.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Pais')),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    public function paises(): ResponseInterface
    {
        return $this->response->json(
            ApiResponse::sucesso(DominioResource::paises($this->dominioRepository->paises()))
        );
    }

    #[OA\Get(
        path: '/estados-civis',
        summary: 'Lista os estados civis do catálogo global',
        description: 'Catálogo global, sem escopo de cliente, sem paginação e sem `meta`. '
            . 'Use para traduzir fisica.cd_estado_civil: a leitura de pessoa devolve o código, não o rótulo.',
        tags: ['Domínio']
    )]
    #[OA\Response(
        response: 200,
        description: 'Lista completa de estados civis, ordenada por cd_estado_civil.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/EstadoCivil')),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    public function estadosCivis(): ResponseInterface
    {
        return $this->response->json(
            ApiResponse::sucesso(DominioResource::estadosCivis($this->dominioRepository->estadosCivis()))
        );
    }

    #[OA\Get(
        path: '/contato-tipos',
        summary: 'Lista os tipos de contato do catálogo global',
        description: 'Catálogo global, sem escopo de cliente, sem paginação e sem `meta`. '
            . 'O cd_tipo devolvido aqui é o que o cadastro de contato exige.',
        tags: ['Domínio']
    )]
    #[OA\Response(
        response: 200,
        description: 'Lista completa de tipos de contato, ordenada por cd_tipo.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContatoTipo')),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    public function tiposDeContato(): ResponseInterface
    {
        return $this->response->json(
            ApiResponse::sucesso(DominioResource::tiposDeContato($this->dominioRepository->tiposDeContato()))
        );
    }

    #[OA\Get(
        path: '/estados',
        summary: 'Lista os estados do catálogo global',
        description: 'Catálogo global, sem escopo de cliente, sem paginação e sem `meta`. '
            . 'Omitir cd_pais devolve os estados de todos os países.',
        tags: ['Domínio']
    )]
    #[OA\Parameter(
        name: 'cd_pais',
        in: 'query',
        required: false,
        description: 'Filtra por país. Deve ser inteiro >= 1. Omitido, devolve todos os estados. Obtenha o código em GET /paises.',
        schema: new OA\Schema(type: 'integer', example: 1)
    )]
    #[OA\Response(
        response: 200,
        description: 'Lista de estados, ordenada por ds_estado.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Estado')),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 422, description: 'cd_pais informado não é inteiro ou é menor que 1 (o parâmetro em si é opcional)', content: new OA\JsonContent(ref: '#/components/schemas/ErroValidacao'))]
    public function estados(ListEstadoRequest $request): ResponseInterface
    {
        $validado = Tipo::mapa($request->validated());
        $cdPais = isset($validado['cd_pais']) ? Tipo::inteiro($validado['cd_pais']) : null;

        return $this->response->json(
            ApiResponse::sucesso(DominioResource::estados($this->dominioRepository->estados($cdPais)))
        );
    }

    #[OA\Get(
        path: '/cidades',
        summary: 'Lista as cidades de um estado',
        description: 'Catálogo global, sem escopo de cliente, sem paginação e sem `meta`. '
            . 'cd_estado é OBRIGATÓRIO: a tabela tem quase 5 mil linhas e a rota não devolve o catálogo inteiro. '
            . 'Sem ele a resposta é 422.',
        tags: ['Domínio']
    )]
    #[OA\Parameter(
        name: 'cd_estado',
        in: 'query',
        required: true,
        description: 'Estado das cidades. Obrigatório e deve ser inteiro >= 1 — sem ele, ou fora desse formato, a resposta é 422. Obtenha o código em GET /estados.',
        schema: new OA\Schema(type: 'integer', example: 26)
    )]
    #[OA\Parameter(
        name: 'q',
        in: 'query',
        required: false,
        description: 'Filtra ds_cidade por trecho do nome (LIKE %q%), sem distinção de acento ou caixa (collation do banco). '
            . 'Omitido, devolve todas as cidades do estado — mas enviado PRESENTE e vazio (?q=) dá 422, não é tratado '
            . 'como omitido (regra sometimes|string|min:1|max:255: min:1 reprova string vazia). '
            . 'q vira fragmento cru de LIKE sem escapar caracteres especiais: `%` e `_` no valor agem como curinga SQL '
            . '(ex.: q=100% casa qualquer nome, não o caractere "%" literal).',
        schema: new OA\Schema(type: 'string', example: 'camp')
    )]
    #[OA\Response(
        response: 200,
        description: 'Lista de cidades do estado pedido, ordenada por ds_cidade.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Cidade')),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 422, description: 'cd_estado ausente ou inválido, ou q enviado presente e vazio (min:1)', content: new OA\JsonContent(ref: '#/components/schemas/ErroValidacao'))]
    public function cidades(ListCidadeRequest $request): ResponseInterface
    {
        $validado = Tipo::mapa($request->validated());
        $cdEstado = Tipo::inteiro($validado['cd_estado'] ?? null);
        $q = isset($validado['q']) ? Tipo::texto($validado['q']) : null;

        return $this->response->json(
            ApiResponse::sucesso(DominioResource::cidades($this->dominioRepository->cidades($cdEstado, $q)))
        );
    }
}
