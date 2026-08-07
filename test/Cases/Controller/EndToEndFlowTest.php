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

namespace HyperfTest\Cases\Controller;

use App\Enum\Privilegio;
use App\Enum\Recurso;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Hyperf\Testing\TestCase;
use HyperfTest\Support\TenantDeTeste;

/**
 * Finding 6 (whole-branch review): faltava um teste de fluxo ponta-a-ponta real. Até aqui
 * todo teste HTTP fabricava a sessão direto no Redis (`session:{token}` com
 * `bin2hex(random_bytes(32))`), nunca exercitando AuthService::autenticar()/AuthMiddleware
 * de verdade nem confirmando isolamento entre clientes.
 *
 * @internal
 * @coversNothing
 */
class EndToEndFlowTest extends TestCase
{
    /**
     * Perfil fabricado só no Redis para o teste de isolamento entre tenants — nunca é
     * gravado em lgin_perfil, então não precisa existir.
     */
    private const CD_PERFIL_OUTRO_TENANT = 900002;

    protected function tearDown(): void
    {
        $cdPessoas = Db::table('unim_pessoa')->where('ds_login', 'like', 'teste.e2e.%')->pluck('cd_pessoa');

        Db::table('lgin_pessoa_perfil')->whereIn('cd_pessoa', $cdPessoas)->delete();
        Db::table('unim_coligada')->whereIn('cd_pessoa', $cdPessoas)->delete();
        Db::table('unim_pessoa_fisica')->whereIn('cd_pessoa', $cdPessoas)->delete();
        Db::table('unim_pessoa_juridica')->whereIn('cd_pessoa', $cdPessoas)->delete();
        Db::table('unim_pessoa')->where('ds_login', 'like', 'teste.e2e.%')->delete();

        $redis = $this->getContainer()->get(Redis::class);
        $redis->del('acl:perfil:' . TenantDeTeste::cdPerfil());
        $redis->del('acl:perfil:' . self::CD_PERFIL_OUTRO_TENANT);

        parent::tearDown();
    }

    /**
     * (a) POST /auth/login de verdade (sem fabricar sessão no Redis) -> usa o token real
     * devolvido pra fazer um CRUD completo de pessoa terminando em soft-delete e 404.
     */
    public function testFluxoPontaAPontaLoginRealCrudESoftDelete()
    {
        $cdPessoaLogin = Db::table('unim_pessoa')->insertGetId([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'E2E Fluxo Completo',
            'ds_login' => 'teste.e2e.fluxo',
            'ds_senha' => password_hash('123456', PASSWORD_BCRYPT),
            'sn_pessoa_juridica' => 0,
        ]);

        // O vínculo pessoa -> perfil exige coligada (FK NOT NULL) e um lgin_perfil que
        // exista de verdade. cd_perfil=1 não existe: os perfis reais começam em 79.
        TenantDeTeste::vincularPerfil($cdPessoaLogin);

        $redis = $this->getContainer()->get(Redis::class);
        $redis->setex('acl:perfil:' . TenantDeTeste::cdPerfil(), 3600, json_encode(self::permissoesPessoa()));

        // --- login de verdade ---
        $login = $this->post('/auth/login', [
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_login' => 'teste.e2e.fluxo',
            'ds_senha' => '123456',
        ]);

        $login->assertStatus(200);
        $token = $login->json('data.token');
        $this->assertNotEmpty($token);

        $headers = ['Authorization' => "Bearer {$token}"];

        // --- CRUD completo com o token real ---
        $criar = $this->json('/pessoas', [
            'ds_nome' => 'E2E Pessoa Criada',
            'ds_login' => 'teste.e2e.criada',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ], $headers);

        $criar->assertStatus(201);
        $cdPessoaCriada = $criar->json('data.cd_pessoa');

        $buscar = $this->get("/pessoas/{$cdPessoaCriada}", [], $headers);
        $buscar->assertStatus(200);
        $this->assertSame('E2E Pessoa Criada', $buscar->json('data.ds_nome'));

        $atualizar = $this->patch("/pessoas/{$cdPessoaCriada}", ['ds_nome' => 'E2E Pessoa Renomeada'], $headers);
        $atualizar->assertStatus(200);
        $this->assertSame('E2E Pessoa Renomeada', $atualizar->json('data.ds_nome'));

        $excluir = $this->delete("/pessoas/{$cdPessoaCriada}", [], $headers);
        $excluir->assertStatus(200);

        $buscarDepoisDeExcluir = $this->get("/pessoas/{$cdPessoaCriada}", [], $headers);
        $buscarDepoisDeExcluir->assertStatus(404);
    }

