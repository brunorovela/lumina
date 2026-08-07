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
use App\Request\Pessoa\PatchPessoaRequest;
use App\Request\Pessoa\UpdatePessoaRequest;
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

    public function testPassaComOsQuatroCamposDePessoa()
    {
        $factory = $this->getContainer()->get(ValidatorFactoryInterface::class);
        $request = new CreatePessoaRequest($this->getContainer());

        $validator = $factory->make([
            'ds_nome' => 'Fulano de Teste',
            'ds_login' => 'fulano.teste',
            'ds_senha' => '123456',
            'sn_pessoa_juridica' => false,
        ], $request->rules());

        $this->assertFalse($validator->fails());
    }

    /**
     * As três classes de escrita aceitam EXATAMENTE as colunas de unim_pessoa expostas — a
     * lista de rules() é o que a checagem de campo desconhecido usa como permitidos, então
     * um campo de outro recurso que voltasse para cá deixaria de responder 422 sem que
     * nenhum outro teste percebesse.
     */
    public function testAsTresClassesAceitamSomenteColunasDePessoa()
    {
        $esperado = ['ds_nome', 'ds_login', 'ds_senha', 'sn_pessoa_juridica'];

        $this->assertEqualsCanonicalizing($esperado, array_keys((new CreatePessoaRequest($this->getContainer()))->rules()));
        $this->assertEqualsCanonicalizing($esperado, array_keys((new UpdatePessoaRequest($this->getContainer()))->rules()));
        $this->assertEqualsCanonicalizing($esperado, array_keys((new PatchPessoaRequest($this->getContainer()))->rules()));
    }

    /**
     * Regra de FORMATO (tipo, tamanho, domínio) não pode divergir entre os verbos: foi
     * divergência assim que deixou ds_cnpj entrar em pessoa física na versão anterior desta
     * API. Os tokens de presença (required/nullable/sometimes/required_if) variam por verbo
     * de propósito — POST exige o que PATCH não precisa reenviar — e por isso são os únicos
     * ignorados na comparação.
     */
    public function testAsTresClassesTemAMesmaRegraDeFormatoParaOsCamposCompartilhados()
    {
        $regrasCreate = (new CreatePessoaRequest($this->getContainer()))->rules();
        $regrasUpdate = (new UpdatePessoaRequest($this->getContainer()))->rules();
        $regrasPatch = (new PatchPessoaRequest($this->getContainer()))->rules();

        foreach (['ds_nome', 'ds_login', 'ds_senha', 'sn_pessoa_juridica'] as $campo) {
            $create = $this->somenteFormato((string) $regrasCreate[$campo]);
            $update = $this->somenteFormato((string) $regrasUpdate[$campo]);
            $patch = $this->somenteFormato((string) $regrasPatch[$campo]);

            $this->assertSame($create, $update, "Campo '{$campo}' diverge entre Create e Update.");
            $this->assertSame($create, $patch, "Campo '{$campo}' diverge entre Create e Patch.");
        }
    }

    /**
     * Remove só os tokens de presença, que legitimamente variam por verbo, preservando a
     * ordem dos demais.
     */
    private function somenteFormato(string $regra): string
    {
        $presenca = ['required', 'nullable', 'sometimes'];

        $tokens = array_filter(
            explode('|', $regra),
            static fn (string $token): bool => ! in_array($token, $presenca, true) && ! str_starts_with($token, 'required_if:')
        );

        return implode('|', $tokens);
    }
}
