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

namespace HyperfTest\Cases\Support;

use App\Support\ApiResponse;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ApiResponseTest extends TestCase
{
    public function testSucessoSemMeta()
    {
        $resultado = ApiResponse::sucesso(['id' => 1]);

        $this->assertSame(['success' => true, 'data' => ['id' => 1]], $resultado);
    }

    public function testSucessoComMeta()
    {
        $resultado = ApiResponse::sucesso([1, 2], ['total' => 2]);

        $this->assertSame(
            ['success' => true, 'data' => [1, 2], 'meta' => ['total' => 2]],
            $resultado
        );
    }

    public function testErroSemErrors()
    {
        $resultado = ApiResponse::erro('Pessoa não encontrada.');

        $this->assertSame(
            ['success' => false, 'message' => 'Pessoa não encontrada.'],
            $resultado
        );
    }

    public function testErroComErrors()
    {
        $resultado = ApiResponse::erro('Validação falhou.', ['ds_nome' => ['obrigatório']]);

        $this->assertSame(
            ['success' => false, 'message' => 'Validação falhou.', 'errors' => ['ds_nome' => ['obrigatório']]],
            $resultado
        );
    }
}
