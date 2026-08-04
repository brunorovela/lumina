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
use App\Request\Pessoa\Concerns\ValidaDocumentosDePessoa;
use App\Support\Tipo;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\Validation\Request\FormRequest;

class PatchPessoaRequest extends FormRequest
{
    use NormalizaCamposDePessoa;
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
            'ds_nome' => 'sometimes|string|max:255',
            'ds_login' => 'sometimes|string|max:100',
            'ds_senha' => 'sometimes|string|min:6',
            'ds_nome_oficial' => 'sometimes|string|max:255',
            'ds_cpf' => 'sometimes|nullable|digits:11',
            'ds_cnpj' => 'sometimes|digits:14',
            'ds_nome_fantasia' => 'sometimes|string|max:255',
            'ds_nome_social' => 'sometimes|nullable|string|max:255',
            'ds_nome_mae' => 'sometimes|nullable|string|max:255',
            'ds_nome_pai' => 'sometimes|nullable|string|max:255',
            'ds_identidade' => 'sometimes|nullable|string|max:255',
            'ds_orgao_estado' => 'sometimes|nullable|string|max:255',
            'ds_identidade_orgao_exp' => 'sometimes|nullable|string|max:255',
            'dt_identidade_expedicao' => 'sometimes|nullable|date_format:Y-m-d|before_or_equal:today|after_or_equal:dt_nascimento',
            'dt_nascimento' => 'sometimes|nullable|date_format:Y-m-d|before_or_equal:today',
            'ds_sexo' => 'sometimes|nullable|in:f,m',
            'cd_estado_civil' => 'sometimes|nullable|integer|exists:saas_estado_civil,cd_estado_civil',
        ];
    }

    public function withValidator(ValidatorInterface $validator): void
    {
        $validator->after(function (ValidatorInterface $validator) {
            if (empty($this->all())) {
                $validator->errors()->add('payload', 'Envie ao menos um campo para atualizar.');
            }
        });

        $this->validarDocumentos($validator);
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
