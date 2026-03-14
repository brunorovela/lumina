<?php

declare(strict_types=1);

namespace App\Request;

use Hyperf\Validation\Request\FormRequest;

/**
 * Base para Form Requests reutilizável.
 * Garante que o body JSON seja usado como fonte de dados para validação.
 */
abstract class AbstractFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Dados para validação: body JSON (quando aplicável) + query/attributes.
     * No Hyperf, o body JSON nem sempre entra em all(), então usamos getParsedBody().
     */
    protected function validationData(): array
    {
        $parsed = method_exists($this, 'getParsedBody') ? $this->getParsedBody() : null;
        if (is_array($parsed) && $parsed !== []) {
            return array_merge($this->all(), $parsed);
        }
        return $this->all();
    }
}
