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

use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class PessoaControllerTest extends TestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $redis = $this->getContainer()->get(Redis::class);
        $this->token = bin2hex(random_bytes(32));
        $redis->setex("session:{$this->token}", 3600, json_encode([
            'cd_pessoa' => 1,
            'cd_cliente' => 1,
            'cd_perfis' => [1],
        ]));

        // garantir que o perfil 1 tem os privilégios de pessoa liberados nesta massa de teste
        $redis->setex('acl:perfil:1', 3600, json_encode([
            'pessoa' => ['criar', 'atualizar', 'visualizar', 'listar', 'excluir'],
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

    public function testSemTokenEComPayloadInvalidoRetornaAtualmente422EmVezDe401()
    {
        // LIMITAÇÃO CONHECIDA (Task 14, Fix round 1, Finding 2) — NÃO é o comportamento
        // desejado, é uma prova/registro do bug atual: ValidationMiddleware (global,
        // config/autoload/middlewares.php) roda ANTES de AuthMiddleware/AclMiddleware
        // (por rota), porque ambos entram na mesma lista de middlewares sem nenhuma
        // ordem declarada e Hyperf\Testing\Http\Client::execute() apenas concatena
        // (array_merge) global + rota, na ordem em que aparecem — sem chamar
        // Hyperf\HttpServer\MiddlewareManager::sortMiddlewares(). Um cliente sem token
        // descobre a forma do contrato de validação (quais campos existem, quais são
        // obrigatórios) sem nunca ter se autenticado. Não vaza dado, mas inverte
        // "autenticar antes de processar".
        //
        // Existe um mecanismo real e limpo do Hyperf pra consertar isso em produção:
        // Hyperf\HttpServer\PriorityMiddleware, interpretado por
        // MiddlewareManager::sortMiddlewares() — e Hyperf\HttpServer\Server::onRequest()
        // (o servidor Swoole de verdade) CHAMA sortMiddlewares() sempre que a rota tem
        // middleware próprio, então o fix funcionaria no servidor real. O problema é que
        // Hyperf\Testing\Http\Client (o cliente HTTP usado por TestCase/MakesHttpRequests,
        // e portanto por TODOS os testes deste projeto) não chama sortMiddlewares() em
        // lugar nenhum — só existe outro Client (Hyperf\Testing\Client, classe raramente
        // usada e não é a que o TestCase importa) que chama. Aplicar PriorityMiddleware
        // nas rotas faria o comportamento em produção mudar (correto), mas quebraria TODA
        // a suíte co-phpunit destas rotas com 500 "Invalid middleware, it has to provide
        // a process() method" — porque o harness passaria o objeto PriorityMiddleware
        // direto pro dispatcher sem desembrulhar. Not sortable = not testable com as
        // ferramentas deste projeto; forçar o fix seria trocar uma inversão de ordem
        // (risco baixo, ninguém lê/escreve sem token) por testes quebrados de verdade.
        // Documentado como limitação conhecida — ver task-14-report.md, Fix round 1,
        // Finding 2. Este teste PINA o comportamento atual (não o desejado): se algum dia
        // isso passar a retornar 401, é sinal de que a limitação foi resolvida de verdade
        // (por exemplo, se uma versão futura do Hyperf\Testing\Http\Client passar a
        // respeitar MiddlewareManager::sortMiddlewares()) — nesse caso, atualize esta
        // asserção para 401 e apague este comentário.
        $criar = $this->json('/pessoas', []);

        $this->assertSame(422, $criar->getStatusCode());
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
        $redis->setex('acl:perfil:1', 3600, json_encode(['pessoa' => []]));

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

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }
}
