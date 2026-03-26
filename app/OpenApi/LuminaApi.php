<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\OpenApi(
    openapi: '3.0.0',
    info: new OA\Info(title: 'Lumina', version: '1.0.0')
)]
final class LuminaApi
{
}
