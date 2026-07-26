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

namespace HyperfTest\Cases\Middleware;

use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\HttpServer\Router\DispatcherFactory;
use Hyperf\HttpServer\Router\Router;
use Hyperf\Testing\TestCase;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @internal
 * @coversNothing
 */
class AclMiddlewareTest extends TestCase
{
    public function testOpcaoCustomizadaAclSobreviveNasOpcoesDaRota()
    {
        // Força a inicialização do DispatcherFactory (e, com ela, Router::init())
        // antes de registrar a rota de teste — sem isso, Router::$factory ainda
        // está null neste processo e Router::get() explode com "getRouter() on null".
        $this->getContainer()->get(DispatcherFactory::class);

        Router::get(
            '/__teste_acl_options',
            static fn () => 'ok',
            ['acl' => ['recurso' => 'pessoa', 'privilegio' => 'listar']]
        );

        $resposta = $this->get('/__teste_acl_options');

        $resposta->assertStatus(200);
        // ATENÇÃO: esta asserção de status 200 NÃO valida a suposição central —
        // uma rota sem nenhuma opção 'acl' responderia 200 do mesmo jeito. Ela só
        // confirma que Router::get() com um 3º argumento aceita a chave extra sem
        // quebrar o registro da rota. A validação real está no teste abaixo, que
        // inspeciona Dispatched::$handler->options['acl'] de dentro de um middleware.
    }

    /**
     * Prova direta e definitiva da suposição: registra uma rota com a opção
     * customizada 'acl' e um middleware-sonda que lê
     * $request->getAttribute(Dispatched::class)->handler->options['acl'] e
     * grava o valor lido no Context. Se a opção não sobrevivesse até o
     * middleware (ou estivesse em outro lugar), o valor capturado seria null
     * ou diferente do esperado.
     */
    public function testOpcaoAclEstaAcessivelDentroDeUmMiddlewareViaDispatchedHandlerOptions()
    {
        $this->getContainer()->get(DispatcherFactory::class);

        Router::get(
            '/__teste_acl_middleware_probe',
            static fn () => 'ok',
            [
                'middleware' => [AclOptionsProbeMiddleware::class],
                'acl' => ['recurso' => 'pessoa', 'privilegio' => 'listar'],
            ]
        );

        $resposta = $this->get('/__teste_acl_middleware_probe');

        $resposta->assertStatus(200);
        $this->assertTrue(AclOptionsProbeMiddleware::$rodou, 'O middleware registrado via opção "middleware" da rota precisa ter rodado.');
        $this->assertSame(
            ['recurso' => 'pessoa', 'privilegio' => 'listar'],
            AclOptionsProbeMiddleware::$optionsCapturadas
        );
    }
}

/**
 * Middleware-sonda usado apenas por este teste de verificação (Step 5/6 do
 * brief da Task 11) — não faz parte da implementação de produção.
 */
class AclOptionsProbeMiddleware implements MiddlewareInterface
{
    public static ?bool $rodou = null;

    public static mixed $optionsCompletas = null;

    public static mixed $optionsCapturadas = null;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        AclOptionsProbeMiddleware::$rodou = true;

        $dispatched = $request->getAttribute(Dispatched::class);

        AclOptionsProbeMiddleware::$optionsCompletas = $dispatched->handler->options ?? '(sem handler)';
        AclOptionsProbeMiddleware::$optionsCapturadas = $dispatched->handler->options['acl'] ?? null;

        return $handler->handle($request);
    }
}
