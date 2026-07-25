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

    public function testSemTokenRetorna401()
    {
        $this->get('/pessoas')->assertStatus(401);
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
