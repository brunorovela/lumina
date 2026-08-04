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

use App\Support\Documento;
use App\Support\Tipo;
use Hyperf\Contract\ValidatorInterface;

use function Hyperf\Translation\trans;

/**
 * Dígito verificador de CPF/CNPJ no after() do validador, mesmo padrão de
 * ValidaCamposDePessoa.
 *
 * Roda depois de digits:11/digits:14 nas regras -- essas rodam sobre validationData()
 * (já sem máscara). Mas $this->input() dentro do after() lê o dado CRU da requisição, não
 * o normalizado: Hyperf\HttpServer\Request::input() não passa por validationData(). Por
 * isso este trait roda Documento::apenasDigitos() de novo aqui -- é idempotente sobre um
 * valor que já veio só com dígitos, e é o que realmente limpa a máscara quando ela chega
 * intacta neste ponto.
 *
 * Guarda com is_scalar(), não is_string(): Hyperf\Validation\Concerns\ValidatesAttributes::
 * validateDigits() faz `(string) $value` antes de medir, então um inteiro sem aspas no
 * JSON ("ds_cpf": 12345678900) passa em digits:11 sem nunca virar string. Com a guarda em
 * is_string(), esse inteiro pulava a checagem de dígito verificador inteira -- 201 com CPF
 * inválido gravado (Critical 1 da revisão da Task 7). Tipo::texto() converte int/float/bool
 * pra string sem lançar, então aceitar qualquer escalar aqui é seguro; só array/objeto sem
 * __toString lançaria, e esses nunca chegam como valor de um campo de formulário.
 *
 * Só reporta quando o campo tem algum dígito: campo ausente ou só-máscara (que
 * Documento::apenasDigitos() esvazia) é assunto de `nullable`/`required_if`, não daqui.
 */
trait ValidaDocumentosDePessoa
{
    protected function validarDocumentos(ValidatorInterface $validator): void
    {
        $validator->after(function (ValidatorInterface $validator): void {
            $cpf = $this->input('ds_cpf');

            if (is_scalar($cpf) && ($cpfSoDigitos = Documento::apenasDigitos(Tipo::texto($cpf))) !== '') {
                if (! Documento::cpfEhValido($cpfSoDigitos)) {
                    // trans() declara array|string (chaves de mensagem em lote viram array);
                    // as duas chaves daqui são sempre string, mas o PHPStan não sabe disso
                    // pela assinatura -- Tipo::texto() é a coerção explícita do projeto.
                    $validator->errors()->add('ds_cpf', Tipo::texto(trans('validation.cpf_invalido', [
                        'attribute' => str_replace('_', ' ', 'ds_cpf'),
                    ])));
                }
            }

            $cnpj = $this->input('ds_cnpj');

            if (is_scalar($cnpj) && ($cnpjSoDigitos = Documento::apenasDigitos(Tipo::texto($cnpj))) !== '') {
                if (! Documento::cnpjEhValido($cnpjSoDigitos)) {
                    $validator->errors()->add('ds_cnpj', Tipo::texto(trans('validation.cnpj_invalido', [
                        'attribute' => str_replace('_', ' ', 'ds_cnpj'),
                    ])));
                }
            }
        });
    }
}
