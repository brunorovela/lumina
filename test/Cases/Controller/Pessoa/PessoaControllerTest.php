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

namespace HyperfTest\Cases\Controller\Pessoa;

use App\Enum\Privilegio;
use App\Enum\Recurso;
use App\Service\Pessoa\CachePessoa;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Hyperf\Testing\TestCase;
use HyperfTest\Support\TenantDeTeste;

/**
 * @internal
 * @coversNothing
 */
class PessoaControllerTest extends TestCase
{
    private string $token;

    private int $cdPerfil;

    protected function setUp(): void
    {
        parent::setUp();

        // Ids fixos (cd_cliente=1, cd_perfis=[1]) não existem no banco: o insert de pessoa
        // batia na FK de saas_cliente e o erro chegava como 409, parecendo login duplicado.
        // O tenant descartável da suíte resolve os ids de verdade.
        $this->cdPerfil = TenantDeTeste::cdPerfil();

        $redis = $this->getContainer()->get(Redis::class);
        $this->token = bin2hex(random_bytes(32));
        $redis->setex("session:{$this->token}", 3600, json_encode([
            'cd_pessoa' => 1,
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'cd_perfis' => [$this->cdPerfil],
        ]));

        // garantir que o perfil de teste tem os privilégios de pessoa liberados nesta massa.
        // As chaves têm que ser as ds_chave reais do LMS (ulms_recurso / ulms_privilegio) —
        // chave inventada aqui esconde o bug em vez de testá-lo.
        $redis->setex("acl:perfil:{$this->cdPerfil}", 3600, json_encode([
            Recurso::GERENCIAR_PESSOA->value => [
                Privilegio::ACESSAR->value,
                Privilegio::INSERIR->value,
                Privilegio::ATUALIZAR->value,
                Privilegio::DELETAR->value,
            ],
        ]));
    }

    protected function tearDown(): void
    {
        $idsPessoa = Db::table('unim_pessoa')->where('ds_login', 'like', 'teste.http.%')->pluck('cd_pessoa');

        // O cache do detalhe sobrevive ao DELETE das linhas feito aqui (TTL de uma hora), e
        // um cd_pessoa cacheado sem linha no banco faria um teste posterior ler dado de
        // outro teste. Limpar a chave junto com a linha mantém os testes independentes.
        $redis = $this->getContainer()->get(Redis::class);
        $cache = $this->getContainer()->get(CachePessoa::class);

        foreach ($idsPessoa as $cdPessoa) {
            $redis->del($cache->chave(TenantDeTeste::cdCliente(), (int) $cdPessoa));
        }

        Db::table('unim_pessoa')->where('ds_login', 'like', 'teste.http.%')->delete();
        parent::tearDown();
    }

