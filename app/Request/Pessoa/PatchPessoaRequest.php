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
 * PATCH /pessoas/{id} escreve unim_pessoa e só — ver CreatePessoaRequest. Campo de pessoa
 * física/jurídica no payload responde 422, e não é mais gravado em silêncio.
 */
class PatchPessoaRequest extends FormRequest
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
            'ds_nome' => 'sometimes|string|max:255',
            'ds_login' => 'sometimes|string|max:100',
            'ds_senha' => 'sometimes|string|min:6',
            'sn_pessoa_juridica' => 'sometimes|boolean',
        ];
    }

    public function withValidator(ValidatorInterface $validator): void
    {
        $validator->after(function (ValidatorInterface $validator) {
            if (empty($this->all())) {
                $validator->errors()->add('payload', 'Envie ao menos um campo para atualizar.');
            }
        });

        $this->rejeitarCamposDesconhecidos($validator);
    }
}
