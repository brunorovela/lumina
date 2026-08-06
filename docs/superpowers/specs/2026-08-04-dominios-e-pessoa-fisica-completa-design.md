# Domínios de cadastro e cobertura completa de pessoa física

Primeira de três entregas do cadastro de pessoa. As outras duas — endereço e contatos — dependem desta e estão descritas em "Entregas seguintes".

## Problema

O cadastro de pessoa está pela metade e de três maneiras diferentes.

**`unim_pessoa_fisica` tem 13 colunas e a API expõe 2.** `MapaDeCamposPessoa` conhece `ds_nome_oficial` e `ds_cpf`. As outras dez — nome social, filiação, identidade, sexo, data de nascimento, estado civil — são inalcançáveis por qualquer rota. Não há como cadastrar uma pessoa física completa pela API, e o que não está no mapa não existe para o cliente.

**As FKs não têm como ser descobertas.** `unim_pessoa_fisica.cd_estado_civil` aponta para `saas_estado_civil`; o endereço (entrega 2) aponta para `saas_pais`, `saas_estado` e `saas_cidade`; o contato (entrega 3) aponta para `unim_pessoa_contato_tipo`. Nenhuma dessas tabelas é legível pela API. Quem consome precisa de outra fonte para saber que `cd_estado_civil` mandar — na prática, abrir o banco.

**Falta a coluna de exclusão.** `unim_pessoa`, `unim_pessoa_juridica` e `unim_pessoa_contato` têm `dt_excluido`. `unim_pessoa_fisica` e `unim_pessoa_endereco` não. Confirmado contra `information_schema` em 2026-08-04.

## Situação atual

```
unim_pessoa            cd_cliente ✓   dt_excluido ✓
unim_pessoa_fisica     cd_cliente ✗   dt_excluido ✗    13 colunas, 2 expostas
unim_pessoa_juridica   cd_cliente ✗   dt_excluido ✓    3 colunas, 2 expostas (completa)
unim_pessoa_endereco   cd_cliente ✗   dt_excluido ✗    entrega 2
unim_pessoa_contato    cd_cliente ✗   dt_excluido ✓    entrega 3
```

Volumes dos domínios: `saas_pais` 2 linhas, `saas_estado` 27, `saas_cidade` 4928, `saas_estado_civil` 6, `unim_pessoa_contato_tipo` 5.

`unim_pessoa_contato_tipo` tem cinco chaves: `TELEFONE`, `TELEFONE-COMERCIAL`, `TELEFONE-CELULAR`, `EMAIL`, `SITE`.

ACL disponível em `ulms_recurso_privilegio`: `GERENCIAR_PESSOA` com `ACESSAR`, `INSERIR`, `ATUALIZAR`, `DELETAR`, `VISUALIZAR_TODAS_PESSOAS_BUSCA`. Não existe recurso próprio para endereço, contato ou domínio.

Dados legados relevantes: `unim_pessoa_fisica.ds_sexo` é `varchar` livre e contém `f` (291.543), `m` (217.709), `NULL` (30.660), string vazia (27.279) e 21 linhas com `n`/`a`/`o`/`b`. Nenhuma constraint no banco.

## Decisões

