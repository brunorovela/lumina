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
use App\Exception\Pessoa\PessoaNaoEncontradaException;
use Hyperf\HttpMessage\Base\Response;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Testing\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

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

        $corpo = json_decode((string) $resultado->getBody(), true);
        $this->assertSame(false, $corpo['success']);
        $this->assertSame('Pessoa não encontrada.', $corpo['message']);
    }
}
