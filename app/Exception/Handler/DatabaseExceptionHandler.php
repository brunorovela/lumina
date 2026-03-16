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

use Hyperf\Database\Exception\QueryException;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function Hyperf\Support\env;

class DatabaseExceptionHandler extends ExceptionHandler
{
    private const int C_ERROR_CODE = 409;

    private const string C_ERROR_DUPLICATE_CODE = '23000';

    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        // Se for erro de duplicidade (MySQL Error 1062)
        if ($throwable instanceof QueryException
            && $throwable->getCode() === self::C_ERROR_DUPLICATE_CODE
        ) {
            $arrPayload = [
                'error' => 'Registro duplicado',
                'message' => 'Os dados informados já constam no sistema.',
            ];

            if (env('APP_ENV') == 'dev') {
                $arrPayload['dev'] = $throwable->getTraceAsString();
            }

            $data = json_encode($arrPayload, JSON_UNESCAPED_UNICODE);

            return $response->withStatus(self::C_ERROR_CODE)
                ->withHeader('Content-Type', 'application/json')
                ->withBody(new SwooleStream($data));
        }

        return $response;
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof QueryException;
    }
}
