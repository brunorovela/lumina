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

namespace App\Constants;

/**
 * Centraliza os códigos de status HTTP utilizados no LMS.
 */
enum HttpStatus: int
{
    // Sucesso
    case OK = 200;
    case CREATED = 201;
    case NO_CONTENT = 204;

    // Erros do Cliente
    case BAD_REQUEST = 400;
    case UNAUTHORIZED = 401;
    case FORBIDDEN = 403;
    case NOT_FOUND = 404;
    case CONFLICT = 409;
    case UNPROCESSABLE_ENTITY = 422;

    // Erros do Servidor
    case INTERNAL_SERVER_ERROR = 500;

    /**
     * Retorna a descrição amigável do status.
     */
    public function getMessage(): string
    {
        return match ($this) {
            self::OK => 'Operação realizada com sucesso.',
            self::CREATED => 'Recurso criado com sucesso.',
            self::NO_CONTENT => 'Sem conteúdo.',
            self::BAD_REQUEST => 'Requisição inválida.',
            self::UNAUTHORIZED => 'Não autorizado.',
            self::FORBIDDEN => 'Acesso negado.',
            self::NOT_FOUND => 'Recurso não encontrado.',
            self::CONFLICT => 'Conflito de dados.',
            self::UNPROCESSABLE_ENTITY => 'Erro de validação.',
            self::INTERNAL_SERVER_ERROR => 'Ocorreu um erro interno no servidor.',
        };
    }
}
