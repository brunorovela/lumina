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

namespace HyperfTest\Cases;

use App\Controller\PessoaController;
use App\Middleware\AclMiddleware;
use App\Support\AclRouteOptions;
use FastRoute\Dispatcher;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpServer\Router\Dispatched;
use Hyperf\HttpServer\Router\Handler;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class AclRouteOptionsTest extends TestCase
{
    public function testResolveFromHandlerOptionsFlat(): void
    {
        $routeHandler = new Handler([PessoaController::class, 'index'], '/pessoa', [
            'middleware' => [AclMiddleware::class],
            'acl_resource' => 'Pessoa.Gerenciamento',
            'acl_level' => 2,
        ]);
        $dispatched = new Dispatched([Dispatcher::FOUND, $routeHandler, []]);
        $request = (new Request('GET', 'http://127.0.0.1/pessoa'))->withAttribute(Dispatched::class, $dispatched);

        [$resource, $level] = AclRouteOptions::resolve($request);

        $this->assertSame('Pessoa.Gerenciamento', $resource);
        $this->assertSame(2, $level);
    }

    public function testResolveFromNestedOptionsArray(): void
    {
        $routeHandler = new Handler([PessoaController::class, 'index'], '/pessoa', [
            'middleware' => [AclMiddleware::class],
            'options' => [
                'acl_resource' => 'Pessoa.Gerenciamento',
                'acl_level' => 4,
            ],
        ]);
        $dispatched = new Dispatched([Dispatcher::FOUND, $routeHandler, []]);
        $request = (new Request('GET', 'http://127.0.0.1/pessoa'))->withAttribute(Dispatched::class, $dispatched);

        [$resource, $level] = AclRouteOptions::resolve($request);

        $this->assertSame('Pessoa.Gerenciamento', $resource);
        $this->assertSame(4, $level);
    }

    public function testRequestAttributesOverrideHandler(): void
    {
        $routeHandler = new Handler([PessoaController::class, 'index'], '/pessoa', [
            'acl_resource' => 'X',
            'acl_level' => 1,
        ]);
        $dispatched = new Dispatched([Dispatcher::FOUND, $routeHandler, []]);
        $request = (new Request('GET', 'http://127.0.0.1/pessoa'))
            ->withAttribute(Dispatched::class, $dispatched)
            ->withAttribute('acl_resource', 'Override.Resource')
            ->withAttribute('acl_level', 8);

        [$resource, $level] = AclRouteOptions::resolve($request);

        $this->assertSame('Override.Resource', $resource);
        $this->assertSame(8, $level);
    }
}
