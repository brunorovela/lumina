<?php

declare(strict_types=1);

namespace App\Request;

class PessoaFisicaUpdateRequest extends AbstractFormRequest
{
    public function rules(): array
    {
        return [
            'cd_estado_civil' => 'nullable|integer',
            'ds_nome_oficial' => 'nullable|string|max:255',
            'ds_nome_social' => 'nullable|string|max:255',
            'ds_cpf' => 'nullable|string|max:14',
            'dt_nascimento' => 'nullable|date',
            'ds_sexo' => 'nullable|string|max:1',
        ];
    }
}