1. **Domínios ganham rotas read-only próprias**, e a escrita continua recebendo `cd_*`. Sem isso a API é indocumentável: não há como dizer ao leitor que valor mandar.
2. **Física e jurídica não ganham sub-recurso.** Os 10 campos entram no `/pessoas` existente. `PUT /pessoas/{id}/fisica` numa pessoa jurídica criaria linha órfã — o bug já corrigido no Finding 14 — e duplicaria a regra de tipo, que hoje vive num lugar só (`PessoaRepository::atualizar()`).
3. **Rótulo de estado civil não é expandido na leitura de pessoa.** `Campo::relacao()` tem um nível só; `fisica.estado_civil.ds_estado_civil` é profundidade 2 e `SelecaoDeCampos` não suporta. Estender o núcleo genérico para relação aninhada é um spec por si. A leitura devolve `cd_estado_civil` cru e o cliente resolve por `GET /estados-civis`. A expansão de rótulo vale para endereço (entrega 2), que tem endpoint próprio e faz join à vontade.
4. **PII exige pedido nominal.** `ds_cpf`, `ds_identidade`, `ds_nome_mae`, `ds_nome_pai` e `dt_nascimento` saem do default de `GET /pessoas/{id}`. Isso quebra o contrato atual: `ds_cpf` vem por padrão hoje.
5. **Escrita devolve PII.** Resposta filtrada esconderia o que o servidor gravou.
6. **Domínios são globais.** Nenhuma tabela de domínio tem `cd_cliente`, e catálogo de cidade não é dado de cliente. Sem `WHERE` de tenant nessas cinco rotas, e isso vai dito na documentação por ser diferente de `/pessoas`.
7. **Domínios não paginam.** As quatro listas curtas devolvem tudo (máximo 27 itens). `/cidades` exige `cd_estado` e nunca despeja as 4928.
8. **Domínios reusam `GERENCIAR_PESSOA` + `ACESSAR`.** É o único recurso que existe, e chave inventada nega tudo em silêncio.
9. **Sem cache de domínio.** Busca por `cd_estado` indexado em 4928 linhas não justifica invalidação. YAGNI.
10. **Sem `?fields=` nas rotas de domínio.** Payload de três campos.
11. **Validação de formato na escrita:** `ds_sexo` restrito, DV de CPF/CNPJ, máscara normalizada, datas coerentes. O banco não restringe nada e o legado gravou lixo; a API não vai acrescentar mais.
12. **`ALTER TABLE` não é aplicado por esta entrega** (regra 2). O SQL exato está em "Bloqueio de banco", a decisão é de quem tem a caneta.

## Contrato

### Rotas de domínio

Todas atrás de `AuthMiddleware` + `AclMiddleware` com `GERENCIAR_PESSOA` + `ACESSAR`. `ValidationMiddleware` entra só em `/estados` e `/cidades` — são as duas que têm `FormRequest`; nas outras três não haveria o que validar. Onde entra, vem **depois** de `AuthMiddleware`/`AclMiddleware` na mesma lista, para token inválido barrar em 401 antes de a validação revelar o contrato (padrão e motivo documentados em `config/routes.php`).

```
GET /paises
GET /estados?cd_pais={int}
GET /cidades?cd_estado={int}&q={string}
GET /estados-civis
GET /contato-tipos
```

Envelope sem `meta`:

```json
{
  "success": true,
  "data": [
    { "cd_pais": 1, "ds_pais": "Brasil", "ds_nacionalidade": "Brasileira" }
  ]
}
```

| Rota | Campos devolvidos | Parâmetros |
|---|---|---|
| `/paises` | `cd_pais`, `ds_pais`, `ds_nacionalidade` | nenhum |
| `/estados` | `cd_estado`, `cd_pais`, `ds_estado`, `ds_uf` | `cd_pais` opcional |
| `/cidades` | `cd_cidade`, `cd_estado`, `ds_cidade` | `cd_estado` **obrigatório**, `q` opcional |
| `/estados-civis` | `cd_estado_civil`, `ds_estado_civil` | nenhum |
| `/contato-tipos` | `cd_tipo`, `ds_chave`, `ds_descricao` | nenhum |

`dt_base` não é exposto em nenhuma: é coluna de controle do LMS (`ON UPDATE CURRENT_TIMESTAMP`), não dado de negócio.

`q` filtra `ds_cidade` por `like %q%`, mínimo 1 caractere.

Status: 200 sempre que autenticado e autorizado; 401 sem token; 403 sem o par ACL; 422 em `/cidades` sem `cd_estado` ou com parâmetro de tipo errado.

### Campos novos de pessoa física

Dez campos entram em `MapaDeCamposPessoa`, todos como `Campo::relacao('fisica', ..., 'cd_pessoa')`:

| Campo | Tipo | Sensível |
|---|---|---|
| `fisica.ds_nome_social` | string, max 255 | não |
| `fisica.ds_nome_mae` | string, max 255 | **sim** |
| `fisica.ds_nome_pai` | string, max 255 | **sim** |
| `fisica.ds_identidade` | string, max 255 | **sim** |
| `fisica.ds_orgao_estado` | string, max 255 | não |
| `fisica.ds_identidade_orgao_exp` | string, max 255 | não |
| `fisica.dt_identidade_expedicao` | date `Y-m-d` | não |
| `fisica.dt_nascimento` | date `Y-m-d` | **sim** |
| `fisica.ds_sexo` | `f` ou `m` | não |
| `fisica.cd_estado_civil` | int, FK `saas_estado_civil` | não |

