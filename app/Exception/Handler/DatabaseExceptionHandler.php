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

namespace App\Exception\Handler;

use App\Support\ApiResponse;
use Hyperf\Database\Exception\QueryException;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class DatabaseExceptionHandler extends ExceptionHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        /** @var QueryException $throwable */
        $codigoSqlDuplicado = '23000';
        $status = str_contains((string) $throwable->getCode(), $codigoSqlDuplicado) ? 409 : 400;

        $this->logger->error($throwable->getMessage(), ['exception' => $throwable]);

        return ApiResponse::erroHttp($response, $status, 'Não foi possível concluir a operação no banco de dados.');
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof QueryException;
    }
}
