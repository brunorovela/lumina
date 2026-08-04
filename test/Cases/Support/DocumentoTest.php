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

use App\Support\Documento;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DocumentoTest extends TestCase
{
    public function testApenasDigitosRemoveMascara()
    {
        $this->assertSame('12345678909', Documento::apenasDigitos('123.456.789-09'));
        $this->assertSame('00000000000191', Documento::apenasDigitos('00.000.000/0001-91'));
        $this->assertSame('01310100', Documento::apenasDigitos('01310-100'));
        $this->assertSame('', Documento::apenasDigitos('sem digito'));
    }

    public function testCpfComDigitoVerificadorCorreto()
    {
        $this->assertTrue(Documento::cpfEhValido('12345678909'));
        $this->assertTrue(Documento::cpfEhValido('52998224725'));
    }

    public function testCpfInvalido()
    {
        // DV que não fecha
        $this->assertFalse(Documento::cpfEhValido('12345678900'));
        // sequência de dígito repetido: DV fecha na aritmética, mas não é CPF
        $this->assertFalse(Documento::cpfEhValido('11111111111'));
        $this->assertFalse(Documento::cpfEhValido('00000000000'));
        // tamanho errado
        $this->assertFalse(Documento::cpfEhValido('1234567890'));
        $this->assertFalse(Documento::cpfEhValido(''));
    }

    public function testCnpjComDigitoVerificadorCorreto()
    {
        $this->assertTrue(Documento::cnpjEhValido('00000000000191'));
        $this->assertTrue(Documento::cnpjEhValido('11222333000181'));
    }

    public function testCnpjInvalido()
    {
        $this->assertFalse(Documento::cnpjEhValido('00000000000192'));
        $this->assertFalse(Documento::cnpjEhValido('11111111111111'));
        $this->assertFalse(Documento::cnpjEhValido('1122233300018'));
        $this->assertFalse(Documento::cnpjEhValido(''));
    }
}
