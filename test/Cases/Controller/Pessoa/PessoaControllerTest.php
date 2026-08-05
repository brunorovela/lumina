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
        Db::table('unim_pessoa_fisica')->whereIn('cd_pessoa', $idsPessoa)->delete();
        Db::table('unim_pessoa_juridica')->whereIn('cd_pessoa', $idsPessoa)->delete();
        Db::table('unim_pessoa')->where('ds_login', 'like', 'teste.http.%')->delete();
        parent::tearDown();
    }

    public function testCriarBuscarAtualizarEExcluirPessoaFisica()
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste',
            'ds_login' => 'teste.http.crud',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Oficial',
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

    public function testPutTrocandoFisicaParaJuridicaApagaOFilhoAntigo()
    {
        // CRITICAL Finding 1 (whole-branch review): PUT trocando o tipo pessoa
        // (física -> jurídica) não podia deixar a linha antiga em unim_pessoa_fisica
        // órfã (pessoa com fisica E juridica preenchidas ao mesmo tempo).
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Troca Tipo',
            'ds_login' => 'teste.http.trocatipo',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Troca Tipo Oficial',
        ], $this->headers());

        $criar->assertStatus(201);
        $cdPessoa = $criar->json('data.cd_pessoa');
        $this->assertNotNull($criar->json('data.fisica'));

        $atualizar = $this->put("/pessoas/{$cdPessoa}", [
            'ds_nome' => 'Http Teste Troca Tipo',
            'ds_login' => 'teste.http.trocatipo',
            'sn_pessoa_juridica' => true,
            'ds_cnpj' => '00000000000191',
            'ds_nome_fantasia' => 'Http Teste Troca Tipo Fantasia',
        ], $this->headers());

        $atualizar->assertStatus(200);
        $this->assertNull($atualizar->json('data.fisica'));
        $this->assertNotNull($atualizar->json('data.juridica'));
        $this->assertSame('00000000000191', $atualizar->json('data.juridica.ds_cnpj'));

        $linhaFisicaOrfa = Db::table('unim_pessoa_fisica')->where('cd_pessoa', $cdPessoa)->first();
        $this->assertNull($linhaFisicaOrfa);

        $linhaJuridica = Db::table('unim_pessoa_juridica')->where('cd_pessoa', $cdPessoa)->first();
        $this->assertNotNull($linhaJuridica);
    }

    public function testCriarExcluirERecriarComMesmoLoginDevolveLoginJaExisteEmVezDeErroGenericoDeBanco()
    {
        // Finding 4 (whole-branch review): o índice UNIQUE (cd_cliente, ds_login) do banco
        // não sabe o que é soft-delete -- sem loginExiste() considerar withTrashed(),
        // recriar uma pessoa com o login de uma já excluída batia direto no índice do
        // banco (409 genérico do DatabaseExceptionHandler) em vez de passar pela checagem
        // de negócio (LoginJaExisteException, mensagem clara).
        $login = 'teste.http.loginreciclado';

        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Login Reciclado',
            'ds_login' => $login,
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Login Reciclado',
        ], $this->headers());

        $criar->assertStatus(201);
        $cdPessoa = $criar->json('data.cd_pessoa');

        $this->delete("/pessoas/{$cdPessoa}", [], $this->headers())->assertStatus(200);

        $recriar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Login Reciclado Duas',
            'ds_login' => $login,
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Login Reciclado Duas',
        ], $this->headers());

        $recriar->assertStatus(409);
        $this->assertSame(
            'Já existe uma pessoa com esse login para este cliente.',
            $recriar->json('message')
        );
    }

    public function testListarComFiltroDeNomeEPaginacao()
    {
        $this->json('/pessoas', [
            'ds_nome' => 'Http Lista Um',
            'ds_login' => 'teste.http.lista1',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Lista Um',
        ], $this->headers());

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
        $this->json('/pessoas', [
            'ds_nome' => 'Http Enxuta Unica',
            'ds_login' => 'teste.http.enxuta',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Enxuta Oficial',
        ], $this->headers())->assertStatus(201);

        $listar = $this->get('/pessoas?nome=Enxuta Unica', [], $this->headers());

        $listar->assertStatus(200);
        $item = $listar->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'ds_nome', 'ds_login', 'sn_pessoa_juridica'],
            array_keys($item)
        );
        $this->assertArrayNotHasKey('fisica', $item);
        $this->assertArrayNotHasKey('cd_cliente', $item);
    }

    public function testListaComFieldsAsteriscoMantemOContratoAntigo()
    {
        // Termo de filtro único: o tenant de teste é compartilhado pela suíte inteira, então
        // um filtro genérico correria o risco de casar pessoa criada por outro teste.
        $this->json('/pessoas', [
            'ds_nome' => 'Http Completa Unica',
            'ds_login' => 'teste.http.completa',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Completa Oficial',
        ], $this->headers())->assertStatus(201);

        $item = $this->get('/pessoas?nome=Completa Unica&fields=*', [], $this->headers())->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'cd_cliente', 'ds_nome', 'ds_login', 'sn_pessoa_juridica', 'fisica', 'juridica'],
            array_keys($item)
        );
        $this->assertSame('Http Completa Oficial', $item['fisica']['ds_nome_oficial']);
    }

    public function testListaComFieldsDeRelacaoDevolveAninhadoSemVazarChaveDeJoin()
    {
        // Termo de filtro único: o tenant de teste é compartilhado pela suíte inteira, então
        // um filtro genérico correria o risco de casar pessoa criada por outro teste.
        $this->json('/pessoas', [
            'ds_nome' => 'Http Relacao Unica',
            'ds_login' => 'teste.http.relacao',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Relacao Oficial',
            // Task 7 passou a validar o dígito verificador; '99988877766' (usado antes) não
            // fecha a conta e o create cairia em 422. Trocado por um CPF de mesma "família"
            // com DV válido -- o valor em si é irrelevante para o que este teste prova
            // (fields de relação sem vazar chave de join).
            'ds_cpf' => '99988877714',
        ], $this->headers())->assertStatus(201);

        $item = $this->get('/pessoas?nome=Relacao Unica&fields=ds_nome,fisica.ds_cpf', [], $this->headers())->json('data.0');

        $this->assertSame(['ds_nome' => 'Http Relacao Unica', 'fisica' => ['ds_cpf' => '99988877714']], $item);
    }

    public function testListaComRelacaoPedidaEmPessoaDoOutroTipoDevolveNulo()
    {
        // Termo de filtro único: o tenant de teste é compartilhado pela suíte inteira, então
        // um filtro genérico correria o risco de casar pessoa criada por outro teste.
        $this->json('/pessoas', [
            'ds_nome' => 'Http Juridica Selecao Unica',
            'ds_login' => 'teste.http.juridicasel',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => true,
            'ds_cnpj' => '00000000000191',
            'ds_nome_fantasia' => 'Http Juridica Fantasia',
        ], $this->headers())->assertStatus(201);

        $item = $this->get('/pessoas?nome=Juridica Selecao Unica&fields=ds_nome,fisica.ds_cpf', [], $this->headers())->json('data.0');

        $this->assertArrayHasKey('fisica', $item);
        $this->assertNull($item['fisica']);
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
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Patch Vazio',
            'ds_login' => 'teste.http.patchvazio',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Patch Vazio',
        ], $this->headers());

        $cdPessoa = $criar->json('data.cd_pessoa');

        $patch = $this->patch("/pessoas/{$cdPessoa}", [], $this->headers());

        $patch->assertStatus(422);
    }

    public function testPatchComCampoDeTipoErradoIgnoraOCampoENaoCriaFilhoErrado()
    {
        // Finding 14 (whole-branch review): PATCH aceitava ds_cnpj numa pessoa física e
        // criava uma linha em unim_pessoa_juridica pra ela.
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Patch Tipo Errado',
            'ds_login' => 'teste.http.patchtipoerrado',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Patch Tipo Errado',
        ], $this->headers());

        $cdPessoa = $criar->json('data.cd_pessoa');

        $patch = $this->patch("/pessoas/{$cdPessoa}", [
            'ds_cnpj' => '00000000000191',
            'ds_nome_fantasia' => 'Nao Deveria Existir',
        ], $this->headers());

        $patch->assertStatus(200);
        $this->assertNull($patch->json('data.juridica'));

        $linhaJuridica = Db::table('unim_pessoa_juridica')->where('cd_pessoa', $cdPessoa)->first();
        $this->assertNull($linhaJuridica);
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

    public function testFieldsValidoNaoCaiNa422()
    {
        $this->get('/pessoas?fields=ds_nome,fisica.ds_cpf', [], $this->headers())->assertStatus(200);
        $this->get('/pessoas?fields=*', [], $this->headers())->assertStatus(200);
        $this->get('/pessoas?fields=fisica.*', [], $this->headers())->assertStatus(200);
        $this->get('/pessoas?fields=', [], $this->headers())->assertStatus(200);
    }

    public function testDetalheSemFieldsDevolveCompleto()
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Detalhe',
            'ds_login' => 'teste.http.detalhe',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Detalhe Oficial',
        ], $this->headers());

        $criar->assertStatus(201);
        $cdPessoa = $criar->json('data.cd_pessoa');

        $item = $this->get("/pessoas/{$cdPessoa}", [], $this->headers())->json('data');

        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'cd_cliente', 'ds_nome', 'ds_login', 'sn_pessoa_juridica', 'fisica', 'juridica'],
            array_keys($item)
        );
        $this->assertSame('Http Detalhe Oficial', $item['fisica']['ds_nome_oficial']);
    }

    public function testDetalheComFieldsRecorta()
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Detalhe Recorte',
            'ds_login' => 'teste.http.detalherecorte',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Detalhe Recorte Oficial',
        ], $this->headers());

        $cdPessoa = $criar->json('data.cd_pessoa');

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
            'ds_nome_oficial' => 'Http Escrita Fields Oficial',
        ], $this->headers());

        $criar->assertStatus(201);
        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'cd_cliente', 'ds_nome', 'ds_login', 'sn_pessoa_juridica', 'fisica', 'juridica'],
            array_keys($criar->json('data'))
        );

        $cdPessoa = $criar->json('data.cd_pessoa');

        $patch = $this->patch("/pessoas/{$cdPessoa}?fields=ds_nome", ['ds_nome' => 'Http Escrita Fields Dois'], $this->headers());

        $patch->assertStatus(200);
        $this->assertEqualsCanonicalizing(
            ['cd_pessoa', 'cd_cliente', 'ds_nome', 'ds_login', 'sn_pessoa_juridica', 'fisica', 'juridica'],
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

    public function testDetalheNaoDevolvePiiSemPedidoExplicito()
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste PII',
            'ds_login' => 'teste.http.pii',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste PII Oficial',
            'ds_cpf' => '12345678909',
        ], $this->headers());

        $criar->assertStatus(201);
        // Resposta de escrita traz PII: o cliente precisa confirmar o que foi gravado.
        $this->assertSame('12345678909', $criar->json('data.fisica.ds_cpf'));

        $cdPessoa = $criar->json('data.cd_pessoa');

        $semFields = $this->get("/pessoas/{$cdPessoa}", [], $this->headers());
        $semFields->assertStatus(200);
        $this->assertArrayNotHasKey('ds_cpf', $semFields->json('data.fisica'));

        $porNome = $this->get("/pessoas/{$cdPessoa}", ['fields' => 'fisica.ds_cpf'], $this->headers());
        $porNome->assertStatus(200);
        $this->assertSame('12345678909', $porNome->json('data.fisica.ds_cpf'));

        $porCuringa = $this->get("/pessoas/{$cdPessoa}", ['fields' => 'fisica.*'], $this->headers());
        $porCuringa->assertStatus(200);
        $this->assertSame('12345678909', $porCuringa->json('data.fisica.ds_cpf'));
    }

    public function testCpfComMascaraPassaPelaRegraDigits()
    {
        // A normalização roda em validationData(), ANTES das regras: sem ela, "123.456.789-09"
        // reprovaria em digits:11 e este teste veria 422. ds_cpf já é persistido hoje, então
        // aqui a asserção sobre o valor gravado é legítima antes da Task 8.
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Mascara',
            'ds_login' => 'teste.http.mascara',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Mascara Oficial',
            'ds_cpf' => '123.456.789-09',
        ], $this->headers());

        $criar->assertStatus(201);
        $this->assertSame('12345678909', $criar->json('data.fisica.ds_cpf'));
    }

    public function testCpfComDigitoVerificadorInvalidoResponde422ComFrase()
    {
        $resposta = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste CPF',
            'ds_login' => 'teste.http.cpfruim',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste CPF Oficial',
            'ds_cpf' => '12345678900',
        ], $this->headers());

        $resposta->assertStatus(422);
        $mensagem = $resposta->json('errors.ds_cpf')[0];

        // Frase exata, não só "não é a chave crua": um assertStringNotContainsString
        // teria passado com ":attribute" sem substituir -- foi exatamente o bug que essa
        // asserção fraca deixou passar antes (ver task-7-report.md, "Desvio 2b").
        $this->assertSame('The ds cpf is not a valid CPF.', $mensagem);
    }

    public function testCnpjComDigitoVerificadorInvalidoResponde422ComFrase()
    {
        $resposta = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste CNPJ',
            'ds_login' => 'teste.http.cnpjruim',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => true,
            // Dígito verificador não fecha (o CNPJ válido usado no resto da suíte termina
            // em ...0191). O CNPJ ruim aqui só existe para provar o after() do validador.
            'ds_cnpj' => '00000000000192',
            'ds_nome_fantasia' => 'Http Teste CNPJ Fantasia',
        ], $this->headers());

        $resposta->assertStatus(422);
        $mensagem = $resposta->json('errors.ds_cnpj')[0];

        $this->assertSame('The ds cnpj is not a valid CNPJ.', $mensagem);
    }

    /**
     * Critical 1 da revisão da Task 7: Hyperf\Validation\Concerns\ValidatesAttributes::
     * validateDigits() faz `(string) $value` ANTES de medir, então um inteiro sem aspas no
     * JSON ("ds_cpf": 12345678900) passa em digits:11 mesmo sem ser string. O trait de DV
     * então guardava com is_string($cpf), que é falso pra um inteiro -- a checagem de
     * dígito verificador era pulada inteira e a pessoa era criada com CPF inválido.
     *
     * Este teste tem de falhar se a guarda em ValidaDocumentosDePessoa voltar a ser
     * is_string() -- ver mutation evidence no relatório da tarefa.
     */
    public function testCpfNumericoNoJsonNaoEscapaDoDigitoVerificador()
    {
        $resposta = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste CPF Numerico',
            'ds_login' => 'teste.http.cpfnumerico',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste CPF Numerico Oficial',
            'ds_cpf' => 12345678900,
        ], $this->headers());

        $resposta->assertStatus(422);
        $this->assertArrayHasKey('ds_cpf', $resposta->json('errors'));
    }

    /**
     * Mesmo bug do teste acima, lado CNPJ.
     */
    public function testCnpjNumericoNoJsonNaoEscapaDoDigitoVerificador()
    {
        $resposta = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste CNPJ Numerico',
            'ds_login' => 'teste.http.cnpjnumerico',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => true,
            'ds_cnpj' => 12345678901234,
            'ds_nome_fantasia' => 'Http Teste CNPJ Numerico Fantasia',
        ], $this->headers());

        $resposta->assertStatus(422);
        $this->assertArrayHasKey('ds_cnpj', $resposta->json('errors'));
    }

    /**
     * Important 3 da revisão: string vazia só vira null nos dez campos novos de física
     * (mais ds_sexo, que já é um deles) -- não nos campos pré-existentes. ds_cnpj não tem
     * `nullable` na regra, então se a normalização convertesse "" para null aqui, o
     * digits:14 passaria a rodar contra null e reprovar uma pessoa física que nunca
     * deveria ter sido obrigada a informar CNPJ.
     */
    public function testCnpjVazioEmPessoaFisicaContinuaAceitoAposNormalizacao()
    {
        $resposta = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Cnpj Vazio',
            'ds_login' => 'teste.http.cnpjvazio',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Cnpj Vazio Oficial',
            'ds_cnpj' => '',
        ], $this->headers());

        $resposta->assertStatus(201);
    }

    /**
     * Important 4 da revisão: os seis testes anteriores só batem em POST. Este cobre PATCH
     * com uma das dez regras novas E o trait de DV, pra não deixar PUT/PATCH sem nenhuma
     * cobertura própria.
     */
    public function testPatchComCpfDigitoVerificadorInvalidoResponde422()
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Patch CPF Ruim',
            'ds_login' => 'teste.http.patchcpfruim',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Patch CPF Ruim Oficial',
        ], $this->headers());

        $cdPessoa = $criar->json('data.cd_pessoa');

        $patch = $this->patch("/pessoas/{$cdPessoa}", [
            'ds_cpf' => '12345678900',
        ], $this->headers());

        $patch->assertStatus(422);
        $this->assertArrayHasKey('ds_cpf', $patch->json('errors'));
    }

    public function testSexoForaDoDominioResponde422()
    {
        $resposta = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Sexo',
            'ds_login' => 'teste.http.sexoruim',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Sexo Oficial',
            'ds_sexo' => 'x',
        ], $this->headers());

        $resposta->assertStatus(422);
        $this->assertArrayHasKey('ds_sexo', $resposta->json('errors'));
    }

    public function testEstadoCivilInexistenteResponde422ENao409()
    {
        $resposta = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Estado Civil',
            'ds_login' => 'teste.http.estadocivil',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Estado Civil Oficial',
            'cd_estado_civil' => 999999,
        ], $this->headers());

        // Sem a regra exists, a FK viraria SQLSTATE 23000 e o DatabaseExceptionHandler
        // devolveria 409 -- o mesmo status de "login ja existe", mandando quem investiga
        // para o lado errado.
        $resposta->assertStatus(422);
        $this->assertArrayHasKey('cd_estado_civil', $resposta->json('errors'));
    }

    public function testExpedicaoAnteriorAoNascimentoResponde422()
    {
        $resposta = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Datas',
            'ds_login' => 'teste.http.datas',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Datas Oficial',
            'dt_nascimento' => '1990-05-12',
            'dt_identidade_expedicao' => '1985-01-01',
        ], $this->headers());

        $resposta->assertStatus(422);
        $this->assertArrayHasKey('dt_identidade_expedicao', $resposta->json('errors'));
    }

    public function testNascimentoNoFuturoResponde422()
    {
        $resposta = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Futuro',
            'ds_login' => 'teste.http.futuro',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Futuro Oficial',
            'dt_nascimento' => '2099-01-01',
        ], $this->headers());

        $resposta->assertStatus(422);
        $this->assertArrayHasKey('dt_nascimento', $resposta->json('errors'));
    }

    /**
     * Critical da revisão da Task 9: `after_or_equal:dt_nascimento` na STRING de regras
     * comparava contra o valor literal "dt_nascimento" quando o campo não vinha no
     * payload -- isso não é uma data, então a comparação reprovava por não conseguir
     * fazer parse, e um PATCH que só manda dt_identidade_expedicao tomava 422 sem
     * nenhuma data estar de fato invertida. A checagem migrou para o after() do
     * validador (ValidaDatasDePessoa) e só compara quando as DUAS datas vêm no mesmo
     * payload.
     */
    public function testPatchComSoDtIdentidadeExpedicaoNaoExigeDtNascimento()
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Patch Data Isolada',
            'ds_login' => 'teste.http.patchdataisolada',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Patch Data Isolada Oficial',
        ], $this->headers());

        $criar->assertStatus(201);
        $cdPessoa = $criar->json('data.cd_pessoa');

        $patch = $this->patch("/pessoas/{$cdPessoa}", [
            'dt_identidade_expedicao' => '2015-03-01',
        ], $this->headers());

        $patch->assertStatus(200);
        $this->assertSame('2015-03-01', $patch->json('data.fisica.dt_identidade_expedicao'));
    }

    /**
     * Mesmo cenário do Critical, mas via POST: alguém com data de expedição de RG
     * conhecida e data de nascimento desconhecida precisa conseguir cadastrar a
     * primeira sem a segunda.
     */
    public function testCriaComSoDtIdentidadeExpedicaoNaoExigeDtNascimento()
    {
        $resposta = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Post Data Isolada',
            'ds_login' => 'teste.http.postdataisolada',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Post Data Isolada Oficial',
            'dt_identidade_expedicao' => '2015-03-01',
        ], $this->headers());

        $resposta->assertStatus(201);
        $this->assertSame('2015-03-01', $resposta->json('data.fisica.dt_identidade_expedicao'));
    }

    public function testCriaPessoaFisicaComOsDezCamposNovos()
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Completa',
            'ds_login' => 'teste.http.completa',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Completa Oficial',
            'ds_nome_social' => 'Completa',
            'ds_nome_mae' => 'Mae Completa',
            'ds_nome_pai' => 'Pai Completa',
            'ds_cpf' => '52998224725',
            'ds_identidade' => '123456789',
            'ds_orgao_estado' => 'SP',
            'ds_identidade_orgao_exp' => 'SSP',
            'dt_identidade_expedicao' => '2015-03-01',
            'dt_nascimento' => '1990-05-12',
            'ds_sexo' => 'f',
            'cd_estado_civil' => $this->cdEstadoCivil(),
        ], $this->headers());

        $criar->assertStatus(201);
        $cdPessoa = $criar->json('data.cd_pessoa');

        $detalhe = $this->get("/pessoas/{$cdPessoa}", ['fields' => 'fisica.*'], $this->headers());
        $detalhe->assertStatus(200);
        $fisica = $detalhe->json('data.fisica');

        $this->assertSame('Completa', $fisica['ds_nome_social']);
        $this->assertSame('Mae Completa', $fisica['ds_nome_mae']);
        $this->assertSame('Pai Completa', $fisica['ds_nome_pai']);
        $this->assertSame('52998224725', $fisica['ds_cpf']);
        $this->assertSame('123456789', $fisica['ds_identidade']);
        $this->assertSame('SP', $fisica['ds_orgao_estado']);
        $this->assertSame('SSP', $fisica['ds_identidade_orgao_exp']);
        $this->assertSame('2015-03-01', $fisica['dt_identidade_expedicao']);
        $this->assertSame('1990-05-12', $fisica['dt_nascimento']);
        $this->assertSame('f', $fisica['ds_sexo']);
        $this->assertSame($this->cdEstadoCivil(), $fisica['cd_estado_civil']);
    }

    public function testNormalizaSexoEStringVaziaAoGravar()
    {
        // Fecha o par que a Task 7 deixou pela metade: a normalização roda em
        // validationData(), mas só aqui os campos chegam ao banco e podem ser observados.
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Normaliza',
            'ds_login' => 'teste.http.normaliza',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Normaliza Oficial',
            'ds_sexo' => 'F',
            'ds_nome_social' => '',
        ], $this->headers());

        $criar->assertStatus(201);
        $this->assertSame('f', $criar->json('data.fisica.ds_sexo'));
        $this->assertNull($criar->json('data.fisica.ds_nome_social'));
    }

    public function testPatchAtualizaCampoNovoDeFisica()
    {
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Patch Fisica',
            'ds_login' => 'teste.http.patchfisica',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Http Teste Patch Fisica Oficial',
        ], $this->headers());

        $criar->assertStatus(201);
        $cdPessoa = $criar->json('data.cd_pessoa');

        $patch = $this->patch("/pessoas/{$cdPessoa}", ['ds_nome_social' => 'Patchado'], $this->headers());
        $patch->assertStatus(200);
        $this->assertSame('Patchado', $patch->json('data.fisica.ds_nome_social'));
    }

    public function testPatchComCampoDeFisicaEmPessoaJuridicaNaoCriaLinhaFisica()
    {
        // Finding 14: PATCH nunca troca o tipo pessoa, e campo do tipo que a pessoa NÃO é
        // tem de ser ignorado em silêncio. Com dez campos a mais, dez portas a mais.
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'Http Teste Juridica Patch',
            'ds_login' => 'teste.http.juridicapatch',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => true,
            'ds_cnpj' => '00000000000191',
            'ds_nome_fantasia' => 'Http Teste Fantasia',
        ], $this->headers());

        $criar->assertStatus(201);
        $cdPessoa = $criar->json('data.cd_pessoa');

        $patch = $this->patch("/pessoas/{$cdPessoa}", [
            'ds_nome_mae' => 'Nao Deve Gravar',
            'ds_sexo' => 'f',
        ], $this->headers());

        $patch->assertStatus(200);
        $this->assertSame(
            0,
            Db::table('unim_pessoa_fisica')->where('cd_pessoa', $cdPessoa)->count()
        );
    }

    private function cdEstadoCivil(): int
    {
        return (int) Db::table('saas_estado_civil')->min('cd_estado_civil');
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }
}
