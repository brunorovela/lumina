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

use App\Exception\HttpAwareException;
use App\Support\ApiResponse;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class AppExceptionHandler extends ExceptionHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        if ($throwable instanceof HttpAwareException) {
            return ApiResponse::erroHttp($response, $throwable->getStatusCode(), $throwable->getMessage());
        }

        $traceId = bin2hex(random_bytes(6));

        $this->logger->error($throwable->getMessage(), ['exception' => $throwable, 'trace_id' => $traceId]);

        return ApiResponse::erroHttp(
            $response,
            500,
            "Erro interno. Código: {$traceId}. Tente novamente em instantes."
        );
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
