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
use App\Exception\Handler\AppExceptionHandler;
use App\Exception\Handler\DatabaseExceptionHandler;
use App\Exception\Handler\RouteExceptionHandler;
use App\Exception\Handler\ValidationExceptionHandler;

/*
 * This file is part of Hyperf.
 *
 * @see     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
return [
    'handler' => [
        'http' => [
            ValidationExceptionHandler::class,
            DatabaseExceptionHandler::class,
            // Substitui Hyperf\HttpServer\Exception\Handler\HttpExceptionHandler (nativo) —
            // ver App\Exception\Handler\RouteExceptionHandler pro porquê (whole-branch
            // review, Finding 3: 404/405 nativos saem sem o envelope ApiResponse).
            RouteExceptionHandler::class,
            AppExceptionHandler::class,
        ],
    ],
];
