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

    /**
     * Important 2 + Important 4 da revisão da Task 7: ds_cpf e ds_cnpj, mais os dez
     * campos novos de física, não podem ter uma regra de FORMATO diferente entre
     * Create/Update/Patch -- divergência aqui é exatamente como ds_cnpj entrou numa
     * pessoa física no Finding 14 (whole-branch review). "required"/"required_if"/
     * "sometimes" divergem de propósito por verbo (POST exige o que PUT/PATCH não
     * precisam reenviar); só esses prefixos são ignorados na comparação, o resto da
     * regra (tipo, tamanho, domínio, exists) tem de bater igual nas três classes.
     */
    public function testAsTresClassesTemAMesmaRegraDeFormatoParaOsCamposCompartilhados()
    {
        $camposCompartilhados = [
            'ds_cpf', 'ds_cnpj', 'ds_nome_social', 'ds_nome_mae', 'ds_nome_pai',
            'ds_identidade', 'ds_orgao_estado', 'ds_identidade_orgao_exp',
            'dt_identidade_expedicao', 'dt_nascimento', 'ds_sexo', 'cd_estado_civil',
        ];

        $regrasCreate = (new CreatePessoaRequest($this->getContainer()))->rules();
        $regrasUpdate = (new UpdatePessoaRequest($this->getContainer()))->rules();
        $regrasPatch = (new PatchPessoaRequest($this->getContainer()))->rules();

        foreach ($camposCompartilhados as $campo) {
            $create = $this->semPrefixosDeVerbo((string) $regrasCreate[$campo]);
            $update = $this->semPrefixosDeVerbo((string) $regrasUpdate[$campo]);
            $patch = $this->semPrefixosDeVerbo((string) $regrasPatch[$campo]);

            $this->assertSame($create, $update, "Campo '{$campo}' diverge entre Create e Update.");
            $this->assertSame($create, $patch, "Campo '{$campo}' diverge entre Create e Patch.");
        }
    }

    /**
     * Remove só os tokens que legitimamente variam por verbo ("sometimes" do PATCH,
     * "required_if:..." do POST/PUT), preservando a ordem dos demais.
     */
    private function semPrefixosDeVerbo(string $regra): string
    {
        $tokens = array_filter(
            explode('|', $regra),
            static fn (string $token): bool => $token !== 'sometimes' && ! str_starts_with($token, 'required_if:')
        );

        return implode('|', $tokens);
    }
}
