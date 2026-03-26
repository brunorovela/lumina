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

namespace App\Support;

use Hyperf\HttpServer\Router\Dispatched;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Lê acl_resource / acl_level de atributos do request ou das opções da rota (Hyperf Handler).
 *
 * @return array{0: ?string, 1: int}
 */
final class AclRouteOptions
{
    public static function resolve(ServerRequestInterface $request): array
    {
        $resource = $request->getAttribute('acl_resource');
        $level = (int) $request->getAttribute('acl_level', 0);

        if ($resource !== null && $resource !== '') {
            return [$resource, $level];
        }

        $dispatched = $request->getAttribute(Dispatched::class);
        if (! $dispatched instanceof Dispatched || ! $dispatched->isFound() || $dispatched->handler === null) {
            return [null, 0];
        }

        $options = $dispatched->handler->options;
        $nested = $options['options'] ?? [];

        $resource = $options['acl_resource'] ?? $nested['acl_resource'] ?? null;
        $level = (int) ($options['acl_level'] ?? $nested['acl_level'] ?? 0);

        return [$resource, $level];
    }
}
