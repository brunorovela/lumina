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

use App\Support\Tipo;
use DateTimeImmutable;
use Hyperf\Contract\ValidatorInterface;

use function Hyperf\Translation\trans;

/**
 * dt_identidade_expedicao não pode ser anterior a dt_nascimento — comparação entre dois
 * campos do MESMO payload, então mora no after() do validador, mesmo padrão de
 * ValidaDocumentosDePessoa, não na string de regras.
 *
 * ANTES a regra vivia como `after_or_equal:dt_nascimento` na string de rules(). Isso
 * quebra sempre que só um dos dois campos vem no payload: sem dt_nascimento presente no
 * dado validado, o comparador de data do Laravel/Hyperf cai no valor LITERAL da string
 * "dt_nascimento" (o próprio nome do campo, não o valor dele), que não é uma data — e a
 * comparação reprova por não conseguir fazer parse. Um PATCH que só manda
 * dt_identidade_expedicao, ou um POST de alguém com data de expedição conhecida e data de
 * nascimento desconhecida, tomava 422 mesmo sem nenhuma das duas datas estar de fato
 * invertida (Critical da revisão da Task 9). Comparar só faz sentido quando as DUAS datas
 * vêm no mesmo payload; fora disso não há o que comparar, e o campo ausente já é assunto
 * de `nullable`.
 */
trait ValidaDatasDePessoa
{
    protected function validarDatas(ValidatorInterface $validator): void
    {
        $validator->after(function (ValidatorInterface $validator): void {
            $dtNascimento = $this->input('dt_nascimento');
            $dtExpedicao = $this->input('dt_identidade_expedicao');

            if (! is_string($dtNascimento) || ! is_string($dtExpedicao)) {
                return;
            }

            $nascimento = self::dataOuNulo($dtNascimento);
            $expedicao = self::dataOuNulo($dtExpedicao);

            if ($nascimento === null || $expedicao === null) {
                return;
            }

            if ($expedicao < $nascimento) {
                // trans() declara array|string -- Tipo::texto() é a coerção explícita do
                // projeto, mesmo padrão de ValidaDocumentosDePessoa. :date reaproveita a
                // chave padrão 'after_or_equal' do Laravel com o nome do OUTRO campo já
                // "humanizado" (espaço no lugar de underscore), reproduzindo a mesma frase
                // que a regra de string produzia quando as duas datas vinham no payload.
                $validator->errors()->add('dt_identidade_expedicao', Tipo::texto(trans('validation.after_or_equal', [
                    'attribute' => str_replace('_', ' ', 'dt_identidade_expedicao'),
                    'date' => str_replace('_', ' ', 'dt_nascimento'),
                ])));
            }
        });
    }

    /**
     * '!Y-m-d' (com o '!' inicial) zera hora/minuto/segundo em vez de herdar o instante
     * "agora": sem isso, duas datas de calendário IGUAIS podiam comparar como diferentes
     * por causa do microssegundo em que cada uma foi parseada. O round-trip
     * format()===valor original rejeita data que o PHP aceitaria de forma leniente mas não
     * existe (ex.: "2021-02-30" viraria 2021-03-02 sem essa checagem).
     */
    private static function dataOuNulo(string $valor): ?DateTimeImmutable
    {
        $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        if ($data === false || $data->format('Y-m-d') !== $valor) {
            return null;
        }

        return $data;
    }
}
