# Seleção de campos na API de pessoas (sparse fieldsets)

Data: 2026-07-30

## Problema

`GET /pessoas` devolve sempre a mesma forma fixa, montada por `PessoaResource::um()`:

```
cd_pessoa, cd_cliente, ds_nome, ds_login, sn_pessoa_juridica,
fisica{ds_nome_oficial, ds_cpf}, juridica{ds_cnpj, ds_nome_fantasia}
```

Três custos, todos apontados como preocupantes:

1. **Rede/parse** — o cliente recebe campo que não usa, multiplicado pelas linhas da página.
2. **Banco** — `PessoaRepository::listar()` faz `with(['fisica', 'juridica'])` sempre, ou seja **3 queries por página** (1 da lista + 2 de eager load), mesmo quando só `ds_nome` interessa.
3. **Crescimento** — com 9 valores hoje o incômodo é pequeno; quando entrarem contatos, endereços e matrículas, o default vira o endpoint mais caro da API.

Filtrar só na saída resolveria (1) e nada de (2): o banco já teria feito todo o trabalho. Por isso a seleção precisa chegar ao SQL.

## Situação atual

- Nenhuma seleção de campos existe em `GET /pessoas`, que aceita apenas `page`, `per_page`, `nome`, `tipo_pessoa`.
- **O LMS atual não tem esse padrão em nenhum módulo** (busca por `fields`/`ds_campos` em `module/*`): não há convenção interna para espelhar, ao contrário do que foi feito com as chaves de ACL.
- A rota nasceu em 2026-07-25 (commit `ab6a416`) e não foi encontrado consumidor — busca limitada aos módulos do LMS e aos front-ends por `9501`/`lumina`, portanto ausência de prova, não prova de ausência. O `lumina-docs` publica o Swagger, o que documenta o contrato mas não o consome.

A idade da rota é o que torna barato mudar o comportamento padrão. Essa janela fecha quando o primeiro consumidor entrar.

## Decisões

| # | Decisão | Alternativa recusada e por quê |
|---|---|---|
| 1 | Um parâmetro `fields`, lista por vírgula, ponto para relação | `fields[x]=`+`include=` (JSON:API) permite pedido incoerente — `fields` de relação não incluída — e dobra a superfície. Presets `?view=` não são flexíveis: campo novo vira deploy. Resposta chapada quebraria o formato atual e ficaria ambígua com coluna homônima entre tabelas |
| 2 | Default da **lista** é enxuto; `fields=*` traz tudo | Manter tudo como default deixa (1) e (3) de pé para quem não optar, e otimização que depende do cliente lembrar de pedir não acontece |
| 3 | Default do **item** é completo; ambos GETs aceitam `fields` | Mínimo em todo lugar obrigaria tela de cadastro a pedir `fields=*`. O custo é por página, não por item — economizar 4 campos de uma pessoa não paga a surpresa de buscar por id e receber registro incompleto |
| 4 | Escrita (`POST`/`PUT`/`PATCH`) ignora `fields`, sempre completo | Resposta de escrita filtrada esconde o que o servidor gravou (default aplicado, id gerado) |
| 5 | Campo inválido → **422** listando os rejeitados | Ignorar em silêncio transforma typo em ausência inexplicada. Diferenciar "não permitido" de "inexistente" viraria oráculo de schema: confirmaria que `ds_senha` é coluna real |
| 6 | Mapa declarativo único como fonte de verdade | Objeto de valor sem mapa espalha o conhecimento "campo público → coluna/relação" entre Repository e Resource: campo novo edita dois arquivos e nada garante coerência |

Decisões menores tomadas sem consulta, registradas para poderem ser contestadas:

- `?fields=` **vazio** é tratado como ausente (cai no default). String vazia em query string é acidente comum de cliente e o default é inofensivo; 422 aqui seria hostil.
- **A resposta respeita `fields` ao pé da letra.** `cd_pessoa` (no pai) e a FK `cd_pessoa` (na relação) entram no `SELECT` quando há relação pedida, porque sem eles o Eloquent não casa filho com pai — mas são **removidos da saída** se não foram pedidos. São detalhe de execução, não parte do contrato. Alternativa recusada: devolvê-los junto, o que faria o cliente receber campo que não pediu e tornaria a resposta dependente de detalhe interno do ORM.
- Espaços em volta dos tokens são aparados (`fields=ds_nome, ds_login` funciona) e tokens repetidos são deduplicados em silêncio. Exigir formatação exata aqui geraria 422 por acidente de montagem de URL.
- `*` presente em qualquer posição vence tudo: `fields=ds_nome,*` equivale a `fields=*`.

