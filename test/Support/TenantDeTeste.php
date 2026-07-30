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

namespace HyperfTest\Support;

use Hyperf\DbConnection\Db;
use RuntimeException;

/**
 * Tenant descartável para a suíte.
 *
 * Antes, todo teste fixava `cd_cliente => 1` e `cd_perfil => 1`. Nenhum dos dois existe:
 * `saas_cliente` começa em 20 e `lgin_perfil` em 79. O insert em `unim_pessoa` violava a FK
 * `FK_E8726A743DA3A29C` (SQLSTATE 23000), o DatabaseExceptionHandler traduzia 23000 para
 * 409 — e o 409 parecia "login duplicado", mandando quem investigava para o lado errado.
 * Eram 26 erros e 7 falhas, todos com essa origem.
 *
 * A saída NÃO é reaproveitar um cliente real: `PessoaRepositoryTest` afirma
 * `assertSame(1, $resultado['total'])` filtrando nome por LIKE, e qualquer tenant povoado
 * (o cliente 20 já tem 2 pessoas com "Teste" no nome) quebraria a contagem. Um tenant
 * criado só para a rodada nasce vazio, então as asserções absolutas seguem valendo.
 *
 * Cria o mínimo: um `saas_cliente` e um `lgin_perfil` dele. Reaproveita `saas_idioma` e
 * `lgin_grupo` existentes, porque essas duas são tabelas de domínio, não massa.
 */
final class TenantDeTeste
{
    /**
     * Marca em ds_chave/ds_perfil. É por ela que a limpeza acha resíduo de rodada abortada,
     * então não pode mudar sem migrar o que ficou para trás.
     */
    public const MARCA = 'LUMINA_SUITE_AUTOMATIZADA';

    private static ?int $cdCliente = null;

    /**
     * @var array<string, int>
     */
    private static array $cdPerfis = [];

    public static function cdCliente(): int
    {
        if (self::$cdCliente !== null) {
            return self::$cdCliente;
        }

        $existente = Db::table('saas_cliente')->where('ds_chave', self::MARCA)->value('cd_cliente');

        if ($existente !== null) {
            return self::$cdCliente = (int) $existente;
        }

        return self::$cdCliente = (int) Db::table('saas_cliente')->insertGetId([
            'ds_cliente' => 'Lumina Suite Automatizada',
            'ds_chave' => self::MARCA,
            'sn_ativo' => 1,
            'sn_organizar_curso_modulo' => 0,
        ]);
    }

    /**
     * Perfil do tenant de teste. AuthServiceTest/AuthRepositoryTest precisam de mais de um
     * (verificam que uma pessoa pode ter vários perfis simultâneos), daí o $nome.
     */
    public static function cdPerfil(string $nome = 'PRINCIPAL'): int
    {
        if (isset(self::$cdPerfis[$nome])) {
            return self::$cdPerfis[$nome];
        }

        $cdCliente = self::cdCliente();
        $dsChave = self::MARCA . '_' . $nome;

        $existente = Db::table('lgin_perfil')
            ->where('cd_cliente', $cdCliente)
            ->where('ds_chave', $dsChave)
            ->value('cd_perfil');

        if ($existente !== null) {
            return self::$cdPerfis[$nome] = (int) $existente;
        }

        return self::$cdPerfis[$nome] = (int) Db::table('lgin_perfil')->insertGetId([
            'cd_cliente' => $cdCliente,
            'cd_grupo' => self::cdGrupo(),
            'ds_perfil' => "Lumina Suite Automatizada ({$nome})",
            'ds_chave' => $dsChave,
            'sn_ativo' => 1,
        ]);
    }

    /**
     * Cliente que com certeza não é o de teste, para exercitar isolamento entre tenants sem
     * precisar de linha no banco (a checagem é sempre um filtro por cd_cliente, então uma
     * busca nunca encontra nada e é isso que o teste quer provar).
     */
    public static function cdClienteInexistente(): int
    {
        return self::cdCliente() * -1;
    }

    /**
     * Tabela de domínio, não massa de teste: qualquer idioma existente serve, só precisa
     * satisfazer a FK unim_coligada.cd_idioma -> saas_idioma.
     */
    public static function cdIdioma(): int
    {
        $cdIdioma = Db::table('saas_idioma')->min('cd_idioma');

        if ($cdIdioma === null) {
            throw new RuntimeException('saas_idioma está vazia — sem ela não é possível criar unim_coligada.');
        }

        return (int) $cdIdioma;
    }

    /**
     * Remove tudo que pertence ao tenant de teste, na ordem que as FKs exigem (filho antes
     * do pai). Chamado no início e no fim da suíte (test/bootstrap.php): no início porque
     * uma rodada abortada no meio deixa resíduo, e resíduo de pessoa faria a contagem do
     * teste de listagem falhar na rodada seguinte.
     */
    public static function limpar(): void
    {
        $cdCliente = Db::table('saas_cliente')->where('ds_chave', self::MARCA)->value('cd_cliente');

        if ($cdCliente === null) {
            return;
        }

        $cdCliente = (int) $cdCliente;

        $cdPessoas = Db::table('unim_pessoa')->where('cd_cliente', $cdCliente)->pluck('cd_pessoa')->all();
        $cdPerfis = Db::table('lgin_perfil')->where('cd_cliente', $cdCliente)->pluck('cd_perfil')->all();

        if ($cdPessoas !== []) {
            Db::table('lgin_pessoa_perfil')->whereIn('cd_pessoa', $cdPessoas)->delete();
            Db::table('unim_pessoa_fisica')->whereIn('cd_pessoa', $cdPessoas)->delete();
            Db::table('unim_pessoa_juridica')->whereIn('cd_pessoa', $cdPessoas)->delete();
        }

        if ($cdPerfis !== []) {
            Db::table('lgin_perfil_recurso_privilegio')->whereIn('cd_perfil', $cdPerfis)->delete();
        }

        Db::table('unim_coligada')->where('cd_cliente', $cdCliente)->delete();
        Db::table('unim_pessoa')->where('cd_cliente', $cdCliente)->delete();
        Db::table('lgin_perfil')->where('cd_cliente', $cdCliente)->delete();
        Db::table('saas_cliente')->where('cd_cliente', $cdCliente)->delete();

        self::$cdCliente = null;
        self::$cdPerfis = [];
    }

    /**
     * Cria a coligada que liga pessoa -> perfil (lgin_pessoa_perfil.cd_coligada é NOT NULL e
     * tem FK). Devolve o cd_coligada.
     */
    public static function criarColigada(int $cdPessoa): int
    {
        return (int) Db::table('unim_coligada')->insertGetId([
            'cd_pessoa' => $cdPessoa,
            'cd_cliente' => self::cdCliente(),
            'cd_idioma' => self::cdIdioma(),
            'ds_coligada' => 'Lumina Suite Automatizada',
        ]);
    }

    /**
     * Vincula a pessoa ao perfil do tenant de teste, criando a coligada necessária.
     */
    public static function vincularPerfil(int $cdPessoa): void
    {
        Db::table('lgin_pessoa_perfil')->insert([
            'cd_pessoa' => $cdPessoa,
            'cd_perfil' => self::cdPerfil(),
            'cd_coligada' => self::criarColigada($cdPessoa),
        ]);
    }

    private static function cdGrupo(): int
    {
        $cdGrupo = Db::table('lgin_grupo')->min('cd_grupo');

        if ($cdGrupo === null) {
            throw new RuntimeException('lgin_grupo está vazia — sem ela não é possível criar lgin_perfil.');
        }

        return (int) $cdGrupo;
    }
}
