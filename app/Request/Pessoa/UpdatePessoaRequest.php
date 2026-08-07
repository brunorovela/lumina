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

use App\Request\Concerns\RejeitaCamposDesconhecidos;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\Validation\Request\FormRequest;

/**
 * PUT /pessoas/{id} escreve unim_pessoa e só — ver CreatePessoaRequest. Campo de pessoa
 * física/jurídica no payload responde 422.
 *
 * Efeito colateral do desacoplamento que vale registrar: trocar sn_pessoa_juridica aqui não
 * apaga mais a linha do tipo antigo (unim_pessoa_fisica/unim_pessoa_juridica). Antes um PUT
 * destruía CPF sem confirmação; agora o dado do outro recurso fica onde está, e só o
 * recurso dele pode removê-lo.
 */
class UpdatePessoaRequest extends FormRequest
{
    use RejeitaCamposDesconhecidos;

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
        ];
    }

    public function withValidator(ValidatorInterface $validator): void
    {
        $this->rejeitarCamposDesconhecidos($validator);
    }
}