## Contrato

| Requisição | Resposta |
|---|---|
| `GET /pessoas` | `cd_pessoa`, `ds_nome`, `ds_login`, `sn_pessoa_juridica`. Sem `fisica`/`juridica` |
| `GET /pessoas?fields=*` | Contrato completo atual |
| `GET /pessoas?fields=ds_nome,fisica.ds_cpf` | `{"ds_nome":"Ana","fisica":{"ds_cpf":"..."}}` |
| `GET /pessoas?fields=fisica.*` | `fisica` inteira |
| `GET /pessoas/{id}` | Completo |
| `GET /pessoas/{id}?fields=ds_nome` | `{"ds_nome":"Ana"}` |
| `POST`/`PUT`/`PATCH /pessoas` | Completo; `fields` ignorado |

O aninhamento atual é preservado: `fisica`/`juridica` seguem objetos, nunca chapados na raiz.

**Forma estável:** a chave existe sempre que foi pedida; o valor pode ser nulo. Pedir `fisica.ds_cpf` de uma pessoa jurídica devolve `"fisica": null` — igual ao comportamento de hoje.

Campo inválido, no envelope que a API já usa:

```json
{
  "success": false,
  "message": "Dados inválidos.",
  "errors": {
    "fields": [
      "Campo não permitido: ds_nomee.",
      "Campo não permitido: ds_senha."
    ]
  }
}
```

`ds_senha` e um typo recebem a **mesma** mensagem: a resposta não confirma quais colunas existem.

## Arquitetura

### Componentes

| Componente | Responsabilidade | Depende de |
|---|---|---|
| `App\Support\Campos\Campo` | Objeto de valor imutável: coluna direta, ou trio (relação, coluna, chave estrangeira), mais a flag `noPadrao` | nada |
| `App\Support\Campos\SelecaoDeCampos` | Interpreta a string crua contra um mapa e deriva o que cada camada precisa | `Campo` |
| `App\Resource\Pessoa\MapaDeCamposPessoa` | Declara o schema exposto de pessoa | `Campo` |

`Campo` e `SelecaoDeCampos` são genéricos: a próxima Resource escreve só o mapa dela.

### O mapa

```php
// app/Resource/Pessoa/MapaDeCamposPessoa.php
'cd_pessoa'                 => Campo::coluna('cd_pessoa', noPadrao: true),
'ds_nome'                   => Campo::coluna('ds_nome', noPadrao: true),
'ds_login'                  => Campo::coluna('ds_login', noPadrao: true),
'sn_pessoa_juridica'        => Campo::coluna('sn_pessoa_juridica', noPadrao: true),
'cd_cliente'                => Campo::coluna('cd_cliente'),
'fisica.ds_nome_oficial'    => Campo::relacao('fisica', 'ds_nome_oficial', self::CHAVE_LOCAL),
'fisica.ds_cpf'             => Campo::relacao('fisica', 'ds_cpf', self::CHAVE_LOCAL),
'juridica.ds_cnpj'          => Campo::relacao('juridica', 'ds_cnpj', self::CHAVE_LOCAL),
'juridica.ds_nome_fantasia' => Campo::relacao('juridica', 'ds_nome_fantasia', self::CHAVE_LOCAL),
```

Um arquivo responde três perguntas hoje espalhadas: o que é exposto, para onde cada campo aponta, e o que entra no default enxuto.

`ds_senha` não está no mapa. **Não existe blacklist a manter:** o que não está no mapa não é selecionável, por construção. Campo novo é uma linha.

### Interface de `SelecaoDeCampos`

