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

namespace App\Request;

use Hyperf\Validation\Request\FormRequest;

class PessoaStoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'cd_cliente' => 'required|integer',
            'ds_nome' => 'required|string|max:255',
            'ds_login' => 'required|string|max:255|unique:unim_pessoa,ds_login,NULL,cd_pessoa,cd_cliente,' . $this->input('cd_cliente'),
            'ds_senha' => 'required|string|min:6',
        ];
    }

    public function messages(): array
    {
        return [
            'ds_login.unique' => 'O login informado já está em uso para este cliente.',
            'cd_cliente.required' => 'O campo cliente é obrigatório.',
            'ds_senha.min' => 'A senha deve ter no mínimo 6 caracteres.',
        ];
    }
}
