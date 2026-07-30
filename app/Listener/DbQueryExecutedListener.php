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

namespace App\Listener;

use DateTimeInterface;
use Hyperf\Collection\Arr;
use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function Hyperf\Support\env;

#[Listener]
class DbQueryExecutedListener implements ListenerInterface
{
    private LoggerInterface $logger;

    public function __construct(ContainerInterface $container)
    {
        // ContainerInterface::get() devolve mixed (contrato PSR-11), então sem o guard a
        // atribuição abaixo é "mixed em propriedade LoggerInterface".
        $fabrica = $container->get(LoggerFactory::class);

        if (! $fabrica instanceof LoggerFactory) {
            throw new RuntimeException('LoggerFactory não está registrado no container.');
        }

        $this->logger = $fabrica->get('sql');
    }

    /**
     * @return string[]
     */
    public function listen(): array
    {
        return [
            QueryExecuted::class,
        ];
    }

    public function process(object $event): void
    {
        // LGPD: este log interpola os bindings direto no SQL -- inclui hash bcrypt de
        // senha e PII (CPF/CNPJ/nome/login) em texto puro, sempre no nível DEBUG. Não
        // existe motivo pra isso rodar em produção; risco fica restrito a dev/staging,
        // onde o dado real de LGPD não deveria estar de qualquer forma (Finding 11,
        // whole-branch review).
        if (env('APP_ENV') === 'production') {
            return;
        }

        if (! $event instanceof QueryExecuted) {
            return;
        }

        $sql = $event->sql;

        if (! Arr::isAssoc($event->bindings)) {
            $position = 0;

            foreach ($event->bindings as $value) {
                $position = strpos($sql, '?', $position);

                if ($position === false) {
                    break;
                }

                $literal = "'" . self::paraTexto($value) . "'";
                $sql = substr_replace($sql, $literal, $position, 1);
                $position += strlen($literal);
            }
        }

        $this->logger->info(sprintf('[%s] %s', $event->time, $sql));
    }

    /**
     * Binding é mixed (pode vir DateTimeInterface, bool, null...). Interpolar direto numa
     * string é conversão implícita de mixed; este match mantém exatamente o texto que a
     * interpolação produzia para escalar e null, e trata objeto em vez de estourar.
     */
    private static function paraTexto(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_scalar($value) => (string) $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => (string) json_encode($value),
        };
    }
}