| Método | Devolve | Consumidor |
|---|---|---|
| `colunas()` | colunas de `unim_pessoa` para o `select()` | Repository |
| `relacoes()` | `['fisica' => ['cd_pessoa', 'ds_cpf']]` — FK sempre injetada | Repository |
| `campos()` | chaves do mapa a manter na saída — **não** inclui `cd_pessoa`/FK adicionados por necessidade de join | Resource |
| `tudo()` | `true` quando `fields=*` ou seleção ausente no item | Resource, Repository |

Construção: `SelecaoDeCampos::de(?string $fields, array $mapa, string $chaveLocal, bool $padraoEhTudo = false)`.

Os nomes das chaves de join são dados explícitos, não convenção: a **chave estrangeira** vem em cada `Campo::relacao()` e a **chave local** do pai vem no `$chaveLocal`. Assumir `cd_pessoa` por default deixaria conhecimento de pessoa dentro da classe genérica — e a próxima Resource pagaria por isso. `MapaDeCamposPessoa::selecao()` encapsula os dois, de forma que o call site não repita nada.

### Curingas

`*` = tudo. `fisica.*` = todas as chaves do mapa com prefixo `fisica.`. A expansão sai do mapa, então relação nova não exige tocar no parser.

### Fluxo

```
ListPessoaRequest      valida ?fields= contra o mapa
        │
PessoaController       monta SelecaoDeCampos
        │                lista → padrão enxuto
        │                item  → padrão completo
        ├──────────────► PessoaService ──► PessoaRepository    select() + with()
        └──────────────► PessoaResource                        recorte da saída
```

Assinaturas ganham o parâmetro **opcional**, `null` = completo:

```php
public function listar(int $cdCliente, array $filtros, int $page, int $perPage, ?SelecaoDeCampos $selecao = null): array;
public static function um(UnimPessoa $pessoa, ?SelecaoDeCampos $selecao = null): array;
```

Assim `POST`/`PUT`/`PATCH` ficam intocados: já chamam `PessoaResource::um($pessoa)` e seguem devolvendo completo sem edição. `PessoaRepositoryInterface` muda junto da implementação.

### SQL resultante

`GET /pessoas` (default):

```sql
select cd_pessoa, ds_nome, ds_login, sn_pessoa_juridica from unim_pessoa
where cd_cliente = ? and dt_excluido is null limit 20
```

**3 queries → 1.** As duas de eager load desaparecem porque nenhuma relação foi pedida.

`GET /pessoas?fields=ds_nome,fisica.ds_cpf`:

```sql
select cd_pessoa, ds_nome from unim_pessoa where ...
select cd_pessoa, ds_cpf from unim_pessoa_fisica where cd_pessoa in (...)
```

### A armadilha do eager load parcial

O `select` **dentro** da relação precisa da chave estrangeira:

```php
$query->with(['fisica' => fn ($q) => $q->select('cd_pessoa', 'ds_cpf')]);
```

Sem `cd_pessoa` ali o Eloquent não casa filho com pai e **não emite erro**: `$pessoa->fisica` vem `null`. É a mesma classe de falha silenciosa das chaves de ACL corrigidas em `82d8963`. Mitigação em duas frentes: `relacoes()` injeta a FK sempre, e existe teste dedicado a esse caso.

No pai, pela mesma razão: relação pedida ⇒ `cd_pessoa` entra no `select` de `unim_pessoa`, senão não há como montar o `in (...)`.

Consequência para o Resource: `colunas()` e `relacoes()` podem conter mais do que `campos()`. O recorte da saída usa **`campos()`**, então `cd_pessoa` e a FK entram no SQL e saem da resposta — o contrato não vaza detalhe do ORM.

### Validação

`ListPessoaRequest` ganha `'fields' => 'sometimes|string'` e um `withValidator` que confere cada token contra o mapa — mesmo padrão que `PatchPessoaRequest` já usa. O 422 no envelope correto vem do `ValidationExceptionHandler` existente.

**Mudança estrutural exigida:** `GET /pessoas/{id}` hoje não tem `ValidationMiddleware` nem FormRequest. Para validar `fields` ali é preciso criar `BuscarPessoaRequest` e registrar o middleware nessa rota em `config/routes.php`, respeitando a ordem já documentada no arquivo (Auth/Acl antes de Validation, para token inválido barrar em 401 antes da validação rodar).