`fisica.ds_cpf`, que já existe, passa a ser marcado sensível.

`unim_pessoa_juridica` não muda: as duas colunas de negócio (`ds_cnpj`, `ds_nome_fantasia`) já estão expostas. Só ganha documentação.

### Leitura: o que vem em cada caminho

| Caminho | Traz sensível |
|---|---|
| `GET /pessoas` sem `fields` | não se aplica — o default enxuto não carrega relação nenhuma |
| `GET /pessoas/{id}` sem `fields` | **não** |
| `fields=*` | sim |
| `fields=fisica.*` | sim |
| `fields=fisica.ds_cpf` (nome exato) | sim |
| resposta de `POST`/`PUT`/`PATCH /pessoas` | sim |

Exemplo do default do detalhe depois desta entrega:

```json
GET /pessoas/9
{
  "success": true,
  "data": {
    "cd_pessoa": 9, "cd_cliente": 20, "ds_nome": "Ana Souza",
    "ds_login": "ana.souza", "sn_pessoa_juridica": false,
    "fisica": {
      "ds_nome_oficial": "Ana Souza", "ds_nome_social": "Ana",
      "ds_orgao_estado": "SP", "ds_identidade_orgao_exp": "SSP",
      "dt_identidade_expedicao": "2015-03-01", "ds_sexo": "f",
      "cd_estado_civil": 37
    },
    "juridica": null
  }
}
```

`ds_cpf`, `ds_identidade`, `ds_nome_mae`, `ds_nome_pai` e `dt_nascimento` só aparecem se pedidos.

### Escrita

`POST /pessoas`, `PUT /pessoas/{id}` e `PATCH /pessoas/{id}` passam a aceitar os dez campos. Todos opcionais — nenhum é `required`, nem no `POST`. As regras existentes (`ds_nome_oficial` obrigatório para física, `ds_cnpj`/`ds_nome_fantasia` para jurídica) não mudam.

`PATCH` mantém o comportamento vigente: campos do tipo que a pessoa **não** é são ignorados em silêncio, e `PATCH` nunca troca o tipo.

Normalização, aplicada em `validationData()` antes das regras rodarem — logo `validated()` já devolve limpo:

| Entrada | Gravado |
|---|---|
| `ds_cpf: "123.456.789-09"` | `"12345678909"` |
| `ds_cnpj: "00.000.000/0001-91"` | `"00000000000191"` |
| `ds_sexo: "F"` | `"f"` |
| `ds_sexo: ""` | `null` |
| qualquer `ds_*` string vazia | `null` |

A resposta devolve o valor normalizado, não o enviado. Documentado no Swagger.

Regras:

```
ds_sexo                  nullable|in:f,m
ds_cpf                   nullable|digits:11   + DV
ds_cnpj                  required_if:sn_pessoa_juridica,true|digits:14 + DV
dt_nascimento            nullable|date_format:Y-m-d|before_or_equal:today
dt_identidade_expedicao  nullable|date_format:Y-m-d|before_or_equal:today
                         |after_or_equal:dt_nascimento
cd_estado_civil          nullable|integer|exists:saas_estado_civil,cd_estado_civil
ds_nome_social           nullable|string|max:255
ds_nome_mae              nullable|string|max:255
ds_nome_pai              nullable|string|max:255
ds_identidade            nullable|string|max:255
ds_orgao_estado          nullable|string|max:255
ds_identidade_orgao_exp  nullable|string|max:255
```

`exists:saas_estado_civil` não é decoração: sem ele um `cd_estado_civil` inexistente viola FK, sai como SQLSTATE 23000 e o `DatabaseExceptionHandler` mapeia para **409** — o mesmo status de "login já existe". Um 409 nesse endpoint seria interpretado como duplicidade. Com `exists`, é 422 com o campo nomeado.

`after_or_equal:dt_nascimento` só é avaliada quando `dt_nascimento` vem no mesmo payload. Num `PATCH` que manda só `dt_identidade_expedicao`, a regra cruzada não roda — a alternativa seria ler o nascimento gravado no banco durante a validação, o que coloca consulta dentro do `FormRequest`. Aceito e documentado.

## Arquitetura

### Componentes novos

