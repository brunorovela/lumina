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

use App\Request\Pessoa\Concerns\ValidaCamposDePessoa;
use Hyperf\Contract\ValidatorInterface;
use Hyperf\Validation\Request\FormRequest;

class ListPessoaRequest extends FormRequest
{
    use ValidaCamposDePessoa;

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
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1',
            'nome' => 'sometimes|string',
            'tipo_pessoa' => 'sometimes|in:fisica,juridica',
            'fields' => 'sometimes|string',
        ];
    }

    public function withValidator(ValidatorInterface $validator): void
    {
        $this->validarCampos($validator);
    }
}
