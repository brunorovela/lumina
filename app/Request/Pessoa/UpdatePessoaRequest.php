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

use Hyperf\Validation\Request\FormRequest;

class UpdatePessoaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ds_nome' => 'required|string|max:255',
            'ds_login' => 'required|string|max:100',
            'ds_senha' => 'nullable|string|min:6',
            'sn_pessoa_juridica' => 'required|boolean',
            'ds_nome_oficial' => 'required_if:sn_pessoa_juridica,false|string|max:255',
            'ds_cpf' => 'nullable|string',
            'ds_cnpj' => 'required_if:sn_pessoa_juridica,true|string',
            'ds_nome_fantasia' => 'required_if:sn_pessoa_juridica,true|string|max:255',
        ];
    }
}
