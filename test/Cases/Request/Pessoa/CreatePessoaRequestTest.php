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

namespace HyperfTest\Cases\Request\Pessoa;

use App\Request\Pessoa\CreatePessoaRequest;
use Hyperf\Testing\TestCase;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;

/**
 * @internal
 * @coversNothing
 */
class CreatePessoaRequestTest extends TestCase
{
    public function testFalhaSemCamposObrigatorios()
    {
        $factory = $this->getContainer()->get(ValidatorFactoryInterface::class);
        $request = new CreatePessoaRequest($this->getContainer());

        $validator = $factory->make([], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('ds_nome', $validator->errors()->toArray());
        $this->assertArrayHasKey('ds_login', $validator->errors()->toArray());
        $this->assertArrayHasKey('ds_senha', $validator->errors()->toArray());
        $this->assertArrayHasKey('sn_pessoa_juridica', $validator->errors()->toArray());
    }

    public function testPassaComCamposMinimosDePessoaFisica()
    {
        $factory = $this->getContainer()->get(ValidatorFactoryInterface::class);
        $request = new CreatePessoaRequest($this->getContainer());

        $validator = $factory->make([
            'ds_nome' => 'Fulano de Teste',
            'ds_login' => 'fulano.teste',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
            'ds_nome_oficial' => 'Fulano de Teste Oficial',
        ], $request->rules());

        $this->assertFalse($validator->fails());
    }
}
