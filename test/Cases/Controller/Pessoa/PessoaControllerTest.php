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

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }
}
