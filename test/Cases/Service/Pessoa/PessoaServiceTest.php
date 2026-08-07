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

namespace HyperfTest\Cases\Service\Pessoa;

use App\Exception\Pessoa\LoginJaExisteException;
use App\Exception\Pessoa\PessoaNaoEncontradaException;
use App\Service\Pessoa\CachePessoa;
use App\Service\Pessoa\PessoaService;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Hyperf\Testing\TestCase;
use HyperfTest\Support\TenantDeTeste;

/**
 * @internal
 * @coversNothing
 */
class PessoaServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        $cdPessoas = Db::table('unim_pessoa')
            ->where('ds_login', 'like', 'teste.service.%')
            ->pluck('cd_pessoa');

        $redis = $this->getContainer()->get(Redis::class);
        $cache = $this->getContainer()->get(CachePessoa::class);

        foreach ($cdPessoas as $cdPessoa) {
            $redis->del($cache->chave(TenantDeTeste::cdCliente(), (int) $cdPessoa));
        }

        Db::table('unim_pessoa')->where('ds_login', 'like', 'teste.service.%')->delete();

        parent::tearDown();
    }

    public function testCriarPessoaComSucesso()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $pessoa = $service->criar(TenantDeTeste::cdCliente(), [
            'ds_nome' => 'Service Teste',
            'ds_login' => 'teste.service.criar',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ]);

        $this->assertNotNull($pessoa->cd_pessoa);
        $this->assertTrue(password_verify('123456', $pessoa->ds_senha));
    }

    /**
     * A escrita de pessoa não toca as tabelas dos outros recursos. Antes, criar uma pessoa
     * física inseria a linha filha junto — e trocar o tipo apagava a antiga.
     */
    public function testCriarNaoEscreveNasTabelasDeOutroRecurso()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $pessoa = $service->criar(TenantDeTeste::cdCliente(), [
            'ds_nome' => 'Service Sem Filho',
            'ds_login' => 'teste.service.semfilho',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ]);

        $this->assertSame(0, Db::table('unim_pessoa_fisica')->where('cd_pessoa', $pessoa->cd_pessoa)->count());
        $this->assertSame(0, Db::table('unim_pessoa_juridica')->where('cd_pessoa', $pessoa->cd_pessoa)->count());
    }

    /**
     * Regressão que a versão anterior desta API tinha de tratar com um sinal especial
     * (ehIsentoDeFisicaJuridica, para os logins admin/administrador): um PUT apagava linha
     * de unim_pessoa_fisica que existisse por dado legado. Agora nenhuma escrita de pessoa
     * apaga linha de outro recurso, para login nenhum — a regra especial deixou de existir e
     * este teste guarda o comportamento por qualquer login.
     */
    public function testAtualizarNaoApagaLinhaDeOutroRecurso()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $pessoa = $service->criar(TenantDeTeste::cdCliente(), [
            'ds_nome' => 'Service Com Fisica Legada',
            'ds_login' => 'teste.service.fisicalegada',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ]);

        // Simula dado de outro recurso (ou do LMS legado) já gravado para esta pessoa.
        Db::table('unim_pessoa_fisica')->insert([
            'cd_pessoa' => $pessoa->cd_pessoa,
            'ds_nome_oficial' => 'Fisica De Outro Recurso',
        ]);

        try {
            // PUT invertendo o tipo: era exatamente o caso que destruía a linha antiga.
            $atualizada = $service->atualizar($pessoa->cd_pessoa, TenantDeTeste::cdCliente(), [
                'ds_nome' => 'Service Com Fisica Legada Renomeado',
                'ds_login' => 'teste.service.fisicalegada',
                'sn_pessoa_juridica' => true,
            ]);

            $this->assertSame('Service Com Fisica Legada Renomeado', $atualizada->ds_nome);
            $this->assertNotNull(
                Db::table('unim_pessoa_fisica')->where('cd_pessoa', $pessoa->cd_pessoa)->first(),
                'Escrita de pessoa NAO pode apagar linha de unim_pessoa_fisica: e dado de outro recurso.'
            );
        } finally {
            Db::table('unim_pessoa_fisica')->where('cd_pessoa', $pessoa->cd_pessoa)->delete();
        }
    }

    public function testCriarComLoginDuplicadoLancaExcecao()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $service->criar(TenantDeTeste::cdCliente(), [
            'ds_nome' => 'Duplicado 1',
            'ds_login' => 'teste.service.duplicado',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ]);

        $this->expectException(LoginJaExisteException::class);

        $service->criar(TenantDeTeste::cdCliente(), [
            'ds_nome' => 'Duplicado 2',
            'ds_login' => 'teste.service.duplicado',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ]);
    }

    public function testAtualizarSemSenhaMantemSenhaAtual()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $pessoa = $service->criar(TenantDeTeste::cdCliente(), [
            'ds_nome' => 'Mantem Senha',
            'ds_login' => 'teste.service.mantemsenha',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ]);
        $hashOriginal = $pessoa->ds_senha;

        $atualizada = $service->atualizar($pessoa->cd_pessoa, TenantDeTeste::cdCliente(), [
            'ds_nome' => 'Mantem Senha Renomeado',
            'ds_login' => 'teste.service.mantemsenha',
            'sn_pessoa_juridica' => false,
        ]);

        $this->assertSame($hashOriginal, $atualizada->ds_senha);
    }

    /**
     * O 404 do PATCH vinha de uma leitura extra feita antes de gravar (para descobrir o tipo
     * da pessoa). Essa leitura saiu junto com a escrita das tabelas filhas; quem garante o
     * 404 agora é o WHERE com cd_cliente do próprio UPDATE.
     */
    public function testAtualizarParcialPessoaInexistenteLancaExcecao()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $this->expectException(PessoaNaoEncontradaException::class);
        $service->atualizarParcial(999999, TenantDeTeste::cdCliente(), ['ds_nome' => 'Nao Existe']);
    }

    public function testBuscarPessoaInexistenteLancaExcecao()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $this->expectException(PessoaNaoEncontradaException::class);
        $service->buscar(999999, TenantDeTeste::cdCliente());
    }

    /**
     * O ganho do cache é não repetir a consulta. A prova é alterar a linha direto no banco
     * (o Service não vê isso passar) e continuar recebendo o valor antigo.
     */
    public function testBuscarUsaCacheNaSegundaChamada()
    {
        $service = $this->getContainer()->get(PessoaService::class);
        $cache = $this->getContainer()->get(CachePessoa::class);
        $redis = $this->getContainer()->get(Redis::class);

        $pessoa = $service->criar(TenantDeTeste::cdCliente(), [
            'ds_nome' => 'Service Cache',
            'ds_login' => 'teste.service.cache',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ]);

        $chave = $cache->chave(TenantDeTeste::cdCliente(), (int) $pessoa->cd_pessoa);
        $redis->del($chave);

        $service->buscar((int) $pessoa->cd_pessoa, TenantDeTeste::cdCliente());
        $this->assertNotEmpty($redis->get($chave), 'A primeira leitura precisa gravar o cache.');
        $this->assertSame(CachePessoa::TTL_SEGUNDOS, $redis->ttl($chave));

        Db::table('unim_pessoa')->where('cd_pessoa', $pessoa->cd_pessoa)->update(['ds_nome' => 'Mudado Direto No Banco']);

        $doCache = $service->buscar((int) $pessoa->cd_pessoa, TenantDeTeste::cdCliente());
        $this->assertSame('Service Cache', $doCache->ds_nome);

        // E a invalidação: depois de uma escrita pela API, a leitura volta ao banco.
        $service->atualizarParcial((int) $pessoa->cd_pessoa, TenantDeTeste::cdCliente(), ['ds_nome' => 'Service Cache Novo']);

        $this->assertSame('Service Cache Novo', $service->buscar((int) $pessoa->cd_pessoa, TenantDeTeste::cdCliente())->ds_nome);
    }

    /**
     * ds_senha não está no mapa de campos, então não pode chegar ao Redis — o hash bcrypt
     * ficaria legível para quem tem acesso ao cache.
     */
    public function testCacheNaoGuardaSenha()
    {
        $service = $this->getContainer()->get(PessoaService::class);
        $cache = $this->getContainer()->get(CachePessoa::class);
        $redis = $this->getContainer()->get(Redis::class);

        $pessoa = $service->criar(TenantDeTeste::cdCliente(), [
            'ds_nome' => 'Service Cache Senha',
            'ds_login' => 'teste.service.cachesenha',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ]);

        $service->buscar((int) $pessoa->cd_pessoa, TenantDeTeste::cdCliente());

        $cacheado = $redis->get($cache->chave(TenantDeTeste::cdCliente(), (int) $pessoa->cd_pessoa));

        $this->assertIsString($cacheado);
        $this->assertStringNotContainsString('ds_senha', $cacheado);
        $this->assertStringNotContainsString('$2y$', $cacheado);
    }
}
