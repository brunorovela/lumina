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

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\OpenApi(
    openapi: '3.0.0',
    info: new OA\Info(title: 'Lumina', version: '1.0.0')
)]
final class LuminaApi
{
}
