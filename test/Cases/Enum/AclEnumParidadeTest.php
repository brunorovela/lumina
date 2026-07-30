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

namespace HyperfTest\Cases\Enum;

use App\Enum\Privilegio;
use App\Enum\Recurso;
use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Router\DispatcherFactory;
use Hyperf\HttpServer\Router\Handler;
use Hyperf\Testing\TestCase;

/**
 * Guarda-corpo do bug relatado: as opções 'acl' das rotas usavam chaves inventadas
 * ('pessoa', 'listar', 'criar', 'visualizar', 'excluir') que não existem em
 * ulms_recurso / ulms_privilegio. Como o ACL resolve permissão comparando ds_chave,
 * chave inexistente nega tudo em silêncio e nenhum teste pegava — os testes HTTP
 * plantavam o cache Redis com as mesmas chaves inventadas.
 *
 * Estes testes batem enum e rota contra o banco de verdade.
 *
 * @internal
 * @coversNothing
 */
class AclEnumParidadeTest extends TestCase
{
    public function testTodoRecursoDoEnumExisteEmUlmsRecurso()
    {
        $noBanco = Db::table('ulms_recurso')->pluck('ds_chave')->all();

        foreach (Recurso::cases() as $recurso) {
            $this->assertContains(
                $recurso->value,
                $noBanco,
                "Recurso::{$recurso->name} não existe em ulms_recurso.ds_chave."
            );
        }
    }

    public function testTodoPrivilegioDoEnumExisteEmUlmsPrivilegio()
    {
        $noBanco = Db::table('ulms_privilegio')->pluck('ds_chave')->all();

        foreach (Privilegio::cases() as $privilegio) {
            $this->assertContains(
                $privilegio->value,
                $noBanco,
                "Privilegio::{$privilegio->name} não existe em ulms_privilegio.ds_chave."
            );
        }
    }

    /**
     * O enum cobrir o banco não basta: o par recurso+privilégio precisa existir em
     * ulms_recurso_privilegio, senão nenhum perfil consegue receber a permissão e a
     * rota fica inalcançável (403 permanente).
     */
    public function testTodoParAclDeRotaExisteEmUlmsRecursoPrivilegio()
    {
        $paresNoBanco = Db::table('ulms_recurso_privilegio as urp')
            ->join('ulms_recurso as ur', 'ur.cd_recurso', '=', 'urp.cd_recurso')
            ->join('ulms_privilegio as up', 'up.cd_privilegio', '=', 'urp.cd_privilegio')
            ->select('ur.ds_chave as recurso', 'up.ds_chave as privilegio')
            ->get()
            ->map(fn ($linha) => "{$linha->recurso}.{$linha->privilegio}")
            ->all();

        $paresDasRotas = $this->paresAclDasRotas();

        $this->assertNotEmpty($paresDasRotas, 'Nenhuma rota com opção "acl" foi encontrada — o teste perderia o sentido.');

        foreach ($paresDasRotas as $par) {
            $this->assertContains(
                $par,
                $paresNoBanco,
                "O par ACL {$par} usado em config/routes.php não existe em ulms_recurso_privilegio."
            );
        }
    }

    /**
     * @return string[] pares no formato RECURSO.PRIVILEGIO
     */
    private function paresAclDasRotas(): array
    {
        $dados = $this->getContainer()->get(DispatcherFactory::class)->getRouter('http')->getData();

        $pares = [];

        foreach ($this->handlers($dados) as $handler) {
            $acl = $handler->options['acl'] ?? null;

            if (! is_array($acl)) {
                continue;
            }

            $recurso = $acl['recurso'] instanceof Recurso ? $acl['recurso'] : Recurso::from((string) $acl['recurso']);
            $privilegio = $acl['privilegio'] instanceof Privilegio ? $acl['privilegio'] : Privilegio::from((string) $acl['privilegio']);

            $pares["{$recurso->value}.{$privilegio->value}"] = true;
        }

        return array_keys($pares);
    }

    /**
     * getData() devolve [rotas estáticas, rotas com variável] em formatos aninhados
     * diferentes; varrer recursivamente evita depender do formato interno do
     * DataGenerator do FastRoute.
     *
     * @return iterable<Handler>
     */
    private function handlers(array $dados): iterable
    {
        foreach ($dados as $valor) {
            if ($valor instanceof Handler) {
                yield $valor;
            } elseif (is_array($valor)) {
                yield from $this->handlers($valor);
            }
        }
    }
}