    /**
     * (b) Isolamento cross-tenant: pessoa criada sob cd_cliente=1 não pode ser vista por
     * quem está autenticado como outro cliente. A sessão do "outro cliente" é simulada
     * direto no Redis (aceitável pra este caso específico, conforme o brief da revisão) —
     * o que importa aqui é confirmar o filtro por cd_cliente no Repository/Service, não
     * o fluxo de login em si (já coberto no teste acima).
     */
    public function testIsolamentoCrossTenantPessoaDeUmClienteNaoApareceParaOutro()
    {
        $redis = $this->getContainer()->get(Redis::class);

        $tokenClienteUm = bin2hex(random_bytes(32));
        $redis->setex("session:{$tokenClienteUm}", 3600, json_encode([
            'cd_pessoa' => 1,
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'cd_perfis' => [TenantDeTeste::cdPerfil()],
        ]));
        $redis->setex('acl:perfil:' . TenantDeTeste::cdPerfil(), 3600, json_encode(self::permissoesPessoa()));

        // O "outro cliente" não precisa existir no banco: nada é gravado sob ele, só se
        // verifica que uma busca filtrada por cd_cliente não acha a pessoa do primeiro. A
        // sessão e o cache ACL são fabricados no Redis, sem FK envolvida.
        $tokenOutroCliente = bin2hex(random_bytes(32));
        $redis->setex("session:{$tokenOutroCliente}", 3600, json_encode([
            'cd_pessoa' => 1,
            'cd_cliente' => TenantDeTeste::cdClienteInexistente(),
            'cd_perfis' => [self::CD_PERFIL_OUTRO_TENANT],
        ]));
        $redis->setex('acl:perfil:' . self::CD_PERFIL_OUTRO_TENANT, 3600, json_encode(self::permissoesPessoa()));

        $criar = $this->json('/pessoas', [
            'ds_nome' => 'E2E Isolamento Cliente Um',
            'ds_login' => 'teste.e2e.isolamento',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ], ['Authorization' => "Bearer {$tokenClienteUm}"]);

        $criar->assertStatus(201);
        $cdPessoa = $criar->json('data.cd_pessoa');

        // o próprio cliente 1 enxerga
        $this->get("/pessoas/{$cdPessoa}", [], ['Authorization' => "Bearer {$tokenClienteUm}"])
            ->assertStatus(200);

        // cliente 2 não enxerga a pessoa do cliente 1
        $this->get("/pessoas/{$cdPessoa}", [], ['Authorization' => "Bearer {$tokenOutroCliente}"])
            ->assertStatus(404);

        $redis->del("session:{$tokenClienteUm}");
        $redis->del("session:{$tokenOutroCliente}");
    }

    /**
     * (c) POST /auth/logout com token real, confirmando que o token para de funcionar
     * logo depois.
     */
    public function testLogoutRealInvalidaOTokenImediatamente()
    {
        Db::table('unim_pessoa')->insert([
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_nome' => 'E2E Logout Teste',
            'ds_login' => 'teste.e2e.logout',
            'ds_senha' => password_hash('123456', PASSWORD_BCRYPT),
            'sn_pessoa_juridica' => 0,
        ]);

        $login = $this->post('/auth/login', [
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'ds_login' => 'teste.e2e.logout',
            'ds_senha' => '123456',
        ]);

        $login->assertStatus(200);
        $token = $login->json('data.token');
        $headers = ['Authorization' => "Bearer {$token}"];

        // token válido funciona antes do logout
        $this->get('/pessoas', [], $headers)->assertStatus(403); // sem ACL configurada pra este perfil -- o que importa é NÃO ser 401

        $logout = $this->post('/auth/logout', [], $headers);
        $logout->assertStatus(200);

        // depois do logout, o mesmo token não autentica mais
        $this->get('/pessoas', [], $headers)->assertStatus(401);
    }

    /**
     * Massa de cache ACL usando as ds_chave reais do LMS (ulms_recurso / ulms_privilegio).
     * DELETAR depende da migration que liga o privilégio a GERENCIAR_PESSOA.
     *
     * @return array<string, string[]>
     */
    private static function permissoesPessoa(): array
    {
        return [
            Recurso::GERENCIAR_PESSOA->value => [
                Privilegio::ACESSAR->value,
                Privilegio::INSERIR->value,
                Privilegio::ATUALIZAR->value,
                Privilegio::DELETAR->value,
            ],
        ];
    }
}
