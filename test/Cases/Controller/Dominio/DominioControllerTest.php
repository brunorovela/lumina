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
use Hyperf\DbConnection\Db;
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
        // cd_pais vem de /estados (não de /paises): saas_pais tem um país (Angola nesta
        // base) sem nenhuma linha em saas_estado, e /paises ordena por ds_pais — "Angola"
        // vem antes de "Brasil". Partir de /paises pegaria um país sem estados e o
        // assertNotEmpty abaixo falharia por dado do catálogo, não por defeito da rota.
        // Este é o caso positivo (o país tem estados e todos vêm filtrados); o caso que
        // realmente prova que o WHERE roda — filtrar por um país SEM estado nenhum — está
        // em testEstadosFiltradosPorPaisSemEstadoDevolveVazio() logo abaixo.
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

    /**
     * Prova que o filtro cd_pais é de fato aplicado no WHERE, não apenas compatível com o
     * dado que já existe.
     *
     * testEstadosFiltradosPorPais() sozinho não distingue "o filtro roda" de "o filtro foi
     * ignorado": ele sempre pega o país que /estados devolve primeiro sem filtro, e como
     * 100% das linhas de saas_estado pertencem a um único país nesta base, filtrar por ele
     * devolve o mesmo conjunto com ou sem o WHERE — a asserção passaria mesmo se
     * DominioRepository::estados() descartasse cd_pais silenciosamente.
     *
     * A lacuna do catálogo é a alavanca: um país que existe em saas_pais mas não tem
     * nenhuma linha em saas_estado só devolve vazio se o WHERE realmente rodou. Se o filtro
     * fosse um no-op, viriam as 27 linhas do outro país.
     */
    public function testEstadosFiltradosPorPaisSemEstadoDevolveVazio()
    {
        $cdPaisSemEstado = Db::table('saas_pais')
            ->whereNotIn('cd_pais', Db::table('saas_estado')->select('cd_pais'))
            ->value('cd_pais');

        if ($cdPaisSemEstado === null) {
            $this->markTestSkipped(
                'Todo país em saas_pais tem ao menos um estado em saas_estado nesta base. '
                . 'Sem um país "vazio" não há como provar que o filtro cd_pais é aplicado '
                . 'de verdade (em vez de ignorado) — ver PHPDoc do teste.'
            );
        }

        $resposta = $this->get('/estados', ['cd_pais' => $cdPaisSemEstado], $this->headers());

        $resposta->assertStatus(200);
        $this->assertSame([], $resposta->json('data'));
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
