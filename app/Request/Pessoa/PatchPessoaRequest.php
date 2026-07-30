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

use Hyperf\Contract\ValidatorInterface;
use Hyperf\Validation\Request\FormRequest;

class PatchPessoaRequest extends FormRequest
{
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
            'ds_cpf' => 'sometimes|nullable|string',
            'ds_cnpj' => 'sometimes|string',
            'ds_nome_fantasia' => 'sometimes|string|max:255',
        ];
    }

    public function withValidator(ValidatorInterface $validator): void
    {
        $validator->after(function (ValidatorInterface $validator) {
            if (empty($this->all())) {
                $validator->errors()->add('payload', 'Envie ao menos um campo para atualizar.');
            }
        });
    }
}
