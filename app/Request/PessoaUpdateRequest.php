<?php

declare(strict_types=1);

namespace App\Request;

class PessoaUpdateRequest extends AbstractFormRequest
{
    public function rules(): array
    {
        return [
            'cd_cliente' => 'sometimes|integer',
            'ds_nome' => 'sometimes|string|max:255',
            'ds_login' => 'sometimes|string|max:100',
            'ds_senha' => 'sometimes|string|min:6',
            'sn_pessoa_juridica' => 'sometimes|integer|in:0,1',
            'me_qualificacao' => 'nullable|string',
            'cd_imagem' => 'nullable|integer',
            'ds_seguimento' => 'nullable|string|max:255',
            'ds_marca' => 'nullable|string|max:255',
            'ds_unidade' => 'nullable|string|max:255',
            'ds_turma' => 'nullable|string|max:255',
            'dt_cadastro' => 'nullable|date',
        ];
    }
}
