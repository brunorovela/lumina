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

namespace App\Request\Pessoa\Concerns;

use App\Resource\Pessoa\MapaDeCamposPessoa;
use Hyperf\Contract\ValidatorInterface;

/**
 * Checagem de `fields` compartilhada entre ListPessoaRequest e BuscarPessoaRequest — a
 * regra é a mesma nos dois, e duplicá-la deixaria os endpoints divergirem com o tempo.
 */
trait ValidaCamposDePessoa
{
    protected function validarCampos(ValidatorInterface $validator): void
    {
        $validator->after(function (ValidatorInterface $validator) {
            $fields = $this->input('fields');

            if ($fields !== null && ! is_string($fields)) {
                $validator->errors()->add(
                    'fields',
                    'O parâmetro fields precisa ser uma lista de campos separada por vírgula.'
                );

                return;
            }

            foreach (MapaDeCamposPessoa::invalidos($fields) as $campo) {
                $validator->errors()->add('fields', "Campo não permitido: {$campo}.");
            }
        });
    }
}
