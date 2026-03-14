<?php

declare(strict_types=1);

namespace App\Request;

class PessoaEnderecoStoreRequest extends AbstractFormRequest
{
    public function rules(): array
    {
        return [
            'cd_pessoa' => 'required|integer',
            'cd_pais' => 'nullable|integer',
            'cd_estado' => 'nullable|integer',
            'cd_cidade' => 'nullable|integer',
            'ds_cep' => 'nullable|string|max:10',
            'ds_bairro' => 'nullable|string|max:255',
            'ds_logradouro' => 'nullable|string|max:255',
            'ds_numero' => 'nullable|string|max:20',
            'ds_complemento' => 'nullable|string|max:255',
        ];
    }
}
