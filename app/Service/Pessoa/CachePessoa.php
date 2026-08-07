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

namespace App\Service\Pessoa;

use App\Model\Pessoa\UnimPessoa;
use App\Resource\Pessoa\MapaDeCamposPessoa;
use DateTimeInterface;
use Hyperf\Redis\Redis;
use LogicException;

/**
 * Cache de leitura de GET /pessoas/{id}. Uma chave por pessoa, com as colunas que o mapa
 * expõe — o recorte do ?fields= roda DEPOIS, sobre o dado cacheado, então um cliente que
 * pede `fields=ds_nome` aproveita a mesma chave de quem pediu `fields=*`.
 *
 * Escolhas que valem saber antes de mexer:
 *
 * - A chave inclui cd_cliente porque o dado é de tenant: `pessoa:{cd_cliente}:{cd_pessoa}`.
 *   Sem isso, uma pessoa lida pelo cliente A voltaria para o cliente B no cache hit, furando
 *   o WHERE cd_cliente que o Repository aplica.
 * - Só entram as colunas de MapaDeCamposPessoa::colunas(). ds_senha não está no mapa, logo
 *   nunca chega ao Redis — a hash bcrypt não fica exposta em cache.
 * - 404 NÃO é cacheado. Pessoa inexistente continua batendo no banco a cada requisição; o
 *   ganho não compensaria ter de invalidar chave negativa na criação.
 * - Cache corrompido ou de formato antigo cai para o banco em vez de virar resposta pela
 *   metade (mesma postura de AclService).
 * - A listagem (GET /pessoas) NÃO usa este cache: a resposta depende de filtro, página e
 *   fields, e invalidar isso corretamente a cada escrita é outro problema.
 */
class CachePessoa
{
    /**
     * Uma hora, como pedido no contrato desta API. Está documentado no Swagger do endpoint:
     * mudar aqui é mudar contrato publicado, não só ajuste interno.
     */
    public const TTL_SEGUNDOS = 3600;

    public function __construct(private Redis $redis)
    {
    }

    /**
     * Pessoa do cache, já hidratada como model (casts aplicados, sem tocar o banco), ou
     * null quando não há cache utilizável.
     */
    public function buscar(int $cdCliente, int $cdPessoa): ?UnimPessoa
    {
        $cacheado = $this->redis->get($this->chave($cdCliente, $cdPessoa));

        if (! is_string($cacheado)) {
            return null;
        }

        $atributos = json_decode($cacheado, true);

        // Formato antigo, escrita manual em debug, JSON truncado: qualquer coisa que não
        // tenha TODAS as colunas do mapa é descartada. Devolver um recorte incompleto faria
        // a resposta perder campo em silêncio, que é pior que uma ida ao banco.
        if (! is_array($atributos) || ! self::temTodasAsColunas($atributos)) {
            return null;
        }

        $pessoa = new UnimPessoa();

        // newFromBuilder() e não fill(): marca o model como existente e passa pelos casts do
        // mesmo jeito que uma linha vinda do SELECT, sem disparar consulta nenhuma.
        return $pessoa->newFromBuilder($atributos);
    }

    public function guardar(int $cdCliente, int $cdPessoa, UnimPessoa $pessoa): void
    {
        $atributos = [];

        foreach (MapaDeCamposPessoa::colunas() as $coluna) {
            $atributos[$coluna] = self::valorParaCache($pessoa->getAttribute($coluna));
        }

        $this->redis->setex($this->chave($cdCliente, $cdPessoa), self::TTL_SEGUNDOS, (string) json_encode($atributos));
    }

    /**
     * Chamado por toda escrita de pessoa (PUT, PATCH, DELETE). Apagar em vez de reescrever é
     * de propósito: a próxima leitura repopula com o que o banco realmente tem, então uma
     * escrita que muda coluna fora do mapa (ou um gatilho no banco) não deixa cache mentindo.
     */
    public function esquecer(int $cdCliente, int $cdPessoa): void
    {
        $this->redis->del($this->chave($cdCliente, $cdPessoa));
    }

    public function chave(int $cdCliente, int $cdPessoa): string
    {
        return "pessoa:{$cdCliente}:{$cdPessoa}";
    }

    /**
     * @param array<mixed> $atributos
     */
    private static function temTodasAsColunas(array $atributos): bool
    {
        foreach (MapaDeCamposPessoa::colunas() as $coluna) {
            if (! array_key_exists($coluna, $atributos)) {
                return false;
            }
        }

        return true;
    }

    /**
     * JSON não guarda objeto de data de volta como data: um Carbon serializado viraria mapa
     * de propriedades e o cast do model receberia array onde espera string. Data vai como
     * texto no formato que o Eloquent lê de volta; qualquer outro tipo não escalar é bug de
     * programação (coluna nova no mapa com cast exótico) e falha alto em vez de gravar lixo
     * no Redis.
     */
    private static function valorParaCache(mixed $valor): bool|float|int|string|null
    {
        if ($valor === null || is_scalar($valor)) {
            return $valor;
        }

        if ($valor instanceof DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }

        throw new LogicException(
            'Valor de pessoa não é cacheável: ' . get_debug_type($valor)
            . '. Coluna nova no mapa precisa de conversão explícita em CachePessoa.'
        );
    }
}
