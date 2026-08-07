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

namespace App\Request\Concerns;

use App\Support\Tipo;
use Hyperf\Contract\ValidatorInterface;

/**
 * Reprova com 422 qualquer campo que não esteja em rules().
 *
 * Por que isto existe: o validador, sozinho, IGNORA campo desconhecido — validated() só
 * devolve o que as regras citam. Enquanto /pessoas escrevia unim_pessoa_fisica, um payload
 * com ds_cpf gravava CPF; depois do desacoplamento (cada recurso responde pela própria
 * tabela) o mesmo payload responderia 200/201 e simplesmente não gravaria o CPF — falha
 * silenciosa, exatamente o modo de falha que este projeto combate. Com esta checagem o
 * cliente antigo quebra alto, com o nome do campo no corpo do 422.
 *
 * A lista de permitidos vem de rules(), então nada precisa ser mantido em dobro: campo novo
 * que entra nas regras passa a ser aceito automaticamente.
 */
trait RejeitaCamposDesconhecidos
{
    protected function rejeitarCamposDesconhecidos(ValidatorInterface $validator): void
    {
        $validator->after(function (ValidatorInterface $validator) {
            $permitidos = array_keys($this->rules());

            // post() e não all(): all() junta a query string ao corpo, e query string em
            // verbo de escrita não é campo do payload — `POST /pessoas?fields=ds_nome` é
            // legítimo (fields é ignorado na escrita, e documentado assim) e não pode
            // responder 422 por "campo desconhecido".
            foreach (array_keys(Tipo::mapa($this->post())) as $campo) {
                if (in_array($campo, $permitidos, true)) {
                    continue;
                }

                $validator->errors()->add(
                    $campo,
                    "Campo não pertence a este recurso: {$campo}. Cada recurso responde pelos próprios dados — "
                    . 'dados de pessoa física (unim_pessoa_fisica) e de pessoa jurídica (unim_pessoa_juridica) não '
                    . 'são gravados por /pessoas.'
                );
            }
        });
    }
}