## Segurança

- **Nome de coluna nunca vem do cliente.** O cliente escolhe chaves do mapa; a coluna que entra no SQL está escrita no `Campo`, no código. Não há caminho de string do cliente até o `select()` — injeção impossível por construção, não por escaping.
- `cd_cliente` continua vindo da identidade autenticada no `WHERE`, sempre. `fields` decide **o que é lido**, nunca **de quem**: pedir `cd_cliente` só muda se o valor aparece na resposta.
- `ds_senha` é inalcançável por ausência no mapa, e a mensagem de erro não a distingue de um campo inexistente.

## LGPD

Hoje `GET /pessoas` devolve **CPF e CNPJ de toda a página**, sempre. Com o default enxuto, CPF só sai quando `fisica.ds_cpf` é pedido explicitamente. Isso reduz exposição de PII no caminho mais usado da API — benefício direto, não efeito colateral, alinhado à preferência por mínimo necessário.

## Documentação

`#[OA\Parameter(name: 'fields', ...)]` nas duas rotas GET. A descrição precisa dizer as duas coisas que surpreendem: **lista e item têm defaults diferentes**, e `fields=*` traz tudo. A assimetria é norma REST, mas só funciona documentada.

## Testes

Decisão deliberada: **não contar queries.** Com pool de conexões por corrotina, `Connection::enableQueryLog()` depende de qual conexão o pool entregou e o teste fica intermitente. Duas asserções determinísticas provam o mesmo:

```php
$pessoa->relationLoaded('fisica')      // false -> nenhum eager load rodou
array_keys($pessoa->getAttributes())   // exatamente as colunas pedidas
```

Ambos os métodos existem no model do Hyperf (`HasRelationships::relationLoaded`, `HasAttributes::getAttributes`).

| Caso | Prova |
|---|---|
| lista sem `fields` | 4 colunas em `getAttributes()`, `relationLoaded('fisica') === false` |
| `fields=*` | resposta idêntica ao contrato atual (regressão de formato) |
| `fields=ds_nome,fisica.ds_cpf` | aninhamento correto, `ds_cpf` presente |
| eager load traz a FK | `fisica` **não** é `null` quando pedida |
| `fields=fisica.*` | expande pelo mapa |
| `fields=ds_nomee` / `fields=ds_senha` | 422, mensagem idêntica para os dois |
| `GET /pessoas/{id}` sem `fields` | completo |
| `POST`/`PATCH` com `?fields=ds_nome` | ignora, devolve completo |
| `fisica.ds_cpf` em pessoa jurídica | `"fisica": null`, chave presente |
| `?fields=` vazio | cai no default, sem 422 |
| `fields=ds_nome,fisica.ds_cpf` | `cd_pessoa` **não** aparece na resposta, embora esteja no SELECT |
| `fields=ds_nome, ds_login` (com espaço) e `ds_nome,ds_nome` | 200, tokens aparados e deduplicados |
| `fields=ds_nome,*` | equivale a `fields=*` |

Os testes usam o tenant descartável (`HyperfTest\Support\TenantDeTeste`, commit `8dda7a7`), que nasce vazio — dá para afirmar contagem e conteúdo sem depender do estado da base.

## Escopo

**Dentro:** `GET /pessoas` e `GET /pessoas/{id}`; `Campo`, `SelecaoDeCampos`, `MapaDeCamposPessoa`; validação e 422; Swagger das duas rotas; testes acima.

**Fora:** qualquer outra Resource (nenhuma existe hoje); `?include=`; cache de resposta; ordenação por campo selecionado; seleção em respostas de escrita; tradução pt_BR das mensagens de validação.

## Risco aceito

Se existir consumidor não encontrado pela busca, ele para de receber `fisica`/`juridica` em `GET /pessoas` sem aviso prévio, e a correção do lado dele é acrescentar `?fields=*`.

O risco foi aceito conscientemente, sobre três bases: a rota tem 5 dias, a busca por consumidor não achou nenhum, e **o dono do projeto confirmou em 2026-07-30 não conhecer consumidor algum**. O custo de mudar o default só cresce daqui para frente.
