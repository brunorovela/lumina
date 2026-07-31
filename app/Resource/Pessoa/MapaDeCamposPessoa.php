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

namespace App\Resource\Pessoa;

use App\Support\Campos\Campo;
use App\Support\Campos\SelecaoDeCampos;

/**
 * Fonte de verdade do schema exposto de pessoa. Um arquivo responde três perguntas: o que
 * a API expõe, para onde cada campo aponta no banco, e o que entra no default enxuto da
 * listagem (noPadrao: true).
 *
 * ds_senha NÃO está aqui, e é por isso que não existe blacklist a manter: o que não está
 * no mapa é inalcançável por construção.
 */
final class MapaDeCamposPessoa
{
    public const CHAVE_LOCAL = 'cd_pessoa';

    /**
     * MANUTENÇÃO: campo novo aqui também precisa entrar na descrição do #[OA\Parameter(name: 'fields')]
     * dos DOIS endpoints de leitura em App\Controller\Pessoa\PessoaController (listar e buscar) —
     * atributo PHP exige expressão constante, então a lista de campos do Swagger não pode ser
     * derivada deste mapa e nada força as duas a acompanharem.
     *
     * @return array<string, Campo>
     */
    public static function mapa(): array
    {
        return [
            'cd_pessoa' => Campo::coluna('cd_pessoa', noPadrao: true),
            'cd_cliente' => Campo::coluna('cd_cliente'),
            'ds_nome' => Campo::coluna('ds_nome', noPadrao: true),
            'ds_login' => Campo::coluna('ds_login', noPadrao: true),
            'sn_pessoa_juridica' => Campo::coluna('sn_pessoa_juridica', noPadrao: true),
            'fisica.ds_nome_oficial' => Campo::relacao('fisica', 'ds_nome_oficial', self::CHAVE_LOCAL),
            'fisica.ds_cpf' => Campo::relacao('fisica', 'ds_cpf', self::CHAVE_LOCAL),
            'juridica.ds_cnpj' => Campo::relacao('juridica', 'ds_cnpj', self::CHAVE_LOCAL),
            'juridica.ds_nome_fantasia' => Campo::relacao('juridica', 'ds_nome_fantasia', self::CHAVE_LOCAL),
        ];
    }

    public static function selecao(?string $fields, bool $padraoEhTudo = false): SelecaoDeCampos
    {
        return SelecaoDeCampos::de($fields, self::mapa(), self::CHAVE_LOCAL, $padraoEhTudo);
    }

    /**
     * @return string[]
     */
    public static function invalidos(?string $fields): array
    {
        return SelecaoDeCampos::invalidos($fields, self::mapa());
    }
}
