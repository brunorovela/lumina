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

namespace HyperfTest\Cases\Controller\Dominio;

use App\Enum\Privilegio;
use App\Enum\Recurso;
use Hyperf\Redis\Redis;
use Hyperf\Testing\TestCase;
use HyperfTest\Support\TenantDeTeste;

/**
 * @internal
 * @coversNothing
 */
class DominioControllerTest extends TestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $cdPerfil = TenantDeTeste::cdPerfil();

        $redis = $this->getContainer()->get(Redis::class);
        $this->token = bin2hex(random_bytes(32));
        $redis->setex("session:{$this->token}", 3600, json_encode([
            'cd_pessoa' => 1,
            'cd_cliente' => TenantDeTeste::cdCliente(),
            'cd_perfis' => [$cdPerfil],
        ]));

        $redis->setex("acl:perfil:{$cdPerfil}", 3600, json_encode([
            Recurso::GERENCIAR_PESSOA->value => [Privilegio::ACESSAR->value],
        ]));
    }

    public function testPaisesRespondeEnvelopeSemMeta()
    {
        $resposta = $this->get('/paises', [], $this->headers());

        $resposta->assertStatus(200);
        $corpo = $resposta->json();

        $this->assertTrue($corpo['success']);
        $this->assertIsArray($corpo['data']);
        // Lista de domínio não pagina, então não tem meta. Se aparecer, alguém copiou o
        // envelope de /pessoas sem pensar.
        $this->assertArrayNotHasKey('meta', $corpo);
        $this->assertSame(
            ['cd_pais', 'ds_pais', 'ds_nacionalidade'],
            array_keys($corpo['data'][0])
        );
    }

    public function testEstadosCivisNaoVazamColunaDeControle()
    {
        $resposta = $this->get('/estados-civis', [], $this->headers());

        $resposta->assertStatus(200);
        $this->assertSame(
            ['cd_estado_civil', 'ds_estado_civil'],
            array_keys($resposta->json('data')[0])
        );
    }

    public function testContatoTiposTrazemAsChavesDoLms()
    {
        $resposta = $this->get('/contato-tipos', [], $this->headers());

        $resposta->assertStatus(200);
        $chaves = array_column($resposta->json('data'), 'ds_chave');

        $this->assertContains('EMAIL', $chaves);
        $this->assertContains('TELEFONE-CELULAR', $chaves);
    }

    public function testSemTokenResponde401()
    {
        $this->get('/paises')->assertStatus(401);
    }

    public function testEstadosFiltradosPorPais()
    {
        // cd_pais vem de /estados (não de /paises): saas_pais tem Angola (cd_pais=2) sem
        // nenhuma linha em saas_estado, e /paises ordena por ds_pais — "Angola" vem antes de
        // "Brasil". Partir de /paises pegaria um país sem estados e o assertNotEmpty abaixo
        // falharia por dado do catálogo, não por defeito da rota.
        $cdPais = $this->get('/estados', [], $this->headers())->json('data')[0]['cd_pais'];

        $resposta = $this->get('/estados', ['cd_pais' => $cdPais], $this->headers());

        $resposta->assertStatus(200);
        $dados = $resposta->json('data');

        $this->assertNotEmpty($dados);
        $this->assertSame(['cd_estado', 'cd_pais', 'ds_estado', 'ds_uf'], array_keys($dados[0]));

        foreach ($dados as $estado) {
            $this->assertSame($cdPais, $estado['cd_pais']);
        }
    }

    public function testCidadesSemEstadoResponde422()
    {
        $resposta = $this->get('/cidades', [], $this->headers());

        // Sem cd_estado a consulta varreria 4928 linhas. A rota recusa em vez de despejar.
        $resposta->assertStatus(422);
        $this->assertFalse($resposta->json('success'));
        $this->assertArrayHasKey('cd_estado', $resposta->json('errors'));
    }

    public function testCidadesDeUmEstadoNaoVazamOutroEstado()
    {
        $cdEstado = $this->get('/estados', [], $this->headers())->json('data')[0]['cd_estado'];

        $resposta = $this->get('/cidades', ['cd_estado' => $cdEstado], $this->headers());

        $resposta->assertStatus(200);
        $dados = $resposta->json('data');

        $this->assertNotEmpty($dados);
        $this->assertSame(['cd_cidade', 'cd_estado', 'ds_cidade'], array_keys($dados[0]));

        foreach ($dados as $cidade) {
            $this->assertSame($cdEstado, $cidade['cd_estado']);
        }
    }

    public function testCidadesFiltradasPorTermo()
    {
        $cdEstado = $this->get('/estados', [], $this->headers())->json('data')[0]['cd_estado'];
        $primeira = $this->get('/cidades', ['cd_estado' => $cdEstado], $this->headers())->json('data')[0];

        $termo = mb_substr((string) $primeira['ds_cidade'], 0, 3);

        $resposta = $this->get('/cidades', ['cd_estado' => $cdEstado, 'q' => $termo], $this->headers());

        $resposta->assertStatus(200);
        $dados = $resposta->json('data');

        $this->assertNotEmpty($dados);

        foreach ($dados as $cidade) {
            $this->assertStringContainsStringIgnoringCase($termo, (string) $cidade['ds_cidade']);
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }
}
