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

use App\Constants\HttpStatus;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function Hyperf\Support\env;

class ValidationExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        /** @var ValidationException $throwable */
        $status = HttpStatus::UNPROCESSABLE_ENTITY; // Centralizado no Enum
        $errors = $throwable->validator->errors()->all();

        $arrPayload = [
            'status' => $status->value,
            'error' => $status->getMessage(),
            'messages' => $errors,
        ];

        // Melhoria no Debug: Verificamos se NÃO é produção para ser mais abrangente
        if (env('APP_ENV') !== 'production') {
            $arrPayload['dev'] = [
                'message' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ];
        }

        $data = json_encode($arrPayload, JSON_UNESCAPED_UNICODE);

        // Usamos $status->value tanto para o corpo quanto para o status da resposta
        return $response->withStatus($status->value)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new SwooleStream($data));
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof ValidationException;
    }
}
