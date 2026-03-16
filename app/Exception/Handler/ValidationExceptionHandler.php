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

use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function Hyperf\Support\env;

class ValidationExceptionHandler extends ExceptionHandler
{
    private const int C_ERROR_CODE = 422;

    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        /** @var ValidationException $throwable */
        $errors = $throwable->validator->errors()->all();

        $arrPayload = [
            'status' => self::C_ERROR_CODE,
            'error' => 'Erro de validação',
            'messages' => $errors,
        ];

        if (env('APP_ENV') == 'dev') {
            $arrPayload['dev'] = $throwable->getTraceAsString();
        }

        $data = json_encode($arrPayload, JSON_UNESCAPED_UNICODE);

        return $response->withStatus(self::C_ERROR_CODE)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new SwooleStream($data));
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof ValidationException;
    }
}
