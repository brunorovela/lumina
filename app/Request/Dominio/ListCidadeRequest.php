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

namespace App\Request\Dominio;

use Hyperf\Validation\Request\FormRequest;

/**
 * cd_estado é obrigatório e isso não é rigor gratuito: saas_cidade tem 4928 linhas e a
 * rota não pagina. Sem o filtro, uma chamada devolveria o catálogo inteiro.
 */
class ListCidadeRequest extends FormRequest
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
            'cd_estado' => 'required|integer|min:1',
            'q' => 'sometimes|string|min:1|max:255',
        ];
    }
}