| Artefato | Caminho | Papel |
|---|---|---|
| `SaasPais`, `SaasEstado`, `SaasCidade`, `SaasEstadoCivil`, `UnimPessoaContatoTipo` | `app/Model/Dominio/` | leitura de catálogo. `timestamps = false` — nunca escrevemos `dt_base`. Sem `SoftDeletes`: nenhuma tem `dt_excluido` |
| `DominioRepository` + `DominioRepositoryInterface` | `app/Repository/Dominio/` | cinco métodos de leitura |
| `DominioController` | `app/Controller/Dominio/` | cinco actions finas; o volume vem dos atributos `#[OA\...]`, como em `PessoaController` |
| `DominioResource` | `app/Resource/Dominio/` | recorte explícito por domínio. Não usa `toArray()` do model, que vazaria `dt_base` |
| `ListEstadoRequest`, `ListCidadeRequest` | `app/Request/Dominio/` | `cd_estado` obrigatório em cidade |
| `PaisSchema`, `EstadoSchema`, `CidadeSchema`, `EstadoCivilSchema`, `ContatoTipoSchema` | `app/Swagger/` | um schema por classe (`#[OA\Schema]` não é repetível) |
| `Documento` | `app/Support/` | `cpfEhValido()`, `cnpjEhValido()`, `apenasDigitos()` |
| `ValidaDocumentosDePessoa` | `app/Request/Pessoa/Concerns/` | aciona o DV via `withValidator()`, mesmo padrão de `ValidaCamposDePessoa` |

### Componentes alterados

| Artefato | Mudança |
|---|---|
| `Campo` | ganha `sensivel: bool` nas duas factories |
| `SelecaoDeCampos` | ganha `completa()`; o ramo `padraoEhTudo` filtra sensível |
| `MapaDeCamposPessoa` | dez campos novos; `fisica.ds_cpf` marcado sensível |
| `PessoaResource` | usa `completa()` quando `$selecao` é null — é o caminho de escrita, confirmado nas linhas 73, 113 e 151 de `PessoaController` |
| `PessoaRepository` | o fallback de `$selecao === null` em `buscarPorId()` e `listar()` passa a `completa()`. É leitura interna (`atualizarParcial()` chama `buscar()` sem seleção para descobrir o tipo da pessoa) e não deve ser estreitada por regra de exposição |
| `PessoaService` | constante `CAMPOS_FISICA` única, usada por `separarDados()` e `atualizarParcial()` |
| `CreatePessoaRequest`, `UpdatePessoaRequest`, `PatchPessoaRequest` | dez campos, `validationData()`, trait de DV |
| `PessoaSchema` | dez propriedades novas |
| `PessoaController` | descrição do `#[OA\Parameter(name:'fields')]` nos dois endpoints de leitura |
| `UnimPessoaFisica` | `SoftDeletes` — **depende do bloqueio de banco** |
| `PessoaRepository::atualizar()` | `withTrashed()` + `restore()` — **depende do bloqueio de banco** |
| `config/routes.php` | cinco rotas de domínio |
| `storage/languages/en/validation.php` | chaves de mensagem do DV |

### O campo sensível no núcleo

Hoje `SelecaoDeCampos::de()` colapsa dois caminhos na mesma seleção:

```php
if ($tokens === []) {
    return $padraoEhTudo
        ? new self($mapa, array_keys($mapa), $chaveLocal, true)   // default do item
        : new self($mapa, self::doPadrao($mapa), $chaveLocal, false);
}

if (in_array('*', $tokens, true)) {
    return new self($mapa, array_keys($mapa), $chaveLocal, true); // curinga
}
```

As duas construções são idênticas, então "default do item" e "`fields=*`" são indistinguíveis. A mudança separa as três intenções:

- `de()` com `padraoEhTudo` e sem tokens → mapa inteiro **menos** os sensíveis;
- `de()` com `*` ou `fisica.*` ou nome exato → inclui sensível, porque foi pedido;
- `completa()` → mapa inteiro, para resposta de escrita.

`expandir()` e `invalidos()` não mudam. Curinga é pedido explícito e por isso traz sensível.

Ganho colateral: o default do detalhe passa a projetar menos colunas no `SELECT` da relação. Menos dado no banco e no fio.

`sensivel` é genérico e será reusado nas entregas 2 e 3.

### Escopo de tenant

Nenhuma tabela filha de pessoa tem `cd_cliente`. O tenant só existe via `unim_pessoa`, e nesta entrega toda leitura de física passa pelo eager load de `UnimPessoa`, que já filtra `cd_cliente` em `PessoaRepository`. Não há rota nova que toque tabela filha.

