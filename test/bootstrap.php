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
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Di\ClassLoader;
use Hyperf\Engine\DefaultOption;
use HyperfTest\Support\TenantDeTeste;

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');

error_reporting(E_ALL);
// PHP roda em UTC por padrão (date.timezone=UTC no php.ini da imagem), mas o MySQL
// (mysql_84) roda com @@time_zone=SYSTEM = America/Sao_Paulo e as colunas de data do
// schema (dt_excluido, dt_cadastro, dt_base, ...) são DATETIME (não TIMESTAMP) — valor
// literal, sem conversão de timezone na leitura/escrita. Carbon::now() (usado por
// SoftDeletes::runSoftDelete() via Model::freshTimestamp()) sem timezone explícita lê
// date_default_timezone_get(): em UTC, soft-delete gravava dt_excluido 3h no futuro em
// relação ao resto da tabela (Finding 12, whole-branch review). A chave 'timezone' de
// config/autoload/databases.php (Hyperf\Database\Connectors\MySqlConnector::setTimezone(),
// que faz `SET time_zone=...` na sessão) NÃO resolve isso: só afeta conversão de colunas
// TIMESTAMP e o valor de NOW()/CURDATE() do lado do servidor — não o literal DATETIME que
// o PHP já monta e envia. Por isso o fix é mesmo o timezone do processo PHP.
date_default_timezone_set('America/Sao_Paulo');

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));

require BASE_PATH . '/vendor/autoload.php';

! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', DefaultOption::hookFlags());

ClassLoader::init();

$container = require BASE_PATH . '/config/container.php';

$container->get(ApplicationInterface::class);

// Limpa resíduo de rodada abortada ANTES de começar: sobra de pessoa no tenant de teste faz
// a contagem de PessoaRepositoryTest::testListar... falhar na rodada seguinte. E limpa de
// novo no fim, para não deixar massa na base compartilhada.
//
// Roda dentro de corrotina porque o pool de conexões do Hyperf exige contexto de corrotina.
Swoole\Coroutine\run(static function () {
    TenantDeTeste::limpar();
});

register_shutdown_function(static function () {
    Swoole\Coroutine\run(static function () {
        TenantDeTeste::limpar();
    });
});