    public function testCriarBuscarAtualizarEExcluirPessoa()
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste',
            'ds_login' => 'teste.http.crud',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ], $this->headers());

        $criar->assertStatus(201);
        $cdPessoa = $criar->json('data.cd_pessoa');

        $buscar = $this->get("/pessoas/{$cdPessoa}", [], $this->headers());
        $buscar->assertStatus(200);
        $this->assertSame('Http Teste', $buscar->json('data.ds_nome'));

        $patch = $this->patch("/pessoas/{$cdPessoa}", ['ds_nome' => 'Http Teste Renomeado'], $this->headers());
        $patch->assertStatus(200);
        $this->assertSame('Http Teste Renomeado', $patch->json('data.ds_nome'));

        $excluir = $this->delete("/pessoas/{$cdPessoa}", [], $this->headers());
        $excluir->assertStatus(200);

        $buscarDepoisDeExcluir = $this->get("/pessoas/{$cdPessoa}", [], $this->headers());
        $buscarDepoisDeExcluir->assertStatus(404);
    }

    /**
     * A criação não toca unim_pessoa_fisica: essa tabela é de outro recurso. Antes, um POST
     * com sn_pessoa_juridica=false criava a linha filha junto — quem depender disso precisa
     * saber que mudou.
     */
    public function testCriarNaoEscreveNaTabelaDePessoaFisica()
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Sem Filho',
            'ds_login' => 'teste.http.semfilho',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ], $this->headers());

        $criar->assertStatus(201);
        $cdPessoa = $criar->json('data.cd_pessoa');

        $this->assertSame(0, Db::table('unim_pessoa_fisica')->where('cd_pessoa', $cdPessoa)->count());
        $this->assertSame(0, Db::table('unim_pessoa_juridica')->where('cd_pessoa', $cdPessoa)->count());
    }

    /**
     * O modo de falha que este endpoint NÃO pode ter: aceitar ds_cpf, responder 201 e não
     * gravar nada. A recusa vem com o nome do campo em errors, para o cliente antigo saber
     * exatamente o que saiu.
     */
    public function testCampoDePessoaFisicaNoPostResponde422ENaoEDescartadoEmSilencio()
    {
        $resposta = $this->json('/pessoas', [
            'ds_nome' => 'Http Campo Estranho',
            'ds_login' => 'teste.http.campoestranho',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_cpf' => '52998224725',
            'ds_nome_oficial' => 'Http Campo Estranho Oficial',
        ], $this->headers());

        $resposta->assertStatus(422);
        $this->assertArrayHasKey('ds_cpf', $resposta->json('errors'));
        $this->assertArrayHasKey('ds_nome_oficial', $resposta->json('errors'));
        $this->assertSame(0, Db::table('unim_pessoa')->where('ds_login', 'teste.http.campoestranho')->count());
    }

    public function testCampoDePessoaJuridicaNoPutResponde422()
    {
        $cdPessoa = $this->criarPessoa('teste.http.putestranho', 'Http Put Estranho');

        $resposta = $this->put("/pessoas/{$cdPessoa}", [
            'ds_nome' => 'Http Put Estranho',
            'ds_login' => 'teste.http.putestranho',
            'sn_pessoa_juridica' => true,
            'ds_cnpj' => '00000000000191',
            'ds_nome_fantasia' => 'Http Put Estranho Fantasia',
        ], $this->headers());

        $resposta->assertStatus(422);
        $this->assertArrayHasKey('ds_cnpj', $resposta->json('errors'));
        $this->assertArrayHasKey('ds_nome_fantasia', $resposta->json('errors'));
    }

    public function testCampoDePessoaFisicaNoPatchResponde422()
    {
        $cdPessoa = $this->criarPessoa('teste.http.patchestranho', 'Http Patch Estranho');

        $resposta = $this->patch("/pessoas/{$cdPessoa}", ['ds_nome_social' => 'Patchado'], $this->headers());

        $resposta->assertStatus(422);
        $this->assertArrayHasKey('ds_nome_social', $resposta->json('errors'));
    }

    /**
     * PATCH passou a aceitar sn_pessoa_juridica: o motivo de ele ser recusado antes era a
     * escrita nas tabelas filhas, que saiu desta API. Trocar o tipo não mexe em
     * unim_pessoa_fisica/unim_pessoa_juridica.
     */
    public function testPatchPodeTrocarOTipoESemMexerNaTabelaDoOutroRecurso()
    {
        $cdPessoa = $this->criarPessoa('teste.http.patchtipo', 'Http Patch Tipo');

        // Linha de física inserida à mão simula o que o outro recurso (ou o LMS legado)
        // teria gravado: o PATCH não pode apagá-la.
        Db::table('unim_pessoa_fisica')->insert(['cd_pessoa' => $cdPessoa, 'ds_nome_oficial' => 'Http Patch Tipo Oficial']);

        $patch = $this->patch("/pessoas/{$cdPessoa}", ['sn_pessoa_juridica' => true], $this->headers());

        $patch->assertStatus(200);
        $this->assertTrue($patch->json('data.sn_pessoa_juridica'));
        $this->assertSame(1, Db::table('unim_pessoa_fisica')->where('cd_pessoa', $cdPessoa)->count());

        Db::table('unim_pessoa_fisica')->where('cd_pessoa', $cdPessoa)->delete();
    }

    /**
     * PUT invertendo o tipo NÃO apaga mais a linha do tipo antigo — antes apagava, e com
     * ela o CPF, sem confirmação. Agora esse dado é de outro recurso.
     */
    public function testPutTrocandoOTipoNaoApagaMaisALinhaDoOutroRecurso()
    {
        $cdPessoa = $this->criarPessoa('teste.http.trocatipo', 'Http Troca Tipo');

        Db::table('unim_pessoa_fisica')->insert(['cd_pessoa' => $cdPessoa, 'ds_nome_oficial' => 'Http Troca Tipo Oficial']);

        $atualizar = $this->put("/pessoas/{$cdPessoa}", [
            'ds_nome' => 'Http Troca Tipo',
            'ds_login' => 'teste.http.trocatipo',
            'sn_pessoa_juridica' => true,
        ], $this->headers());

        $atualizar->assertStatus(200);
        $this->assertTrue($atualizar->json('data.sn_pessoa_juridica'));
        $this->assertSame(1, Db::table('unim_pessoa_fisica')->where('cd_pessoa', $cdPessoa)->count());

        Db::table('unim_pessoa_fisica')->where('cd_pessoa', $cdPessoa)->delete();
    }

    public function testCriarExcluirERecriarComMesmoLoginDevolveLoginJaExisteEmVezDeErroGenericoDeBanco()
    {
        // Finding 4 (whole-branch review): o índice UNIQUE (cd_cliente, ds_login) do banco
        // não sabe o que é soft-delete -- sem loginExiste() considerar withTrashed(),
        // recriar uma pessoa com o login de uma já excluída batia direto no índice do
        // banco (409 genérico do DatabaseExceptionHandler) em vez de passar pela checagem
        // de negócio (LoginJaExisteException, mensagem clara).
        $login = 'teste.http.loginreciclado';

        $cdPessoa = $this->criarPessoa($login, 'Http Teste Login Reciclado');

        $this->delete("/pessoas/{$cdPessoa}", [], $this->headers())->assertStatus(200);

        $recriar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Login Reciclado Duas',
            'ds_login' => $login,
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ], $this->headers());

        $recriar->assertStatus(409);
        $this->assertSame(
            'Já existe uma pessoa com esse login para este cliente.',
            $recriar->json('message')
        );
    }

    public function testListarComFiltroDeNomeEPaginacao()
    {
        $this->criarPessoa('teste.http.lista1', 'Http Lista Um');

        $listar = $this->get('/pessoas?nome=Lista&per_page=10', [], $this->headers());

        $listar->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $listar->json('meta.total'));
    }

    public function testMetaPerPageEUltimaPaginaRefletemOValorClampadoNaoOOriginal()
    {
        // Finding 5 (whole-branch review): PessoaService::listar() clampa per_page pra
        // 100 internamente, mas o Controller montava o meta com o per_page ORIGINAL do
        // request -- meta.per_page/last_page mentiam quando o cliente pedia per_page > 100.
        $listar = $this->get('/pessoas?per_page=500', [], $this->headers());

        $listar->assertStatus(200);
        $this->assertSame(100, $listar->json('meta.per_page'));
    }

    public function testListaSemFieldsDevolveApenasOConjuntoEnxuto()
    {
        // O tenant de teste é compartilhado pela suíte inteira (TenantDeTeste::limpar() só
        // roda no início/fim, não por teste), e PessoaRepositoryTest cria uma "Selecao
        // Enxuta" — filtrar por "Enxuta" casaria as duas. O termo aqui precisa ser único.
        $this->criarPessoa('teste.http.enxuta', 'Http Enxuta Unica');

        $listar = $this->get('/pessoas?nome=Enxuta Unica', [], $this->headers());

        $listar->assertStatus(200);
        $item = $listar->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'ds_nome', 'ds_login', 'sn_pessoa_juridica'],
            array_keys($item)
        );
        $this->assertArrayNotHasKey('cd_cliente', $item);
    }

    public function testListaComFieldsAsteriscoDevolveSoAsColunasDePessoa()
    {
        // Termo de filtro único: o tenant de teste é compartilhado pela suíte inteira, então
        // um filtro genérico correria o risco de casar pessoa criada por outro teste.
        $this->criarPessoa('teste.http.completa', 'Http Completa Unica');

        $item = $this->get('/pessoas?nome=Completa Unica&fields=*', [], $this->headers())->json('data.0');

        // fields=* não traz mais fisica/juridica: o mapa não tem relação nenhuma.
        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'cd_cliente', 'ds_nome', 'ds_login', 'sn_pessoa_juridica'],
            array_keys($item)
        );
    }

    public function testMetaDaPaginacaoNaoMudaComFields()
    {
        $listar = $this->get('/pessoas?fields=ds_nome&per_page=10', [], $this->headers());

        $listar->assertStatus(200);
        $this->assertSame(10, $listar->json('meta.per_page'));
        $this->assertIsInt($listar->json('meta.total'));

        // A invariante real: o SELECT parcial não pode afetar o count() usado no total da
        // paginação. assertIsInt acima passaria com qualquer inteiro, inclusive um total
        // errado -- aqui comparamos o total do contrato completo com o do enxuto.
        $totalCompleto = $this->get('/pessoas?fields=*', [], $this->headers())->json('meta.total');
        $totalEnxuto = $this->get('/pessoas?fields=ds_nome', [], $this->headers())->json('meta.total');

        $this->assertSame($totalCompleto, $totalEnxuto);
    }

    public function testSemTokenRetorna401()
    {
        $this->get('/pessoas')->assertStatus(401);
    }

    public function testSemTokenEComPayloadInvalidoRetorna401NaoMais422()
    {
        // Finding 8 (whole-branch review, revisitando a limitação conhecida da Task 14):
        // ValidationMiddleware era global (config/autoload/middlewares.php) e rodava ANTES
        // de AuthMiddleware/AclMiddleware (por rota) -- um cliente sem token descobria a
        // forma do contrato de validação (quais campos existem, quais são obrigatórios)
        // sem nunca ter se autenticado.
        //
        // A correção NÃO usa Hyperf\HttpServer\PriorityMiddleware/sortMiddlewares() (a
        // limitação original apontava, corretamente, que Hyperf\Testing\Http\Client --
        // usado por todo o harness de teste -- não chama sortMiddlewares(), então
        // PriorityMiddleware quebraria a suíte). Em vez disso, ValidationMiddleware saiu
        // do array global e passou a ser declarado no 'middleware' de cada rota (ver
        // config/routes.php), APÓS AuthMiddleware/AclMiddleware na mesma lista --
        // RouteCollector::mergeOptions() (array_merge_recursive) preserva essa ordem de
        // aparição sem precisar de nenhum mecanismo de prioridade, e tanto o servidor real
        // quanto Hyperf\Testing\Http\Client respeitam a ordem simples de concatenação.
        $criar = $this->json('/pessoas', []);

        $this->assertSame(401, $criar->getStatusCode());
    }

    public function testMensagemDeValidacaoVemEmInglesNaoEmChines()
    {
        // Fix round 1, Finding 1: sem config/autoload/translation.php, o TranslatorFactory
        // do Hyperf cai no default do pacote (zh_CN) e QUALQUER erro 422 saía em chinês.
        $resposta = $this->json('/pessoas', [], $this->headers());

        $resposta->assertStatus(422);
        $mensagem = $resposta->json('errors.ds_nome.0');
        $this->assertSame('The ds nome field is required.', $mensagem);
    }

    public function testSemPermissaoAclRetorna403()
    {
        $redis = $this->getContainer()->get(Redis::class);
        $redis->setex("acl:perfil:{$this->cdPerfil}", 3600, json_encode([Recurso::GERENCIAR_PESSOA->value => []]));

        $this->get('/pessoas', [], $this->headers())->assertStatus(403);
    }

    /**
     * GET /pessoas exige GERENCIAR_PESSOA + ACESSAR. Ter só INSERIR/ATUALIZAR (escrita)
     * não pode liberar leitura — é o pareamento recurso+privilégio que precisa bater.
     */
    public function testPrivilegioDeEscritaNaoLiberaLeitura()
    {
        $redis = $this->getContainer()->get(Redis::class);
        $redis->setex("acl:perfil:{$this->cdPerfil}", 3600, json_encode([
            Recurso::GERENCIAR_PESSOA->value => [
                Privilegio::INSERIR->value,
                Privilegio::ATUALIZAR->value,
            ],
        ]));

        $this->get('/pessoas', [], $this->headers())->assertStatus(403);
    }

    /**
     * Regressão do bug relatado: as chaves antigas ('pessoa' / 'criar'|'listar'|...) não
     * existem em ulms_recurso / ulms_privilegio. Um cache montado com elas tem que dar
     * 403 — se der 200, alguém voltou a comparar chave inventada.
     */
    public function testChavesAntigasMinusculasNaoConcedemNada()
    {
        $redis = $this->getContainer()->get(Redis::class);
        $redis->setex("acl:perfil:{$this->cdPerfil}", 3600, json_encode([
            'pessoa' => ['criar', 'atualizar', 'visualizar', 'listar', 'excluir'],
        ]));

        $this->get('/pessoas', [], $this->headers())->assertStatus(403);
    }

    public function testAtualizarParcialComPayloadVazioRetorna422()
    {
        $cdPessoa = $this->criarPessoa('teste.http.patchvazio', 'Http Teste Patch Vazio');

        $patch = $this->patch("/pessoas/{$cdPessoa}", [], $this->headers());

        $patch->assertStatus(422);
    }

    public function testFieldsComCampoInexistenteRetorna422()
    {
        $resposta = $this->get('/pessoas?fields=ds_nome,ds_nomee', [], $this->headers());

        $resposta->assertStatus(422);
        $this->assertContains('Campo não permitido: ds_nomee.', $resposta->json('errors.fields'));
    }

    /**
     * ds_senha não está no mapa, então recebe exatamente a mesma mensagem de um typo — a
     * resposta não pode confirmar que a coluna existe.
     */
    public function testFieldsComDsSenhaRetorna422ComAMesmaMensagemDeUmTypo()
    {
        $resposta = $this->get('/pessoas?fields=ds_senha', [], $this->headers());

        $resposta->assertStatus(422);
        $this->assertSame(['Campo não permitido: ds_senha.'], $resposta->json('errors.fields'));
    }

    /**
     * Campo de relação saiu do mapa junto com as tabelas filhas: quem tinha ?fields=fisica.*
     * no cliente precisa de um erro, não de uma resposta silenciosamente sem o dado.
     */
    public function testFieldsDeRelacaoAgoraRetorna422NaListaENoDetalhe()
    {
        $cdPessoa = $this->criarPessoa('teste.http.fieldsrelacao', 'Http Fields Relacao');

        $lista = $this->get('/pessoas?fields=ds_nome,fisica.ds_cpf', [], $this->headers());
        $lista->assertStatus(422);
        $this->assertContains('Campo não permitido: fisica.ds_cpf.', $lista->json('errors.fields'));

        $curinga = $this->get('/pessoas?fields=fisica.*', [], $this->headers());
        $curinga->assertStatus(422);
        $this->assertContains('Campo não permitido: fisica.*.', $curinga->json('errors.fields'));

        $detalhe = $this->get("/pessoas/{$cdPessoa}?fields=juridica.ds_cnpj", [], $this->headers());
        $detalhe->assertStatus(422);
        $this->assertContains('Campo não permitido: juridica.ds_cnpj.', $detalhe->json('errors.fields'));
    }

    public function testFieldsValidoNaoCaiNa422()
    {
        $this->get('/pessoas?fields=ds_nome,cd_cliente', [], $this->headers())->assertStatus(200);
        $this->get('/pessoas?fields=*', [], $this->headers())->assertStatus(200);
        $this->get('/pessoas?fields=', [], $this->headers())->assertStatus(200);
    }

    public function testDetalheSemFieldsDevolveCompleto()
    {
        $cdPessoa = $this->criarPessoa('teste.http.detalhe', 'Http Detalhe');

        $item = $this->get("/pessoas/{$cdPessoa}", [], $this->headers())->json('data');

        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'cd_cliente', 'ds_nome', 'ds_login', 'sn_pessoa_juridica'],
            array_keys($item)
        );
        $this->assertSame('Http Detalhe', $item['ds_nome']);
    }

    public function testDetalheComFieldsRecorta()
    {
        $cdPessoa = $this->criarPessoa('teste.http.detalherecorte', 'Http Detalhe Recorte');

        $item = $this->get("/pessoas/{$cdPessoa}?fields=ds_nome", [], $this->headers())->json('data');

        $this->assertSame(['ds_nome' => 'Http Detalhe Recorte'], $item);
    }

    public function testDetalheComCampoInvalidoRetorna422()
    {
        $resposta = $this->get('/pessoas/1?fields=ds_senha', [], $this->headers());

        $resposta->assertStatus(422);
        $this->assertSame(['Campo não permitido: ds_senha.'], $resposta->json('errors.fields'));
    }

    /**
     * Resposta de escrita filtrada esconderia o que o servidor gravou, então fields é
     * ignorado em POST/PUT/PATCH de propósito.
     */
    public function testEscritaIgnoraFieldsEDevolveCompleto()
    {
        $criar = $this->json('/pessoas?fields=ds_nome', [
            'ds_nome' => 'Http Escrita Fields',
            'ds_login' => 'teste.http.escritafields',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ], $this->headers());

        $criar->assertStatus(201);
        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'cd_cliente', 'ds_nome', 'ds_login', 'sn_pessoa_juridica'],
            array_keys($criar->json('data'))
        );

        $cdPessoa = $criar->json('data.cd_pessoa');

        $patch = $this->patch("/pessoas/{$cdPessoa}?fields=ds_nome", ['ds_nome' => 'Http Escrita Fields Dois'], $this->headers());

        $patch->assertStatus(200);
        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'cd_cliente', 'ds_nome', 'ds_login', 'sn_pessoa_juridica'],
            array_keys($patch->json('data'))
        );
    }

    /**
     * A ordem Auth/Acl antes de Validation vale também na rota do detalhe, que ganhou
     * ValidationMiddleware junto com o ?fields=: sem token, o cliente recebe 401 e não
     * descobre nada sobre os campos aceitos.
     */
    public function testDetalheSemTokenEComFieldsInvalidoRetorna401NaoMais422()
    {
        $this->get('/pessoas/1?fields=ds_senha')->assertStatus(401);
    }

    /**
     * Important 3 da revisão final: todo teste de "não encontrado" da suíte usava
     * cd_pessoa = 999999, uma linha que não existe em NENHUM cliente -- isso passaria
     * mesmo se PessoaRepository::buscarPorId() perdesse o ->where('cd_cliente', ...), já
     * que a linha simplesmente não existe em lugar nenhum. O teste de isolamento
     * cross-tenant precisa criar uma pessoa que EXISTE, só que de outro cliente, e provar
     * que ela não vaza -- nem pelo banco, nem pelo cache do detalhe (a chave de cache
     * inclui cd_cliente exatamente por isso).
     */
    public function testDetalheDeOutroTenantRetorna404MesmoComOCacheJaQuente()
    {
        $cdPessoa = $this->criarPessoa('teste.http.crosstenant', 'Http Teste Cross Tenant');

        // Cache quente para o tenant DONO da pessoa: se a chave não fosse por tenant, a
        // leitura do outro tenant abaixo devolveria este dado sem nem chegar ao banco.
        $this->get("/pessoas/{$cdPessoa}", [], $this->headers())->assertStatus(200);

        // Segundo tenant fabricado direto no Redis, mesmo padrão de
        // EndToEndFlowTest::testIsolamentoCrossTenantPessoaDeUmClienteNaoApareceParaOutro
        // -- cd_cliente inexistente não precisa ter linha no banco, só precisa ser
        // diferente do tenant que criou a pessoa.
        $redis = $this->getContainer()->get(Redis::class);
        $cdPerfilOutroTenant = 900003;
        $tokenOutroTenant = bin2hex(random_bytes(32));

        $redis->setex("session:{$tokenOutroTenant}", 3600, json_encode([
            'cd_pessoa' => 1,
            'cd_cliente' => TenantDeTeste::cdClienteInexistente(),
            'cd_perfis' => [$cdPerfilOutroTenant],
        ]));
        $redis->setex("acl:perfil:{$cdPerfilOutroTenant}", 3600, json_encode([
            Recurso::GERENCIAR_PESSOA->value => [Privilegio::ACESSAR->value],
        ]));

        $headersOutroTenant = ['Authorization' => "Bearer {$tokenOutroTenant}"];

        $buscar = $this->get("/pessoas/{$cdPessoa}", ['fields' => '*'], $headersOutroTenant);

        $buscar->assertStatus(404);
        $this->assertArrayNotHasKey('data', $buscar->json());

        $redis->del("session:{$tokenOutroTenant}");
        $redis->del("acl:perfil:{$cdPerfilOutroTenant}");
    }

    /**
     * O contrato do cache: a primeira leitura vai ao banco e grava a chave com TTL de uma
     * hora; a segunda é servida do cache. A prova é uma alteração feita DIRETO no banco
     * (que a API não vê passar) — se a segunda leitura trouxesse o valor novo, não havia
     * cache nenhum.
     */
    public function testSegundaLeituraVemDoCacheENaoDoBanco()
    {
        $cdPessoa = $this->criarPessoa('teste.http.cache', 'Http Cache Original');
        $chave = $this->getContainer()->get(CachePessoa::class)->chave(TenantDeTeste::cdCliente(), $cdPessoa);
        $redis = $this->getContainer()->get(Redis::class);

        $redis->del($chave);

        $primeira = $this->get("/pessoas/{$cdPessoa}", [], $this->headers());
        $primeira->assertStatus(200);
        $this->assertSame('Http Cache Original', $primeira->json('data.ds_nome'));

        $this->assertNotEmpty($redis->get($chave));
        $ttl = $redis->ttl($chave);
        $this->assertGreaterThan(3500, $ttl);
        $this->assertLessThanOrEqual(3600, $ttl);

        Db::table('unim_pessoa')->where('cd_pessoa', $cdPessoa)->update(['ds_nome' => 'Http Cache Mudado No Banco']);

        $segunda = $this->get("/pessoas/{$cdPessoa}", [], $this->headers());
        $segunda->assertStatus(200);
        $this->assertSame('Http Cache Original', $segunda->json('data.ds_nome'));

        // O recorte por fields roda sobre o dado cacheado: a mesma chave serve qualquer
        // combinação, então este pedido também vem do cache (valor antigo).
        $recortada = $this->get("/pessoas/{$cdPessoa}?fields=ds_nome", [], $this->headers());
        $this->assertSame(['ds_nome' => 'Http Cache Original'], $recortada->json('data'));
    }

    public function testPutInvalidaOCacheDoDetalhe()
    {
        $cdPessoa = $this->criarPessoa('teste.http.cacheput', 'Http Cache Put');

        $this->get("/pessoas/{$cdPessoa}", [], $this->headers())->assertStatus(200);

        $this->put("/pessoas/{$cdPessoa}", [
            'ds_nome' => 'Http Cache Put Novo',
            'ds_login' => 'teste.http.cacheput',
            'sn_pessoa_juridica' => false,
        ], $this->headers())->assertStatus(200);

        $depois = $this->get("/pessoas/{$cdPessoa}", [], $this->headers());
        $this->assertSame('Http Cache Put Novo', $depois->json('data.ds_nome'));
    }

    public function testPatchInvalidaOCacheDoDetalhe()
    {
        $cdPessoa = $this->criarPessoa('teste.http.cachepatch', 'Http Cache Patch');

        $this->get("/pessoas/{$cdPessoa}", [], $this->headers())->assertStatus(200);

        $this->patch("/pessoas/{$cdPessoa}", ['ds_nome' => 'Http Cache Patch Novo'], $this->headers())->assertStatus(200);

        $depois = $this->get("/pessoas/{$cdPessoa}", [], $this->headers());
        $this->assertSame('Http Cache Patch Novo', $depois->json('data.ds_nome'));
    }

    /**
     * Sem invalidação no DELETE, a pessoa excluída continuaria respondendo 200 por até uma
     * hora — o soft delete some do banco na hora, o cache não.
     */
    public function testDeleteInvalidaOCacheEODetalhePassaAResponder404()
    {
        $cdPessoa = $this->criarPessoa('teste.http.cachedelete', 'Http Cache Delete');
        $chave = $this->getContainer()->get(CachePessoa::class)->chave(TenantDeTeste::cdCliente(), $cdPessoa);
        $redis = $this->getContainer()->get(Redis::class);

        $this->get("/pessoas/{$cdPessoa}", [], $this->headers())->assertStatus(200);
        $this->assertNotEmpty($redis->get($chave));

        $this->delete("/pessoas/{$cdPessoa}", [], $this->headers())->assertStatus(200);

        $this->assertFalse($redis->get($chave));
        $this->get("/pessoas/{$cdPessoa}", [], $this->headers())->assertStatus(404);
    }

    /**
     * Cache corrompido (formato antigo, escrita manual em debug, JSON truncado) precisa cair
     * para o banco em vez de virar resposta pela metade.
     */
    public function testCacheCorrompidoCaiParaOBanco()
    {
        $cdPessoa = $this->criarPessoa('teste.http.cachelixo', 'Http Cache Lixo');
        $chave = $this->getContainer()->get(CachePessoa::class)->chave(TenantDeTeste::cdCliente(), $cdPessoa);
        $redis = $this->getContainer()->get(Redis::class);

        $redis->setex($chave, 3600, '{"ds_nome":"So Metade Do Registro"}');

        $resposta = $this->get("/pessoas/{$cdPessoa}", [], $this->headers());

        $resposta->assertStatus(200);
        $this->assertSame('Http Cache Lixo', $resposta->json('data.ds_nome'));
        $this->assertSame(TenantDeTeste::cdCliente(), $resposta->json('data.cd_cliente'));
    }

    private function criarPessoa(string $login, string $nome): int
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => $nome,
            'ds_login' => $login,
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ], $this->headers());

        $criar->assertStatus(201);

        return (int) $criar->json('data.cd_pessoa');
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }
}
