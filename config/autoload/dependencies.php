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
use App\Repository\Auth\AuthRepository;
use App\Repository\Auth\AuthRepositoryInterface;
use App\Repository\Dominio\DominioRepository;
use App\Repository\Dominio\DominioRepositoryInterface;
use App\Repository\Pessoa\PessoaRepository;
use App\Repository\Pessoa\PessoaRepositoryInterface;
use Hyperf\HttpServer\Response;
use Psr\Http\Message\ResponseInterface;

return [
    PessoaRepositoryInterface::class => PessoaRepository::class,
    AuthRepositoryInterface::class => AuthRepository::class,
    DominioRepositoryInterface::class => DominioRepository::class,
    // AuthMiddleware/AclMiddleware injetam Psr\Http\Message\ResponseInterface diretamente no
    // construtor (em vez do Hyperf\HttpServer\Contract\ResponseInterface usado pelos
    // controllers). O skeleton do Hyperf só registra o binding do contrato próprio; sem esta
    // entrada o container não sabe instanciar a interface PSR pura e o DI explode com
    // "the class is not instantiable" assim que qualquer rota atrás desses middlewares é
    // chamada de verdade. Hyperf\HttpServer\Response implementa as duas interfaces e resolve
    // a resposta real da coroutine via ResponseContext::get(), então o binding é seguro.
    ResponseInterface::class => Response::class,
];
