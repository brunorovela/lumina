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
 *
 * ESCOPO: só colunas de unim_pessoa. Pessoa física (unim_pessoa_fisica) e pessoa jurídica
 * (unim_pessoa_juridica) NÃO são desta API — cada recurso responde pela própria tabela.
 * Antes o mapa tinha quatorze campos `fisica.*`/`juridica.*` e a leitura os trazia por
 * eager load; hoje /pessoas não lê nem escreve essas tabelas, e pedir `fields=fisica.ds_cpf`
 * responde 422 (campo fora do mapa) em vez de devolver dado de outro recurso.
 *
 * Nenhum campo aqui é `sensivel: true` hoje: a PII de pessoa (CPF, RG, filiação,
 * nascimento) mora em unim_pessoa_fisica, que saiu deste mapa. O mecanismo de sensível
 * continua em Campo/SelecaoDeCampos, coberto por HyperfTest\Cases\Support\Campos, para
 * quem for expor essas colunas na API própria delas.
 */
final class MapaDeCamposPessoa
{
    public const CHAVE_LOCAL = 'cd_pessoa';

    /**
     * MANUTENÇÃO: coluna nova de unim_pessoa exposta na API é SEIS edições, não uma — nada
     * aqui deriva automaticamente do resto porque atributo PHP exige expressão constante
     * (Swagger) e porque mapa, regras de validação e schema do banco são coisas fisicamente
     * separadas. Esquecer PessoaService::CAMPOS_PESSOA é o pior caso: falha muda (201
     * normal, campo simplesmente nunca grava).
     *
     * 1. Este mapa (mapa()).
     * 2. App\Swagger\PessoaSchema (e PessoaResumidaSchema, se o campo entrar no default enxuto).
     * 3. A descrição do #[OA\Parameter(name: 'fields')] dos DOIS endpoints de leitura em
     *    App\Controller\Pessoa\PessoaController (listar e buscar).
     * 4. A lista de properties do requestBody nos TRÊS verbos de escrita em PessoaController
     *    (criar/atualizar/atualizarParcial), se o campo também for escrito.
     * 5. rules() nas TRÊS classes de request (Create/Update/PatchPessoaRequest) — é dali que
     *    sai a lista de campos aceitos, então campo fora de rules() responde 422.
     * 6. App\Model\Pessoa\UnimPessoa::$fillable/$casts e App\Service\Pessoa\PessoaService::CAMPOS_PESSOA.
     *
     * Depois de tudo isso, rodar gen:swagger (ver regra 1 do CLAUDE.md) para publicar em
     * storage/swagger/http.json.
     *
     * Campo marcado `sensivel: true` sai do default de GET /pessoas/{id} e só vem se pedido
     * por nome ou por curinga; resposta de escrita traz sempre (ver PessoaResource). Hoje
     * nenhum campo de unim_pessoa é sensível.
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
        ];
    }

    /**
     * Todas as colunas expostas, em ordem de mapa. É o conjunto que o cache de
     * GET /pessoas/{id} guarda: o cache é por ENTIDADE (uma chave por pessoa), e o recorte
     * do ?fields= roda depois, sobre o dado cacheado — por isso a leitura de banco do
     * detalhe traz sempre estas colunas, e não só as pedidas.
     *
     * @return string[]
     */
    public static function colunas(): array
    {
        return array_map(static fn (Campo $campo): string => $campo->coluna, array_values(self::mapa()));
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