As cinco rotas de domínio são globais por natureza — não têm `cd_cliente` a filtrar.

As regras completas de escopo em sub-recurso valem para as entregas 2 e 3 e estão registradas em "Entregas seguintes", porque é lá que a superfície aparece.

## Bloqueio de banco

Duas tabelas não têm `dt_excluido`. Acrescentar é `ALTER TABLE` em schema compartilhado com o LMS legado, o que a regra 2 do `CLAUDE.md` proíbe a esta entrega de aplicar. O SQL exato:

```sql
ALTER TABLE unim_pessoa_fisica   ADD COLUMN dt_excluido datetime DEFAULT NULL;
ALTER TABLE unim_pessoa_endereco ADD COLUMN dt_excluido datetime DEFAULT NULL;
```

`unim_pessoa_endereco` é pré-requisito da entrega 2, não desta. `unim_pessoa_fisica` afeta esta.

Os cinco catálogos (`unim_pessoa_contato_tipo`, `saas_pais`, `saas_estado`, `saas_cidade`, `saas_estado_civil`) **ficam sem `dt_excluido`**, decidido em 2026-08-04: ninguém exclui uma cidade pela API, e a FK `ON DELETE RESTRICT` já impede apagar cidade em uso. As cinco rotas de domínio não filtram exclusão.

### Ordem de aplicação, e ela não é negociável

1. DBA aplica o `ALTER` de `unim_pessoa_fisica`. Coluna nullable, todas as linhas `NULL`, nenhum código lê. Inócuo em produção e no LMS.
2. Commit liga `SoftDeletes` e `const DELETED_AT = 'dt_excluido'` em `UnimPessoaFisica`.
3. **No mesmo commit do passo 2**, `PessoaRepository::atualizar()` troca `UnimPessoaFisica::updateOrCreate()` por `withTrashed()` + `restore()`.

O passo 3 não é polimento. `unim_pessoa_fisica` tem PK `cd_pessoa`; com `SoftDeletes`, o scope global esconde a linha excluída, o `updateOrCreate()` tenta INSERT, bate na PK, sai como 23000 e o handler devolve **409**. Trocar uma pessoa de jurídica para física depois de uma exclusão passaria a falhar. É o mesmo motivo pelo qual `PessoaRepository::loginExiste()` já usa `withTrashed()`, documentado lá.

Invertida a ordem — código antes da coluna — `withTrashed()` estoura, porque o trait exige a coluna.

Se o `ALTER` não for aprovado, esta entrega faz tudo menos os passos 2 e 3. Os dez campos e as cinco rotas não dependem dele. Fica registrado como bloqueio, não como pendência silenciosa.

`sn_excluido` continua invisível (regra 3). A exceção de `unim_pessoa_juridica.sn_excluido` — `NOT NULL` sem default, suprido por `$attributes` — fica como está.

## LGPD

`ds_cpf`, `ds_identidade`, `ds_nome_mae`, `ds_nome_pai` e `dt_nascimento` são dado pessoal. A decisão 4 os tira do default do detalhe: passam a exigir pedido nominal ou curinga.

CORREÇÃO (revisão final): a frase anterior aqui afirmava que "o log de acesso mostra quem pediu PII, porque pediu por escrito" — isso é falso. Esta entrega não adiciona nenhum log de acesso/auditoria a `?fields=`, e `DbQueryExecutedListener` (a única coisa no projeto que loga SQL) para de rodar quando `APP_ENV=production` justamente para não expor PII em log, por design — o que é o oposto de um mecanismo de auditoria de quem pediu o quê. `sensivel` é um controle de discoverability e tamanho de payload (PII não aparece por acidente num `SELECT *` implícito, precisa ser pedida por nome ou curinga), não um controle de auditoria. Se auditoria de acesso a PII vier a ser necessária, é trabalho novo, não algo que já existe aqui.

Não há máscara na leitura. Mascarar o mesmo campo conforme o `fields` faria a mesma chave devolver valores diferentes, e um cliente gravaria o valor mascarado de volta num `PUT`.

`ds_senha` continua fora do mapa, logo inalcançável por construção. Nada nesta entrega muda isso.

O `DbQueryExecutedListener` já para de logar SQL quando `APP_ENV=production`, o que evita CPF em log de query.

