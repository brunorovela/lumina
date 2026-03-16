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
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function Hyperf\Support\env;

class AppExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $status = HttpStatus::INTERNAL_SERVER_ERROR;

        $arrPayload = [
            'status' => $status->value,
            'error' => $status->getMessage(),
            'messages' => ['Ocorreu um erro interno inesperado no servidor.'],
        ];

        // 3. Lógica de Debug para ambientes que não sejam produção
        if (env('APP_ENV') !== 'production') {
            $arrPayload['dev'] = [
                'exception' => get_class($throwable),
                'message' => $throwable->getMessage(),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'trace' => explode("\n", $throwable->getTraceAsString()), // Array facilita a leitura no JSON
            ];
        }

        $data = json_encode($arrPayload, JSON_UNESCAPED_UNICODE);

        // 4. Retorno padronizado
        return $response->withStatus($status->value)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new SwooleStream($data));
    }

    public function isValid(Throwable $throwable): bool
    {
        // Sempre verdadeiro pois é a última rede de proteção da aplicação
        return true;
    }
}
