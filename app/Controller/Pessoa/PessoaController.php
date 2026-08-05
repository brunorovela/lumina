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

#[OA\HyperfServer(name: 'http')]
class PessoaController extends AbstractController
{
    #[Inject]
    protected PessoaService $pessoaService;

    #[OA\Post(path: '/pessoas', summary: 'Cria uma nova pessoa para o cliente autenticado', tags: ['Pessoa'])]
    #[OA\RequestBody(
        required: true,
        description: 'Nos dez campos novos de pessoa física (ds_nome_social, ds_nome_mae, ds_nome_pai, ds_identidade, '
            . 'ds_orgao_estado, ds_identidade_orgao_exp, dt_identidade_expedicao, dt_nascimento, ds_sexo, cd_estado_civil), '
            . 'string vazia enviada é tratada como ausência de valor e vira null. '
            . 'ds_cpf e ds_cnpj aceitam máscara ou número JSON sem aspas; são validados por dígito verificador e gravados/devolvidos sempre sem máscara.',
        content: new OA\JsonContent(
            required: ['ds_nome', 'ds_login', 'ds_senha', 'sn_pessoa_juridica'],
            properties: [
                new OA\Property(property: 'ds_nome', type: 'string', maxLength: 255),
                new OA\Property(property: 'ds_login', type: 'string', maxLength: 100),
                new OA\Property(property: 'ds_senha', type: 'string', minLength: 6),
                new OA\Property(property: 'sn_pessoa_juridica', type: 'boolean'),
                new OA\Property(property: 'ds_nome_oficial', type: 'string', maxLength: 255, description: 'Obrigatório quando sn_pessoa_juridica = false'),
                new OA\Property(property: 'ds_cpf', type: 'string', description: 'CPF com 11 dígitos e DV válido. Aceita máscara ou número JSON sem aspas.', example: '52998224725', nullable: true),
                new OA\Property(property: 'ds_cnpj', type: 'string', description: 'Obrigatório quando sn_pessoa_juridica = true. CNPJ com 14 dígitos e DV válido. Aceita máscara ou número JSON sem aspas.', example: '00000000000191'),
                new OA\Property(property: 'ds_nome_fantasia', type: 'string', maxLength: 255, description: 'Obrigatório quando sn_pessoa_juridica = true'),
                new OA\Property(property: 'ds_nome_social', type: 'string', maxLength: 255, nullable: true, example: 'Ana'),
                new OA\Property(property: 'ds_nome_mae', type: 'string', maxLength: 255, nullable: true, example: 'Maria Souza'),
                new OA\Property(property: 'ds_nome_pai', type: 'string', maxLength: 255, nullable: true, example: 'Jose Souza'),
                new OA\Property(property: 'ds_identidade', type: 'string', maxLength: 255, nullable: true, example: '123456789'),
                new OA\Property(property: 'ds_orgao_estado', type: 'string', maxLength: 255, nullable: true, description: 'UF do órgão expedidor da identidade.', example: 'SP'),
                new OA\Property(property: 'ds_identidade_orgao_exp', type: 'string', maxLength: 255, nullable: true, description: 'Órgão expedidor da identidade.', example: 'SSP'),
                new OA\Property(property: 'dt_identidade_expedicao', type: 'string', format: 'date', nullable: true, description: 'Y-m-d. Não pode ser futura nem anterior a dt_nascimento quando as duas vêm no mesmo payload.', example: '2015-03-01'),
                new OA\Property(property: 'dt_nascimento', type: 'string', format: 'date', nullable: true, description: 'Y-m-d. Não pode ser futura.', example: '1990-05-12'),
                new OA\Property(property: 'ds_sexo', type: 'string', enum: ['f', 'm'], nullable: true, description: 'Só f, m ou null. F/M maiúsculo também são aceitos e gravados em minúsculo.', example: 'f'),
                new OA\Property(property: 'cd_estado_civil', type: 'integer', nullable: true, description: 'Código de saas_estado_civil; precisa existir na tabela. Rótulo em GET /estados-civis.', example: 37),
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: 'Pessoa criada. A resposta ignora ?fields= e traz o registro completo, dado pessoal incluso — é o que o '
            . 'servidor gravou. CPF e CNPJ voltam sem máscara e ds_sexo em minúsculo, mesmo que enviados de outra forma; '
            . 'nos dez campos novos de pessoa física, string vazia enviada volta como null.',
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
    #[OA\Response(response: 409, description: 'Já existe pessoa com esse login para este cliente', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: '#/components/schemas/ErroValidacao'))]
    public function criar(CreatePessoaRequest $request): ResponseInterface
    {
        $pessoa = $this->pessoaService->criar(IdentidadeContext::cdCliente(), Tipo::mapa($request->validated()));

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)))->withStatus(201);
    }

    #[OA\Put(path: '/pessoas/{id}', summary: 'Atualiza (substitui) uma pessoa existente', tags: ['Pessoa'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        description: 'Mesmas regras de POST /pessoas para os campos de pessoa física, ds_cpf e ds_cnpj — inclusive o '
            . 'empty-string-vira-null nos dez campos novos e a aceitação de máscara ou número JSON sem aspas em ds_cpf/ds_cnpj.',
        content: new OA\JsonContent(
            required: ['ds_nome', 'ds_login', 'sn_pessoa_juridica'],
            properties: [
                new OA\Property(property: 'ds_nome', type: 'string', maxLength: 255),
                new OA\Property(property: 'ds_login', type: 'string', maxLength: 100),
                new OA\Property(property: 'ds_senha', type: 'string', minLength: 6, nullable: true),
                new OA\Property(property: 'sn_pessoa_juridica', type: 'boolean'),
                new OA\Property(property: 'ds_nome_oficial', type: 'string', maxLength: 255),
                new OA\Property(property: 'ds_cpf', type: 'string', description: 'CPF com 11 dígitos e DV válido. Aceita máscara ou número JSON sem aspas.', example: '52998224725', nullable: true),
                new OA\Property(property: 'ds_cnpj', type: 'string', description: 'CNPJ com 14 dígitos e DV válido. Aceita máscara ou número JSON sem aspas.', example: '00000000000191'),
                new OA\Property(property: 'ds_nome_fantasia', type: 'string', maxLength: 255),
                new OA\Property(property: 'ds_nome_social', type: 'string', maxLength: 255, nullable: true, example: 'Ana'),
                new OA\Property(property: 'ds_nome_mae', type: 'string', maxLength: 255, nullable: true, example: 'Maria Souza'),
                new OA\Property(property: 'ds_nome_pai', type: 'string', maxLength: 255, nullable: true, example: 'Jose Souza'),
                new OA\Property(property: 'ds_identidade', type: 'string', maxLength: 255, nullable: true, example: '123456789'),
                new OA\Property(property: 'ds_orgao_estado', type: 'string', maxLength: 255, nullable: true, description: 'UF do órgão expedidor da identidade.', example: 'SP'),
                new OA\Property(property: 'ds_identidade_orgao_exp', type: 'string', maxLength: 255, nullable: true, description: 'Órgão expedidor da identidade.', example: 'SSP'),
                new OA\Property(property: 'dt_identidade_expedicao', type: 'string', format: 'date', nullable: true, description: 'Y-m-d. Não pode ser futura nem anterior a dt_nascimento quando as duas vêm no mesmo payload.', example: '2015-03-01'),
                new OA\Property(property: 'dt_nascimento', type: 'string', format: 'date', nullable: true, description: 'Y-m-d. Não pode ser futura.', example: '1990-05-12'),
                new OA\Property(property: 'ds_sexo', type: 'string', enum: ['f', 'm'], nullable: true, description: 'Só f, m ou null. F/M maiúsculo também são aceitos e gravados em minúsculo.', example: 'f'),
                new OA\Property(property: 'cd_estado_civil', type: 'integer', nullable: true, description: 'Código de saas_estado_civil; precisa existir na tabela. Rótulo em GET /estados-civis.', example: 37),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Pessoa atualizada. A resposta ignora ?fields= e traz o registro completo, dado pessoal incluso — é o '
            . 'que o servidor gravou. CPF e CNPJ voltam sem máscara e ds_sexo em minúsculo, mesmo que enviados de outra '
            . 'forma; nos dez campos novos de pessoa física, string vazia enviada volta como null.',
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
    #[OA\Response(response: 404, description: 'Pessoa não encontrada', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: '#/components/schemas/ErroValidacao'))]
    public function atualizar(int $id, UpdatePessoaRequest $request): ResponseInterface
    {
        $pessoa = $this->pessoaService->atualizar($id, IdentidadeContext::cdCliente(), Tipo::mapa($request->validated()));

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)));
    }

    #[OA\Patch(path: '/pessoas/{id}', summary: 'Atualiza parcialmente uma pessoa existente', tags: ['Pessoa'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\RequestBody(
        required: true,
        description: 'Envie só os campos que quer trocar — nenhum é obrigatório aqui, mas o payload precisa ter ao menos '
            . 'um. Campo do tipo que a pessoa NÃO é (física em jurídica, ou o contrário) é ignorado em silêncio, e PATCH '
            . 'nunca troca o tipo da pessoa. Mesmas regras de valor de POST /pessoas nos demais campos: nos dez campos '
            . 'novos de pessoa física, string vazia vira null; ds_cpf/ds_cnpj aceitam máscara ou número JSON sem aspas.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'ds_nome', type: 'string', maxLength: 255),
                new OA\Property(property: 'ds_login', type: 'string', maxLength: 100),
                new OA\Property(property: 'ds_senha', type: 'string', minLength: 6),
                new OA\Property(property: 'ds_nome_oficial', type: 'string', maxLength: 255),
                new OA\Property(property: 'ds_cpf', type: 'string', description: 'CPF com 11 dígitos e DV válido. Aceita máscara ou número JSON sem aspas.', example: '52998224725', nullable: true),
                new OA\Property(property: 'ds_cnpj', type: 'string', description: 'CNPJ com 14 dígitos e DV válido. Aceita máscara ou número JSON sem aspas.', example: '00000000000191'),
                new OA\Property(property: 'ds_nome_fantasia', type: 'string', maxLength: 255),
                new OA\Property(property: 'ds_nome_social', type: 'string', maxLength: 255, nullable: true, example: 'Ana'),
                new OA\Property(property: 'ds_nome_mae', type: 'string', maxLength: 255, nullable: true, example: 'Maria Souza'),
                new OA\Property(property: 'ds_nome_pai', type: 'string', maxLength: 255, nullable: true, example: 'Jose Souza'),
                new OA\Property(property: 'ds_identidade', type: 'string', maxLength: 255, nullable: true, example: '123456789'),
                new OA\Property(property: 'ds_orgao_estado', type: 'string', maxLength: 255, nullable: true, description: 'UF do órgão expedidor da identidade.', example: 'SP'),
                new OA\Property(property: 'ds_identidade_orgao_exp', type: 'string', maxLength: 255, nullable: true, description: 'Órgão expedidor da identidade.', example: 'SSP'),
                new OA\Property(property: 'dt_identidade_expedicao', type: 'string', format: 'date', nullable: true, description: 'Y-m-d. Não pode ser futura nem anterior a dt_nascimento quando as duas vêm no mesmo payload — a regra cruzada só é avaliada quando as duas datas vêm no mesmo PATCH.', example: '2015-03-01'),
                new OA\Property(property: 'dt_nascimento', type: 'string', format: 'date', nullable: true, description: 'Y-m-d. Não pode ser futura.', example: '1990-05-12'),
                new OA\Property(property: 'ds_sexo', type: 'string', enum: ['f', 'm'], nullable: true, description: 'Só f, m ou null. F/M maiúsculo também são aceitos e gravados em minúsculo.', example: 'f'),
                new OA\Property(property: 'cd_estado_civil', type: 'integer', nullable: true, description: 'Código de saas_estado_civil; precisa existir na tabela. Rótulo em GET /estados-civis.', example: 37),
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Pessoa atualizada. A resposta ignora ?fields= e traz o registro completo, dado pessoal incluso — é o '
            . 'que o servidor gravou. CPF e CNPJ voltam sem máscara e ds_sexo em minúsculo, mesmo que enviados de outra '
            . 'forma; nos dez campos novos de pessoa física, string vazia enviada volta como null. '
            . 'Campo do tipo que a pessoa NÃO é (física em jurídica, ou o contrário) é ignorado em silêncio, e PATCH nunca '
            . 'troca o tipo. A regra cruzada de dt_identidade_expedicao contra dt_nascimento só é avaliada quando as duas '
            . 'datas vêm no mesmo payload.',
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
    #[OA\Response(response: 404, description: 'Pessoa não encontrada', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 422, description: 'Dados inválidos (ou nenhum campo enviado)', content: new OA\JsonContent(ref: '#/components/schemas/ErroValidacao'))]
    public function atualizarParcial(int $id, PatchPessoaRequest $request): ResponseInterface
    {
        $pessoa = $this->pessoaService->atualizarParcial($id, IdentidadeContext::cdCliente(), Tipo::mapa($request->validated()));

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa)));
    }

    #[OA\Get(path: '/pessoas/{id}', summary: 'Busca uma pessoa pelo identificador', tags: ['Pessoa'])]
    #[OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))]
    #[OA\Parameter(
        name: 'fields',
        in: 'query',
        description: 'Campos a devolver, separados por vírgula (mesma sintaxe de GET /pessoas). '
            . 'Sem este parâmetro o detalhe devolve o registro completo MENOS o dado pessoal — diferente da listagem, que devolve um conjunto enxuto. '
            . 'Dado pessoal (fisica.ds_cpf, fisica.ds_identidade, fisica.ds_nome_mae, fisica.ds_nome_pai, fisica.dt_nascimento) só vem se pedido por nome ou por curinga (fisica.* ou *). '
            . 'Campos disponíveis: cd_pessoa, cd_cliente, ds_nome, ds_login, sn_pessoa_juridica, '
            . 'fisica.ds_nome_oficial, fisica.ds_nome_social, fisica.ds_nome_mae, fisica.ds_nome_pai, fisica.ds_cpf, '
            . 'fisica.ds_identidade, fisica.ds_orgao_estado, fisica.ds_identidade_orgao_exp, fisica.dt_identidade_expedicao, '
            . 'fisica.dt_nascimento, fisica.ds_sexo, fisica.cd_estado_civil, juridica.ds_cnpj, juridica.ds_nome_fantasia.',
        schema: new OA\Schema(type: 'string', example: 'ds_nome,fisica.ds_cpf')
    )]
    #[OA\Response(
        response: 200,
        description: 'Pessoa encontrada. Por padrão vem o registro completo SEM o dado pessoal; com ?fields= vem só o que foi pedido, dado pessoal incluso se pedido explicitamente. '
            . 'ATENÇÃO: mudança de contrato — ds_cpf costumava vir por padrão e agora não vem mais.',
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
    #[OA\Response(response: 404, description: 'Pessoa não encontrada', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: '#/components/schemas/ErroValidacao'))]
    public function buscar(int $id, BuscarPessoaRequest $request): ResponseInterface
    {
        $fields = Tipo::mapa($request->validated())['fields'] ?? null;
        $selecao = MapaDeCamposPessoa::selecao(is_string($fields) ? $fields : null, padraoEhTudo: true);

        $pessoa = $this->pessoaService->buscar($id, IdentidadeContext::cdCliente(), $selecao);

        return $this->response->json(ApiResponse::sucesso(PessoaResource::um($pessoa, $selecao)));
    }

    #[OA\Get(path: '/pessoas', summary: 'Lista pessoas do cliente autenticado', tags: ['Pessoa'])]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1))]
    #[OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100))]
    #[OA\Parameter(name: 'nome', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'tipo_pessoa', in: 'query', schema: new OA\Schema(type: 'string', enum: ['fisica', 'juridica']))]
    #[OA\Parameter(
        name: 'fields',
        in: 'query',
        description: 'Campos a devolver, separados por vírgula. Campo de relação usa ponto (fisica.ds_cpf), e relação inteira usa curinga (fisica.*). `fields=*` devolve tudo, dado pessoal incluso. '
            . 'ATENÇÃO: sem este parâmetro a LISTA devolve apenas cd_pessoa, ds_nome, ds_login e sn_pessoa_juridica — diferente de GET /pessoas/{id}, que devolve o registro completo menos o dado pessoal. '
            . 'Campos disponíveis: cd_pessoa, cd_cliente, ds_nome, ds_login, sn_pessoa_juridica, '
            . 'fisica.ds_nome_oficial, fisica.ds_nome_social, fisica.ds_nome_mae, fisica.ds_nome_pai, fisica.ds_cpf, '
            . 'fisica.ds_identidade, fisica.ds_orgao_estado, fisica.ds_identidade_orgao_exp, fisica.dt_identidade_expedicao, '
            . 'fisica.dt_nascimento, fisica.ds_sexo, fisica.cd_estado_civil, juridica.ds_cnpj, juridica.ds_nome_fantasia.',
        schema: new OA\Schema(type: 'string', example: 'ds_nome,fisica.ds_cpf')
    )]
    #[OA\Response(
        response: 200,
        description: 'Lista paginada. Sem ?fields=, cada item traz apenas os campos de PessoaResumida; '
            . 'com ?fields= (ou fields=*), cada item segue o schema Pessoa recortado pelo que foi pedido.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(
                    property: 'data',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/PessoaResumida')
                ),
                new OA\Property(property: 'meta', ref: '#/components/schemas/MetaPaginacao'),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 422, description: 'Dados inválidos', content: new OA\JsonContent(ref: '#/components/schemas/ErroValidacao'))]
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
    #[OA\Response(
        response: 200,
        description: 'Pessoa excluída. É soft delete: a linha permanece com dt_excluido preenchido e para de aparecer nas leituras.',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'boolean', example: true),
            ],
            type: 'object'
        )
    )]
    #[OA\Response(response: 401, description: 'Não autenticado', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 403, description: 'Sem permissão', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    #[OA\Response(response: 404, description: 'Pessoa não encontrada', content: new OA\JsonContent(ref: '#/components/schemas/Erro'))]
    public function excluir(int $id): ResponseInterface
    {
        $this->pessoaService->excluir($id, IdentidadeContext::cdCliente());

        return $this->response->json(ApiResponse::sucesso(null));
    }
}