## Documentação

Regra 1 do `CLAUDE.md`: atributos e artefato no mesmo commit.

Cada uma das cinco rotas de domínio precisa de `content`/`JsonContent` com schema, todo parâmetro com tipo, default e exemplo, e cada status apontando o corpo daquele status. Ditos explicitamente, porque quem lê não vai adivinhar:

- domínios são globais, sem escopo de tenant — diferente de `/pessoas`;
- listas de domínio não têm `meta`, porque não paginam;
- `/cidades` exige `cd_estado` e responde 422 sem ele;
- PII exige pedido nominal ou curinga, e `ds_cpf` **saiu** do default de `GET /pessoas/{id}`;
- a resposta devolve CPF/CNPJ/CEP sem máscara, ainda que tenham sido enviados com;
- resposta de escrita ignora `fields` e traz tudo, PII incluso.

Depois:

```bash
docker exec lumina php /opt/www/bin/hyperf.php gen:swagger
```

E a conferência é no artefato, não no fonte:

```bash
python3 -c "import json; d=json.load(open('storage/swagger/http.json')); print(json.dumps(d['paths']['/cidades']['get'], ensure_ascii=False, indent=2))"
```

## Testes

Regra 4: teste vale pelo bug que pega.

| Teste | Falha que pega |
|---|---|
| `GET /pessoas/{id}` sem `fields` não traz `ds_cpf`; `fields=fisica.ds_cpf` traz; `fields=fisica.*` traz | flag `sensivel` errada — vazamento de PII sem erro nenhum |
| `POST /pessoas` devolve `ds_cpf` gravado | escrita filtrada esconde o que o servidor persistiu |
| `"123.456.789-09"` grava `12345678909`; `"F"` grava `f`; `""` grava `null` | `validationData()` não rodou antes das regras |
| CPF com DV inválido → 422 com frase real, não `validation.cpf` | chave de mensagem ausente em `storage/languages/en/validation.php` |
| `cd_estado_civil` inexistente → **422**, não 409 | sem `exists`, a FK vira 23000 e o handler devolve 409 enganoso |
| `PATCH` com campo de física em pessoa jurídica → ignorado, nenhuma linha criada | regressão do Finding 14, agora com dez campos a mais de superfície |
| `dt_identidade_expedicao` anterior a `dt_nascimento` → 422 | regra cruzada de datas |
| `GET /cidades` sem `cd_estado` → 422; com `cd_estado` → só cidades daquele estado | rota que despejaria 4928 linhas |
| eager load parcial de física com campo novo casa pai e filho (`assertNotNull($pessoa->fisica)`) | FK ausente no `select` da relação — o Eloquent devolve `null` sem erro |
| `GET /pessoas/{id}` de outro tenant → 404 | escopo de tenant no guarda existente, agora com mais campo a vazar |

Depois do bloqueio de banco liberado, mais um:

| Teste | Falha que pega |
|---|---|
| pessoa jurídica → física depois de exclusão da linha física → 200, não 409 | `updateOrCreate()` sem `withTrashed()` bate na PK `cd_pessoa` |

Não escrever: contagem de linhas de `/paises` (dado volátil), getter de model, e repetir pelo endpoint o que `SelecaoDeCamposTest` já prova em unidade.

Testes usam `HyperfTest\Support\TenantDeTeste` — `cd_cliente = 1` e `cd_perfil = 1` não existem neste banco.

## Entregas seguintes

Decidido em 2026-08-04, registrado aqui para não se perder.

### Entrega 2 — endereço

```
GET    /pessoas/{id}/endereco
PUT    /pessoas/{id}/endereco
DELETE /pessoas/{id}/endereco
```

`unim_pessoa_endereco` é **1:1** (PK `cd_pessoa`). Guarda também a naturalidade (`cd_pais_nascimento`, `cd_estado_nascimento`, `cd_cidade_nascimento`), que não é endereço mas mora na mesma linha — `DELETE` leva as duas coisas, e isso vai na documentação. Quem quer limpar só o endereço usa `PUT` omitindo os campos de logradouro.

A leitura expande rótulo (`ds_cidade`, `ds_uf`, `ds_pais`) por join, o que o endpoint próprio permite e o mapa de pessoa não.

Depende do `ALTER` de `dt_excluido` em `unim_pessoa_endereco`: com a coluna, `DELETE` passa a ser soft delete e o `PUT` seguinte revive a linha, pelo mesmo motivo de PK do passo 3 do bloqueio.

