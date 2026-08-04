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
use App\Resource\Dominio\DominioResource;
use App\Support\ApiResponse;
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
}
