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

namespace App\Exception\Auth;

use App\Exception\HttpAwareException;

class CredenciaisInvalidasException extends HttpAwareException
{
    public function __construct()
    {
        parent::__construct('Login ou senha inválidos.');
    }

    public function getStatusCode(): int
    {
        return 401;
    }
}
