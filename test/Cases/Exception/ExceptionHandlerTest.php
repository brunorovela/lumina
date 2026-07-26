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

namespace HyperfTest\Cases\Exception;

use App\Exception\Handler\AppExceptionHandler;
use App\Exception\Handler\DatabaseExceptionHandler;
use App\Exception\Handler\RouteExceptionHandler;
use App\Exception\Pessoa\PessoaNaoEncontradaException;
use Hyperf\Database\Exception\QueryException;
use Hyperf\HttpMessage\Base\Response;
use Hyperf\HttpMessage\Exception\MethodNotAllowedHttpException;
use Hyperf\HttpMessage\Exception\NotFoundHttpException;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Testing\TestCase;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class ExceptionHandlerTest extends TestCase
{
    public function testAppExceptionHandlerFormataExcecaoDeDominio()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $handler = new AppExceptionHandler($logger);

        /** @var ResponseInterface $response */
        $response = (new Response())->withBody(new SwooleStream(''));

        $resultado = $handler->handle(new PessoaNaoEncontradaException(), $response);

        $this->assertSame(404, $resultado->getStatusCode());
        $this->assertSame('application/json', $resultado->getHeaderLine('Content-Type'));

        $corpo = json_decode((string) $resultado->getBody(), true);
        $this->assertSame(false, $corpo['success']);
        $this->assertSame('Pessoa não encontrada.', $corpo['message']);
    }

    public function testAppExceptionHandlerDevolve500ComTraceIdCorrelacionavelAoLog()
    {
        // Finding 10 (whole-branch review): 500 sem trace_id não dava pra correlacionar
        // a resposta ao log correspondente. Confirma que o mesmo trace_id aparece nos dois.
        $traceIdCapturado = null;

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'algo quebrou',
                $this->callback(function (array $contexto) use (&$traceIdCapturado) {
                    $traceIdCapturado = $contexto['trace_id'] ?? null;

                    return isset($contexto['exception'], $contexto['trace_id']);
                })
            );

        $handler = new AppExceptionHandler($logger);

        /** @var ResponseInterface $response */
        $response = (new Response())->withBody(new SwooleStream(''));

        $resultado = $handler->handle(new RuntimeException('algo quebrou'), $response);

        $this->assertSame(500, $resultado->getStatusCode());
        $this->assertSame('application/json', $resultado->getHeaderLine('Content-Type'));

        $corpo = json_decode((string) $resultado->getBody(), true);
        $this->assertNotNull($traceIdCapturado);
        $this->assertStringContainsString($traceIdCapturado, $corpo['message']);
    }

    public function testDatabaseExceptionHandlerLogaAExcecaoAntesDeResponder()
    {
        // Finding 9 (whole-branch review): falha de banco não deixava rastro no log.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $handler = new DatabaseExceptionHandler($logger);

        /** @var ResponseInterface $response */
        $response = (new Response())->withBody(new SwooleStream(''));

        $excecao = new QueryException(
            'select 1',
            [],
            new PDOException('erro de conexão', 2006),
            'mysql'
        );

        $resultado = $handler->handle($excecao, $response);

        $this->assertSame(400, $resultado->getStatusCode());
        $this->assertSame('application/json', $resultado->getHeaderLine('Content-Type'));
    }

    public function testRouteExceptionHandlerFormataNotFoundComEnvelopePadrao()
    {
        // Finding 3 (whole-branch review): 404/405 nativos saíam como texto cru
        // ("Not Found"/"Method Not Allowed"), sem o envelope ApiResponse nem
        // Content-Type: application/json.
        $handler = new RouteExceptionHandler();

        /** @var ResponseInterface $response */
        $response = (new Response())->withBody(new SwooleStream(''));

        $this->assertTrue($handler->isValid(new NotFoundHttpException()));
        $this->assertTrue($handler->isValid(new MethodNotAllowedHttpException('Allow: GET')));
        $this->assertFalse($handler->isValid(new RuntimeException()));

        $resultado = $handler->handle(new NotFoundHttpException(), $response);

        $this->assertSame(404, $resultado->getStatusCode());
        $this->assertSame('application/json', $resultado->getHeaderLine('Content-Type'));

        $corpo = json_decode((string) $resultado->getBody(), true);
        $this->assertSame(false, $corpo['success']);
        $this->assertSame('Not Found', $corpo['message']);
    }

    public function testRouteExceptionHandlerFormataMethodNotAllowed()
    {
        $handler = new RouteExceptionHandler();

        /** @var ResponseInterface $response */
        $response = (new Response())->withBody(new SwooleStream(''));

        $resultado = $handler->handle(new MethodNotAllowedHttpException('Allow: GET, POST'), $response);

        $this->assertSame(405, $resultado->getStatusCode());

        $corpo = json_decode((string) $resultado->getBody(), true);
        $this->assertSame('Allow: GET, POST', $corpo['message']);
    }
}
