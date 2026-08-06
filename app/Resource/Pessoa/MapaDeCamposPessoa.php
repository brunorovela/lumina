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
     * MANUTENÇÃO: campo novo de pessoa física é NOVE edições, não três — nada aqui deriva
     * automaticamente do resto porque atributo PHP exige expressão constante (Swagger) e
     * porque mapa, regras de validação e schema do banco são coisas fisicamente separadas.
     * Esquecer CAMPOS_FISICA é o pior caso: falha muda (201 normal, campo simplesmente
     * nunca grava).
     *
     * 1. Este mapa (mapa()).
     * 2. App\Swagger\PessoaSchema (e PessoaResumidaSchema, se o campo entrar no default enxuto).
     * 3. A descrição do #[OA\Parameter(name: 'fields')] dos DOIS endpoints de leitura em
     *    App\Controller\Pessoa\PessoaController (listar e buscar).
     * 4. A lista de properties do requestBody nos TRÊS verbos de escrita em PessoaController
     *    (criar/atualizar/atualizarParcial).
     * 5. rules() nas TRÊS classes de request (Create/Update/PatchPessoaRequest).
     * 6. App\Service\Pessoa\PessoaService::CAMPOS_FISICA — sem entrar aqui, o campo valida,
     *    responde 201/200, e nunca é gravado (falha SILENCIOSA, não dá erro nenhum).
     * 7. App\Request\Pessoa\Concerns\NormalizaCamposDePessoa::CAMPOS_VAZIO_VIRA_NULO, se o
     *    campo novo tiver o mesmo contrato de "string vazia vira null" dos demais.
     * 8. App\Model\Pessoa\UnimPessoaFisica::$fillable/$casts.
     * 9. A lista de campos compartilhados em test/Cases/Request/Pessoa/CreatePessoaRequestTest.
     *
     * Depois de tudo isso, rodar gen:swagger (ver regra 1 do CLAUDE.md) para publicar em
     * storage/swagger/http.json.
     *
     * PII (sensivel: true) sai do default de GET /pessoas/{id} e só vem se pedida por nome
     * ou por curinga. Resposta de escrita traz sempre — ver PessoaResource.
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
            'fisica.ds_nome_social' => Campo::relacao('fisica', 'ds_nome_social', self::CHAVE_LOCAL),
            'fisica.ds_nome_mae' => Campo::relacao('fisica', 'ds_nome_mae', self::CHAVE_LOCAL, sensivel: true),
            'fisica.ds_nome_pai' => Campo::relacao('fisica', 'ds_nome_pai', self::CHAVE_LOCAL, sensivel: true),
            'fisica.ds_cpf' => Campo::relacao('fisica', 'ds_cpf', self::CHAVE_LOCAL, sensivel: true),
            'fisica.ds_identidade' => Campo::relacao('fisica', 'ds_identidade', self::CHAVE_LOCAL, sensivel: true),
            'fisica.ds_orgao_estado' => Campo::relacao('fisica', 'ds_orgao_estado', self::CHAVE_LOCAL),
            'fisica.ds_identidade_orgao_exp' => Campo::relacao('fisica', 'ds_identidade_orgao_exp', self::CHAVE_LOCAL),
            'fisica.dt_identidade_expedicao' => Campo::relacao('fisica', 'dt_identidade_expedicao', self::CHAVE_LOCAL),
            'fisica.dt_nascimento' => Campo::relacao('fisica', 'dt_nascimento', self::CHAVE_LOCAL, sensivel: true),
            'fisica.ds_sexo' => Campo::relacao('fisica', 'ds_sexo', self::CHAVE_LOCAL),
            'fisica.cd_estado_civil' => Campo::relacao('fisica', 'cd_estado_civil', self::CHAVE_LOCAL),
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
