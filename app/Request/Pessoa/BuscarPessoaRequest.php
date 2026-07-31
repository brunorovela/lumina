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

/**
 * GET /pessoas/{id} não tinha FormRequest: nada havia para validar. Passou a ter com o
 * ?fields=, e sem isto o parâmetro seria silenciosamente ignorado no detalhe enquanto
 * funciona na lista — divergência que gera bug de cliente.
 */
class BuscarPessoaRequest extends FormRequest
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
            'fields' => 'sometimes|string',
        ];
    }

    public function withValidator(ValidatorInterface $validator): void
    {
        $this->validarCampos($validator);
    }
}
