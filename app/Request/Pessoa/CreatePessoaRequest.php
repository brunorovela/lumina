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
 * POST /pessoas escreve unim_pessoa e só. Os quatorze campos de pessoa física/jurídica que
 * este request aceitava (ds_nome_oficial, ds_cpf, ds_cnpj, ds_nome_fantasia e os dez campos
 * de física) saíram: quem grava aquelas tabelas é o recurso delas. Enviar um deles agora
 * responde 422 — ver RejeitaCamposDesconhecidos, e o porquê de não ser descarte silencioso.
 */
class CreatePessoaRequest extends FormRequest
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
            'ds_senha' => 'required|string|min:6',
            'sn_pessoa_juridica' => 'required|boolean',
        ];
    }

    public function withValidator(ValidatorInterface $validator): void
    {
        $this->rejeitarCamposDesconhecidos($validator);
    }
}
