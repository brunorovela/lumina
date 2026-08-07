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
use App\Request\Pessoa\BuscarPessoaRequest;
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

/**
 * ESCOPO DESTA API: a tabela unim_pessoa. Nada de unim_pessoa_fisica nem
 * unim_pessoa_juridica — cada recurso responde pelos próprios dados. Campo de física ou
 * jurídica no payload de escrita responde 422; em ?fields= responde 422 do mesmo jeito
 * (campo fora do mapa).
 */
#[OA\HyperfServer(name: 'http')]
class PessoaController extends AbstractController
{
    #[Inject]
    protected PessoaService $pessoaService;

    #[OA\Post(path: '/pessoas', summary: 'Cria uma nova pessoa para o cliente autenticado', tags: ['Pessoa'])]
    #[OA\RequestBody(
        required: true,
        description: 'Esta API grava APENAS a tabela de pessoa (unim_pessoa): nome, login, senha e o indicador de '
            . 'física/jurídica. '
            . 'MUDANÇA DE CONTRATO: os quatorze campos de pessoa física e jurídica que este endpoint aceitava '
            . '(ds_nome_oficial, ds_cpf, ds_cnpj, ds_nome_fantasia, ds_nome_social, ds_nome_mae, ds_nome_pai, '
            . 'ds_identidade, ds_orgao_estado, ds_identidade_orgao_exp, dt_identidade_expedicao, dt_nascimento, '
            . 'ds_sexo, cd_estado_civil) NÃO são mais aceitos aqui. Enviar qualquer um deles — ou qualquer outro campo '
            . 'fora da lista abaixo — responde 422 com o nome do campo em errors, em vez de ser descartado em silêncio. '
            . 'Consequência prática: uma pessoa criada por aqui NÃO ganha linha em unim_pessoa_fisica; sn_pessoa_juridica '
            . 'diz apenas o tipo declarado.',
        content: new OA\JsonContent(
            required: ['ds_nome', 'ds_login', 'ds_senha', 'sn_pessoa_juridica'],
            properties: [
                new OA\Property(property: 'ds_nome', type: 'string', maxLength: 255, example: 'Ana Souza'),
                new OA\Property(property: 'ds_login', type: 'string', maxLength: 100, description: 'Único por cliente. Login repetido responde 409.', example: 'ana.souza'),
                new OA\Property(property: 'ds_senha', type: 'string', minLength: 6, description: 'Gravada com hash bcrypt; nunca é devolvida em nenhuma resposta.'),
                new OA\Property(property: 'sn_pessoa_juridica', type: 'boolean', description: 'false = pessoa física, true = pessoa jurídica. Só declara o tipo — não cria nem apaga registro de física/jurídica.', example: false),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Pessoa criada. A resposta ignora ?fields= e traz o registro completo de unim_pessoa — é o que o '
            . 'servidor gravou. cd_cliente vem da identidade autenticada, nunca do payload.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Pessoa'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(
        response: 409,
        description: 'Já existe pessoa com esse login para este cliente. Mesma resposta (mesma mensagem) para uma '
            . 'violação de chave estrangeira em qualquer coluna FK do payload (SQLSTATE 23000, mapeado para 409 por '
            . 'App\Exception\Handler\DatabaseExceptionHandler).',
        content: new OA\JsonContent(ref: '#/components/schemas/Erro')
    )]
    #[OA\Response(
        response: 422,
        description: 'Dados inválidos, OU campo que não pertence a este recurso (qualquer campo de pessoa '
            . 'física/jurídica, ou qualquer nome fora da lista do requestBody). A chave de errors é o nome do campo '
            . 'recusado.',
        content: new OA\JsonContent(ref: '#/components/schemas/ErroValidacao')
    )]
    public function criar(CreatePessoaRequest $request): ResponseInterface
    {
        $pessoa = $this->pessoaService->criar(IdentidadeContext::cdCliente(), Tipo::mapa($request->validated()));

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)))->withStatus(201);
    }

    #[OA\Put(path: '/pessoas/{id}', summary: 'Atualiza (substitui) uma pessoa existente', tags: ['Pessoa'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        description: 'Mesmo escopo de POST /pessoas: só colunas de unim_pessoa. Campo de pessoa física/jurídica (ou '
            . 'qualquer campo fora da lista abaixo) responde 422. '
            . 'ATENÇÃO — TROCAR sn_pessoa_juridica NÃO DESTRÓI MAIS DADO: antes um PUT que invertia o tipo apagava de '
            . 'vez a linha de unim_pessoa_fisica ou unim_pessoa_juridica (CPF incluso, sem confirmação). Agora essa '
            . 'linha permanece intacta — ela é de outro recurso, e apagá-la é decisão de quem responde por ele. Em '
            . 'troca, uma pessoa pode ficar marcada como jurídica com dado de física ainda gravado no banco. '
            . 'Este verbo invalida o cache do detalhe da pessoa (ver GET /pessoas/{id}).',
        content: new OA\JsonContent(
            required: ['ds_nome', 'ds_login', 'sn_pessoa_juridica'],
            properties: [
                new OA\Property(property: 'ds_nome', type: 'string', maxLength: 255, example: 'Ana Souza'),
                new OA\Property(property: 'ds_login', type: 'string', maxLength: 100, description: 'Único por cliente (a própria pessoa é ignorada na checagem). Login de outra pessoa responde 409.', example: 'ana.souza'),
                new OA\Property(property: 'ds_senha', type: 'string', minLength: 6, nullable: true, description: 'Omitida ou null mantém a senha atual.'),
                new OA\Property(property: 'sn_pessoa_juridica', type: 'boolean', description: 'false = pessoa física, true = pessoa jurídica. Trocar o valor não mexe em unim_pessoa_fisica/unim_pessoa_juridica.', example: false),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Pessoa atualizada. A resposta ignora ?fields= e traz o registro completo de unim_pessoa — é o que '
            . 'o servidor gravou.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Pessoa'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 404, description: 'Pessoa não encontrada (ou de outro cliente)', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(
        response: 409,
        description: 'Já existe pessoa com esse login para este cliente, ou violação de chave estrangeira (SQLSTATE '
            . '23000, mapeado para 409 por App\Exception\Handler\DatabaseExceptionHandler).',
        content: new OA\JsonContent(ref: '#/components/schemas/Erro')
    )]
    #[OA\Response(
        response: 422,
        description: 'Dados inválidos, OU campo que não pertence a este recurso. A chave de errors é o nome do campo recusado.',
        content: new OA\JsonContent(ref: '#/components/schemas/ErroValidacao')
    )]
    public function atualizar(int $id, UpdatePessoaRequest $request): ResponseInterface
    {
        $pessoa = $this->pessoaService->atualizar($id, IdentidadeContext::cdCliente(), Tipo::mapa($request->validated()));

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)));
    }

    #[OA\Patch(path: '/pessoas/{id}', summary: 'Atualiza parcialmente uma pessoa existente', tags: ['Pessoa'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        description: 'Envie só os campos que quer trocar — nenhum é obrigatório, mas o payload precisa ter ao menos um '
            . '(payload vazio responde 422). Campo omitido não é tocado. '
            . 'Mesmo escopo de POST/PUT: só colunas de unim_pessoa; campo de pessoa física/jurídica responde 422. '
            . 'MUDANÇA DE CONTRATO: sn_pessoa_juridica agora PODE ser alterado por PATCH. Antes era recusado em '
            . 'silêncio, porque trocar o tipo mexia nas tabelas filhas; essas tabelas saíram desta API, então o campo é '
            . 'só mais uma coluna de unim_pessoa. Trocar o valor não mexe em unim_pessoa_fisica/unim_pessoa_juridica. '
            . 'Este verbo invalida o cache do detalhe da pessoa (ver GET /pessoas/{id}).',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'ds_nome', type: 'string', maxLength: 255, example: 'Ana Souza'),
                new OA\Property(property: 'ds_login', type: 'string', maxLength: 100, description: 'Único por cliente. Login de outra pessoa responde 409.', example: 'ana.souza'),
                new OA\Property(property: 'ds_senha', type: 'string', minLength: 6, description: 'Gravada com hash bcrypt; nunca é devolvida.'),
                new OA\Property(property: 'sn_pessoa_juridica', type: 'boolean', description: 'false = física, true = jurídica.', example: false),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Pessoa atualizada. A resposta ignora ?fields= e traz o registro completo de unim_pessoa.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Pessoa'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 404, description: 'Pessoa não encontrada (ou de outro cliente)', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(
        response: 409,
        description: 'Já existe pessoa com esse login para este cliente, ou violação de chave estrangeira (SQLSTATE 23000).',
        content: new OA\JsonContent(ref: '#/components/schemas/Erro')
    )]
    #[OA\Response(
        response: 422,
        description: 'Dados inválidos, nenhum campo enviado, OU campo que não pertence a este recurso.',
        content: new OA\JsonContent(ref: '#/components/schemas/ErroValidacao')
    )]
    public function atualizarParcial(int $id, PatchPessoaRequest $request): ResponseInterface
    {
        $pessoa = $this->pessoaService->atualizarParcial($id, IdentidadeContext::cdCliente(), Tipo::mapa($request->validated()));

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)));
    }

    #[OA\Get(
        path: '/pessoas/{id}',
        summary: 'Busca uma pessoa pelo identificador (resposta cacheada por 1 hora)',
        description: 'CACHE: a primeira leitura de cada pessoa vai ao banco e guarda o registro no Redis por '
            . '3600 segundos (1 hora), na chave `pessoa:{cd_cliente}:{cd_pessoa}`; as leituras seguintes dentro da '
            . 'janela são servidas do cache, sem consultar o banco. '
            . 'O cache é por PESSOA, não por combinação de ?fields=: guarda todas as colunas expostas e o recorte de '
            . 'fields é aplicado na hora de responder, então pedir fields diferentes aproveita a mesma entrada. '
            . 'PUT, PATCH e DELETE /pessoas/{id} invalidam a entrada na hora — a leitura seguinte volta ao banco. '
            . 'Escrita feita FORA desta API (direto no banco, ou pelo LMS legado) não invalida nada: nesse caso a '
            . 'resposta pode ficar desatualizada por até uma hora. '
            . '404 não é cacheado, e GET /pessoas (listagem) não usa cache nenhum.',
        tags: ['Pessoa']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(
        name: 'fields',
        in: 'query',
        description: 'Campos a devolver, separados por vírgula (mesma sintaxe de GET /pessoas). Sem este parâmetro o '
            . 'detalhe devolve o registro completo; a listagem é que devolve um conjunto enxuto. '
            . 'Campos disponíveis (todos de unim_pessoa): cd_pessoa, cd_cliente, ds_nome, ds_login, sn_pessoa_juridica. '
            . 'MUDANÇA DE CONTRATO: os campos de relação (fisica.* e juridica.*) não existem mais aqui — pedi-los '
            . 'responde 422, porque pessoa física e jurídica são recursos próprios. Por isso `fields=*` hoje devolve '
            . 'exatamente esses cinco campos.',
        schema: new OA\Schema(type: 'string', example: 'ds_nome,ds_login')
    )]
    #[OA\Response(
        response: 200,
        description: 'Pessoa encontrada. Sem ?fields= vêm os cinco campos de unim_pessoa; com ?fields= vem só o que foi '
            . 'pedido. A resposta pode vir do cache (ver a descrição do endpoint) — o corpo é idêntico nos dois casos.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', ref: '#/components/schemas/Pessoa'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 404, description: 'Pessoa não encontrada (ou de outro cliente)', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(
        response: 422,
        description: 'fields com campo que não existe no mapa de pessoa (inclusive fisica.* e juridica.*, que saíram desta API).',
        content: new OA\JsonContent(ref: '#/components/schemas/ErroValidacao')
    )]
    public function buscar(int $id, BuscarPessoaRequest $request): ResponseInterface
    {
        $fields = Tipo::mapa($request->validated())['fields'] ?? null;
        $selecao = MapaDeCamposPessoa::selecao(is_string($fields) ? $fields : null, padraoEhTudo: true);

        // O Service devolve a pessoa inteira (do cache ou do banco); o recorte de fields é
        // do Resource. É o que permite uma única entrada de cache servir qualquer fields.
        $pessoa = $this->pessoaService->buscar($id, IdentidadeContext::cdCliente());

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa, $selecao)));
    }

    #[OA\Get(
        path: '/pessoas',
        summary: 'Lista pessoas do cliente autenticado',
        description: 'Esta listagem NÃO é cacheada (o cache de 1 hora existe só em GET /pessoas/{id}): o resultado '
            . 'depende de filtro, página e fields, e reflete o banco a cada requisição.',
        tags: ['Pessoa']
    )]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'per_page', in: 'query', description: 'Máximo 100. Valor maior é reduzido a 100 em silêncio, e o meta devolvido já reflete o valor efetivo.', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100))]
    #[OA\Parameter(name: 'nome', in: 'query', description: 'Filtro por parte do nome (LIKE %nome%).', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'tipo_pessoa', in: 'query', description: 'Filtra por sn_pessoa_juridica: fisica = false, juridica = true. É filtro por coluna de unim_pessoa, não por existência de registro em unim_pessoa_fisica/unim_pessoa_juridica.', schema: new OA\Schema(type: 'string', enum: ['fisica', 'juridica']))]
    #[OA\Parameter(
        name: 'fields',
        in: 'query',
        description: 'Campos a devolver, separados por vírgula. `fields=*` devolve todos. '
            . 'ATENÇÃO: sem este parâmetro a LISTA devolve apenas cd_pessoa, ds_nome, ds_login e sn_pessoa_juridica — '
            . 'diferente de GET /pessoas/{id}, que devolve o registro completo. '
            . 'Campos disponíveis (todos de unim_pessoa): cd_pessoa, cd_cliente, ds_nome, ds_login, sn_pessoa_juridica. '
            . 'MUDANÇA DE CONTRATO: fisica.* e juridica.* não existem mais aqui — pedi-los responde 422.',
        schema: new OA\Schema(type: 'string', example: 'ds_nome,cd_cliente')
    )]
    #[OA\Response(
        response: 200,
        description: 'Lista paginada. Sem ?fields=, cada item traz apenas os campos de PessoaResumida; '
            . 'com ?fields= (ou fields=*), cada item segue o schema Pessoa recortado pelo que foi pedido — por isso '
            . 'o item é oneOf[PessoaResumida, Pessoa] e não um $ref fixo: um cliente gerado a partir deste schema '
            . 'que travasse em PessoaResumida rejeitaria a resposta a ?fields=cd_cliente.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(oneOf: [
                        new OA\Schema(ref: '#/components/schemas/PessoaResumida'),
                        new OA\Schema(ref: '#/components/schemas/Pessoa'),
                    ])
                ),
                new OA\Property(property: 'meta', ref: '#/components/schemas/MetaPaginacao'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 422, description: 'Parâmetro inválido, inclusive fields com campo fora do mapa de pessoa', content: new OA\JsonContent(ref: '#/components/schemas/ErroValidacao'))]
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

    #[OA\Delete(
        path: '/pessoas/{id}',
        summary: 'Exclui uma pessoa existente',
        description: 'Soft delete em unim_pessoa e invalidação do cache do detalhe. Registro de pessoa física ou '
            . 'jurídica ligado a esta pessoa NÃO é tocado — é de outro recurso.',
        tags: ['Pessoa']
    )]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Response(
        response: 200,
        description: 'Pessoa excluída. É soft delete: a linha permanece com dt_excluido preenchido e para de aparecer '
            . 'nas leituras. O cache do detalhe é apagado na hora, então o GET seguinte responde 404 e não a versão '
            . 'cacheada.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', description: 'Sempre null neste endpoint — a exclusão não devolve corpo de dado.', type: 'boolean', example: null, nullable: true),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 404, description: 'Pessoa não encontrada (ou de outro cliente)', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    public function excluir(int $id): ResponseInterface
    {
        $this->pessoaService->excluir($id, IdentidadeContext::cdCliente());

        return $this->response->json(ApiResponse::sucesso(null));
    }
}