### Entrega 3 — contatos

```
GET    /pessoas/{id}/contatos
POST   /pessoas/{id}/contatos
PUT    /pessoas/{id}/contatos/{cd_contato}
DELETE /pessoas/{id}/contatos/{cd_contato}
```

`unim_pessoa_contato` é **1:N**, já tem `dt_excluido`, e tem `UNIQUE (cd_pessoa, ds_contato, cd_tipo)` que existe sobre todas as linhas, inclusive as excluídas — mesma armadilha do `ds_login`. `POST` de um contato idêntico a um já excluído **reativa a linha** e devolve 201 com o `cd_contato` antigo, reaproveitado. Documentado.

Depende de `GET /contato-tipos`, desta entrega.

### Escopo de tenant nos sub-recursos

Vale para as duas entregas. Nenhuma tabela filha tem `cd_cliente`, então:

1. Resolver a pessoa primeiro, sempre com tenant: `WHERE cd_pessoa = ? AND cd_cliente = ? AND dt_excluido IS NULL`. Não achou → **404**, nunca 403 — 403 confirmaria que o `cd_pessoa` existe em outro cliente.
2. Nunca consultar tabela filha só por `cd_pessoa`. O `cd_pessoa` vem da URL, controlado pelo cliente; sem o passo 1, `GET /pessoas/999/endereco` lê endereço de qualquer tenant.
3. `cd_contato` é AUTO_INCREMENT global: `PUT`/`DELETE` de contato precisa de `WHERE cd_contato = ? AND cd_pessoa = ?`. Sem isso, adivinhar um id edita contato de outro cliente mesmo com a pessoa da URL correta.
4. `cd_pessoa` nunca aceito no payload. Só URL.

Teste de vazamento é obrigatório nas duas entregas (regra 4, escopo de tenant): dois tenants, uma pessoa em cada, cada rota tentando cruzar, em leitura **e** escrita.

### Decisão adiada

Sub-recurso `GET`/`PUT /pessoas/{id}/fisica` e `/juridica`. Não está descartado, está fora de escopo. Se voltar à mesa, precisa resolver o que responder quando o tipo da pessoa não casa com o sub-recurso, e onde a regra de tipo passa a viver sem duplicar.

## Escopo

**Entra:** cinco rotas read-only de domínio; dez campos de pessoa física; `sensivel` no núcleo de seleção de campos; validação de formato (sexo, DV, máscara, datas); `SoftDeletes` em `UnimPessoaFisica` e o conserto do `updateOrCreate()`, se o `ALTER` for aprovado; Swagger completo do que foi tocado, com `http.json` regenerado.

**Não entra:** endereço; contatos; sub-recurso de física/jurídica; expansão de rótulo na leitura de pessoa; relação aninhada de profundidade 2 no `SelecaoDeCampos`; cache de domínio; `?fields=` em domínio; `dt_excluido` nos cinco catálogos; escrita em qualquer tabela de domínio; unicidade de CPF por cliente (o legado tem duplicados e o banco não tem índice).

## Risco aceito

**`ds_cpf` sai do default de `GET /pessoas/{id}`.** É quebra de contrato para quem já consome. Aceita em troca de PII sob pedido nominal; mitigada pela documentação e por `fields=fisica.*` continuar trazendo tudo.

**A validação de DV rejeita o que o legado aceitou.** Há CPF com dígito inválido gravado. A regra vale só para escrita nova; leitura devolve o que está lá, sem validar.

**`ds_sexo` continua devolvendo lixo legado na leitura** (`n`, `a`, `o`, `b`, string vazia). Normalizar na leitura faria a API mentir sobre o dado gravado. A escrita nova aceita só `f`, `m` e `null`.

**`after_or_equal:dt_nascimento` não roda em `PATCH` que omite o nascimento.** A alternativa colocaria consulta ao banco dentro do `FormRequest`.

**O LMS legado não conhece `dt_excluido` em `unim_pessoa_fisica`.** Depois do `ALTER` e dos passos 2–3, uma linha que o Lumina considera excluída continua visível no LMS, cujas entidades Doctrine não filtram a coluna. Duas verdades sobre a mesma tabela até alguém mexer no LMS. O `ALTER` em si não quebra nada lá — coluna nullable que ninguém lê.
