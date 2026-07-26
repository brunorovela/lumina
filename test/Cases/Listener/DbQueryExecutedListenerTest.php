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

namespace HyperfTest\Cases\Listener;

use App\Listener\DbQueryExecutedListener;
use Hyperf\Database\Events\QueryExecuted;
use Hyperf\DbConnection\Db;
use Hyperf\Testing\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * Finding 11 (whole-branch review): este listener interpola os bindings direto no SQL
 * logado -- inclui hash bcrypt de senha e PII (CPF/CNPJ/nome/login) em texto puro, sempre
 * no nível DEBUG. Confirma que ele para de logar quando APP_ENV=production e continua
 * logando fora disso.
 *
 * @internal
 * @coversNothing
 */
class DbQueryExecutedListenerTest extends TestCase
{
    private ?string $appEnvOriginal = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appEnvOriginal = getenv('APP_ENV') ?: null;
    }

    protected function tearDown(): void
    {
        if ($this->appEnvOriginal === null) {
            putenv('APP_ENV');
            unset($_ENV['APP_ENV']);
        } else {
            putenv("APP_ENV={$this->appEnvOriginal}");
            $_ENV['APP_ENV'] = $this->appEnvOriginal;
        }

        parent::tearDown();
    }

    public function testNaoLogaQuandoAppEnvEhProduction()
    {
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        $listener = $this->criarListenerComLoggerFake($logger);
        $listener->process($this->criarEventoDeTeste());
    }

    public function testContinuaLogandoForaDeProduction()
    {
        putenv('APP_ENV=dev');
        $_ENV['APP_ENV'] = 'dev';

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $listener = $this->criarListenerComLoggerFake($logger);
        $listener->process($this->criarEventoDeTeste());
    }

    private function criarListenerComLoggerFake(LoggerInterface $logger): DbQueryExecutedListener
    {
        $listener = $this->getContainer()->get(DbQueryExecutedListener::class);

        $propriedade = new ReflectionProperty(DbQueryExecutedListener::class, 'logger');
        $propriedade->setAccessible(true);
        $propriedade->setValue($listener, $logger);

        return $listener;
    }

    private function criarEventoDeTeste(): QueryExecuted
    {
        return new QueryExecuted(
            'select * from unim_pessoa where ds_login = ?',
            ['usuario.teste'],
            0.5,
            Db::connection()
        );
    }
}
