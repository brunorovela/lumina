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

namespace App\Request\Pessoa;

use App\Request\Pessoa\Concerns\NormalizaCamposDePessoa;
use App\Request\Pessoa\Concerns\ValidaDatasDePessoa;
use App\Request\Pessoa\Concerns\ValidaDocumentosDePessoa;
use App\Support\Tipo;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\Validation\Request\FormRequest;

class UpdatePessoaRequest extends FormRequest
{
    use NormalizaCamposDePessoa;
    use ValidaDatasDePessoa;
    use ValidaDocumentosDePessoa;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ds_nome' => 'required|string|max:255',
            'ds_login' => 'required|string|max:100',
            'ds_senha' => 'nullable|string|min:6',
            'sn_pessoa_juridica' => 'required|boolean',
            'ds_nome_oficial' => 'required_if:sn_pessoa_juridica,false|string|max:255',
            'ds_cpf' => 'nullable|regex:/^\d{11}$/',
            'ds_cnpj' => 'required_if:sn_pessoa_juridica,true|regex:/^\d{14}$/',
            'ds_nome_fantasia' => 'required_if:sn_pessoa_juridica,true|string|max:255',
            'ds_nome_social' => 'nullable|string|max:255',
            'ds_nome_mae' => 'nullable|string|max:255',
            'ds_nome_pai' => 'nullable|string|max:255',
            'ds_identidade' => 'nullable|string|max:255',
            'ds_orgao_estado' => 'nullable|string|max:255',
            'ds_identidade_orgao_exp' => 'nullable|string|max:255',
            'dt_identidade_expedicao' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'dt_nascimento' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'ds_sexo' => 'nullable|in:f,m',
            'cd_estado_civil' => 'nullable|integer|exists:saas_estado_civil,cd_estado_civil',
        ];
    }

    public function withValidator(ValidatorInterface $validator): void
    {
        $this->validarDocumentos($validator);
        $this->validarDatas($validator);
    }

    /**
     * `regex` (Critical 1 da revisão final) não tem mensagem própria como `digits` tinha —
     * cairia no genérico "The :attribute format is invalid.". Mensagem explícita preserva a
     * frase que o cliente da API já lia antes da troca de regra.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ds_cpf.regex' => 'The ds cpf must be 11 digits.',
            'ds_cnpj.regex' => 'The ds cnpj must be 14 digits.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function validationData(): array
    {
        // parent::validationData() declara array (não array<string, mixed>): sem a
        // normalização de Tipo::mapa(), o PHPStan nível 10 recusa o argumento.
        return $this->normalizarCamposDePessoa(Tipo::mapa(parent::validationData()));
    }
}
