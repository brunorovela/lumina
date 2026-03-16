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
use App\Constants\MySqlError;
use Hyperf\Database\Exception\QueryException;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function Hyperf\Support\env;

class DatabaseExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        /** @var QueryException $throwable */
        $message = $throwable->getMessage();
        $errorCode = (int) ($throwable->errorInfo[1] ?? 0);

        // Tenta mapear o código do MySQL para o nosso Enum de Erros SQL
        $mysqlError = MySqlError::tryFrom($errorCode);

        // Define o status HTTP inicial como 400 (Bad Request) por padrão para erros de query
        $httpStatus = HttpStatus::BAD_REQUEST;
        $errorMessage = $mysqlError ? $mysqlError->getLabel() : 'Erro de Banco de Dados';
        $detailMessage = 'Ocorreu um erro inesperado na operação de dados.';

        // Refina o Status HTTP e a mensagem baseado no erro específico do MySQL
        switch ($mysqlError) {
            case MySqlError::DUPLICATE_ENTRY:
                $httpStatus = HttpStatus::CONFLICT; // 409
                preg_match("/for key '(.+?)'/", $message, $matches);
                $column = $matches[1] ?? 'desconhecido';
                $detailMessage = "O valor informado para o campo ou índice '{$column}' já existe.";
                break;
            case MySqlError::ROW_IS_REFERENCED:
            case MySqlError::NO_REFERENCED_ROW:
                $httpStatus = HttpStatus::BAD_REQUEST; // 400
                preg_match('/FOREIGN KEY \(`(.+?)`\)/', $message, $matches);
                $column = $matches[1] ?? 'desconhecido';
                $detailMessage = "O campo '{$column}' referencia um registro inexistente ou está sendo usado em outro lugar.";
                break;
            default:
                $httpStatus = HttpStatus::INTERNAL_SERVER_ERROR; // 500
                break;
        }

        $body = [
            'status' => $httpStatus->value,
            'error' => $errorMessage,
            'messages' => [$detailMessage],
        ];

        // Dados de debug (apenas fora de produção)
        if (env('APP_ENV') !== 'production') {
            $body['dev'] = [
                'mysql_error' => $mysqlError?->name,
                'sql_code' => $errorCode,
                'raw_message' => $message,
                'sql' => $throwable->getSql(),
            ];
        }

        $data = json_encode($body, JSON_UNESCAPED_UNICODE);

        return $response->withStatus($httpStatus->value)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new SwooleStream($data));
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof QueryException;
    }
}
