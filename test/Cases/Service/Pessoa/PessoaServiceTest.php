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
use App\Service\Pessoa\PessoaService;
use Hyperf\DbConnection\Db;
use Hyperf\Testing\TestCase;

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

        Db::table('unim_pessoa_fisica')->whereIn('cd_pessoa', $cdPessoas)->delete();
        Db::table('unim_pessoa_juridica')->whereIn('cd_pessoa', $cdPessoas)->delete();
        Db::table('unim_pessoa')->where('ds_login', 'like', 'teste.service.%')->delete();

        parent::tearDown();
    }

    public function testCriarPessoaFisicaComSucesso()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $pessoa = $service->criar(1, [
            'ds_nome' => 'Service Teste',
            'ds_login' => 'teste.service.criar',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Service Teste Oficial',
        ]);

        $this->assertNotNull($pessoa->cd_pessoa);
        $this->assertTrue(password_verify('123456', $pessoa->ds_senha));
    }

    public function testCriarComLoginDuplicadoLancaExcecao()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $service->criar(1, [
            'ds_nome' => 'Duplicado 1',
            'ds_login' => 'teste.service.duplicado',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Duplicado 1',
        ]);

        $this->expectException(LoginJaExisteException::class);

        $service->criar(1, [
            'ds_nome' => 'Duplicado 2',
            'ds_login' => 'teste.service.duplicado',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Duplicado 2',
        ]);
    }

    /**
     * A isenção de fisica/juridica (spec: docs/superpowers/specs/2026-07-25-migracao-
     * cadastro-pessoa-design.md:104) exige o login EXATO 'admin' ou 'administrador'
     * (fiel ao legado — comparacao por igualdade, nao prefixo). Por isso este teste
     * nao pode usar o prefixo 'teste.service.%' dos demais (nao seria mais "admin"
     * apos o prefixo). Como a base usada aqui e' real (mysql_84/lms2), o teste
     * confere antes que nao existe conta 'administrador' de verdade para cd_cliente=1
     * e remove explicitamente o registro criado ao final, em vez de depender do
     * tearDown() generico.
     */
    public function testCriarComLoginAdminNaoExigeFisicaOuJuridica()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $existeContaReal = Db::table('unim_pessoa')
            ->where('cd_cliente', 1)
            ->where('ds_login', 'administrador')
            ->exists();

        if ($existeContaReal) {
            $this->markTestSkipped('Ja existe uma conta "administrador" real para cd_cliente=1 nesta base; teste evitado para nao conflitar com dado real.');
        }

        $cdPessoa = null;

        try {
            $pessoa = $service->criar(1, [
                'ds_nome' => 'Administrador Teste',
                'ds_login' => 'administrador',
                'ds_senha' => '123456',
                'sn_pessoa_juridica' => false,
            ]);
            $cdPessoa = $pessoa->cd_pessoa;

            $this->assertNotNull($pessoa->cd_pessoa);
            $this->assertNull($pessoa->fisica);
        } finally {
            if ($cdPessoa !== null) {
                Db::table('unim_pessoa')->where('cd_pessoa', $cdPessoa)->delete();
            }
        }
    }

    public function testAtualizarSemSenhaMantemSenhaAtual()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $pessoa = $service->criar(1, [
            'ds_nome' => 'Mantem Senha',
            'ds_login' => 'teste.service.mantemsenha',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Mantem Senha',
        ]);
        $hashOriginal = $pessoa->ds_senha;

        $atualizada = $service->atualizar($pessoa->cd_pessoa, 1, [
            'ds_nome' => 'Mantem Senha Renomeado',
            'ds_login' => 'teste.service.mantemsenha',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Mantem Senha',
        ]);

        $this->assertSame($hashOriginal, $atualizada->ds_senha);
    }

    public function testAtualizarParcialIgnoraCampoDoTipoQuePessoaNaoE()
    {
        // Finding 14 (whole-branch review): atualizarParcial() montava dadosJuridica sem
        // checar o sn_pessoa_juridica REAL da pessoa -- um PATCH com ds_cnpj numa pessoa
        // física criava uma linha jurídica pra ela (mesmo bug de integridade do Critical
        // 1, por outra porta, só que via PATCH em vez de PUT).
        $service = $this->getContainer()->get(PessoaService::class);

        $pessoa = $service->criar(1, [
            'ds_nome' => 'Patch Tipo Errado',
            'ds_login' => 'teste.service.patchtipoerrado',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Patch Tipo Errado',
        ]);

        $atualizada = $service->atualizarParcial($pessoa->cd_pessoa, 1, [
            'ds_cnpj' => '00000000000191',
            'ds_nome_fantasia' => 'Nao Deveria Existir',
        ]);

        $this->assertNull($atualizada->juridica);

        $linhaJuridica = Db::table('unim_pessoa_juridica')->where('cd_pessoa', $pessoa->cd_pessoa)->first();
        $this->assertNull($linhaJuridica);
    }

    public function testAtualizarParcialPessoaInexistenteLancaExcecao()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $this->expectException(PessoaNaoEncontradaException::class);
        $service->atualizarParcial(999999, 1, ['ds_nome' => 'Nao Existe']);
    }

    public function testBuscarPessoaInexistenteLancaExcecao()
    {
        $service = $this->getContainer()->get(PessoaService::class);

        $this->expectException(PessoaNaoEncontradaException::class);
        $service->buscar(999999, 1);
    }
}
