# Contatos de pessoa

Terceira das três entregas do cadastro de pessoa. A primeira (domínios de cadastro e pessoa física completa) está em `main`; a segunda (endereço) está bloqueada por decisão de banco e **esta entrega passou na frente dela**, porque nada aqui depende de `ALTER TABLE`.

Spec da entrega 1: `docs/superpowers/specs/2026-08-04-dominios-e-pessoa-fisica-completa-design.md`. A seção "Entregas seguintes" dela já fixou a forma desta; este documento resolve o que ficou em aberto.

## Problema

`unim_pessoa_contato` guarda 1,28 milhão de linhas e a API não a expõe de nenhuma forma. Telefone, e-mail e site de uma pessoa são inalcançáveis: não há como ler, cadastrar, corrigir nem remover contato por nenhuma rota.

O LMS legado escreve nessa tabela por formulário, sem restrição de formato, e o resultado está no dado: e-mail que é CPF, string vazia em coluna `NOT NULL`, e telefone gravado com máscara em texto livre.

## Situação atual

```
unim_pessoa_contato
  cd_contato    int NOT NULL AUTO_INCREMENT  PK   (global, AUTO_INCREMENT=1320643)
  cd_pessoa     int NOT NULL                      FK -> unim_pessoa       (RESTRICT)
  cd_tipo       int NOT NULL                      FK -> unim_pessoa_contato_tipo (RESTRICT)
  ds_contato    varchar(255) NOT NULL
  dt_excluido   datetime NULL
  UNIQUE contato_unico (cd_pessoa, ds_contato, cd_tipo)
```

Sem `cd_cliente` — o tenant só existe via `unim_pessoa`. **Com** `dt_excluido`, e é por isso que esta entrega não precisa de `ALTER TABLE`.

Volumes e formas, medidos em 2026-08-07:

| Tipo | `cd_tipo` | Linhas | Menor `ds_contato` |
|---|---|---|---|
| `EMAIL` | 34 | 534.171 | 0 |
| `TELEFONE-CELULAR` | 33 | 504.466 | 0 |
| `TELEFONE` | 31 | 176.392 | 0 |
| `TELEFONE-COMERCIAL` | 32 | 67.813 | 0 |
| `SITE` | 35 | 2.190 | 0 |

O comprimento mínimo é **zero nos cinco tipos**: há string vazia gravada numa coluna `NOT NULL`. Telefones aparecem com máscara (`(63)99975-9446`), e entre os `EMAIL` há valores como `14703355817`, que é um CPF.

2.991 linhas têm `dt_excluido` preenchido. Máximo de 16 contatos vivos por pessoa, média 2,4, e apenas 76 pessoas passam de dez.

`GET /contato-tipos`, de que esta entrega depende, saiu na entrega 1. O par ACL `GERENCIAR_PESSOA` com `ACESSAR`/`INSERIR`/`ATUALIZAR`/`DELETAR` já foi confirmado contra `ulms_recurso_privilegio`.

## Decisões

1. **Sub-recurso próprio**, `1:N` sob `/pessoas/{id}`, com id na URL para as operações de item. Fixado no brainstorming da entrega 1.
2. **`POST` de um contato idêntico a um excluído reativa a linha**, devolvendo o `cd_contato` antigo. Sem isso, excluir e recadastrar o mesmo telefone bateria no `UNIQUE`, que existe sobre todas as linhas — inclusive as excluídas.
3. **Validação de formato por tipo**, com a regra escolhida pela `ds_chave` do `cd_tipo` enviado. O banco não restringe nada e o legado gravou lixo; a API para de acrescentar.
4. **Grava o texto como veio.** A máscara é removida apenas para contar dígitos na validação. Normalizar colidiria com 750 mil linhas mascaradas do legado, faria o LMS exibir dois formatos, e quebraria a reativação da decisão 2 — que depende de igualdade exata contra a linha excluída.
5. **`contatos` não entra no `?fields=` de `/pessoas`.** O núcleo de `SelecaoDeCampos`/`PessoaResource` só trata `hasOne`: uma relação `1:N` devolve `Collection` e `PessoaResource::um()` a descarta em `if (! $filho instanceof Model) continue`. Como `contatos` nunca entra no mapa, `fields=contatos.*` já responde **422** limpo hoje, via `MapaDeCamposPessoa::invalidos()`.
6. **Sem paginação.** Máximo de 16 por pessoa. Envelope sem `meta`, como as rotas de domínio.
7. **O rótulo do tipo é expandido na leitura.** O endpoint tem consulta própria e pode fazer o join; são 5 tipos e 2,4 linhas por pessoa.
8. **Duplicata contra linha viva é 409 checado, não 23000 traduzido.** Funciona pelos dois caminhos, mas só o explícito diz o que houve.
9. **`DELETE` é soft delete.** A coluna existe e o LMS legado a respeita (`UnimPessoaContatoRepository::doDeletar`).
10. **Nenhum `ALTER TABLE`** (regra 2). Esta entrega não precisa de nenhum.

## Contrato

Todas atrás de `AuthMiddleware` + `AclMiddleware`, com `ValidationMiddleware` **depois** deles na mesma lista, para token inválido barrar em 401 antes de a validação revelar o contrato.

```
GET    /pessoas/{id}/contatos                  ACESSAR
POST   /pessoas/{id}/contatos                  INSERIR
PUT    /pessoas/{id}/contatos/{cd_contato}     ATUALIZAR
DELETE /pessoas/{id}/contatos/{cd_contato}     DELETAR
```

### Leitura

```json
GET /pessoas/9/contatos
{
  "success": true,
  "data": [
    { "cd_contato": 771, "cd_tipo": 34, "ds_contato": "ana@x.com",
      "tipo": { "ds_chave": "EMAIL", "ds_descricao": "E-mail" } },
    { "cd_contato": 772, "cd_tipo": 33, "ds_contato": "(47)99185-0309",
      "tipo": { "ds_chave": "TELEFONE-CELULAR", "ds_descricao": "Telefone Celular" } }
  ]
}
```

Sem `meta`. Ordenado por `cd_contato`. Excluídos não aparecem. Pessoa sem contato devolve `"data": []` com 200 — lista vazia é resposta, não 404; o 404 fica reservado para a pessoa não existir ou ser de outro cliente.

`cd_pessoa` não é devolvido: já está na URL, e repeti-lo no corpo convida alguém a acreditar que pode mudá-lo.

### Escrita

```json
POST /pessoas/9/contatos
{ "cd_tipo": 33, "ds_contato": "(47)99185-0309" }
```

`PUT` recebe o mesmo corpo e substitui os dois campos. `cd_pessoa` nunca é aceito no payload.

Regras:

```
cd_tipo      required|integer|exists:unim_pessoa_contato_tipo,cd_tipo
ds_contato   required|string|max:255   + regra por tipo (abaixo)
```

`exists` não é decoração: sem ele um `cd_tipo` inexistente viola a FK, sai como SQLSTATE 23000 e o `DatabaseExceptionHandler` traduz para **409** — o mesmo status de duplicata, mandando quem investiga para o lado errado.

Regra por tipo, escolhida pela `ds_chave` do `cd_tipo` enviado:

| `ds_chave` | Exigência |
|---|---|
| `EMAIL` | formato de e-mail |
| `TELEFONE`, `TELEFONE-COMERCIAL`, `TELEFONE-CELULAR` | 10 ou 11 dígitos após remover tudo que não é dígito |
| `SITE` | URL |

É validação cruzada entre dois campos, então mora no `withValidator()->after()`, não na string de `rules()` — mesmo padrão de `ValidaDocumentosDePessoa` e `ValidaDatasDePessoa`. A limpeza da máscara serve **apenas** para contar dígitos; o valor persistido é o que veio.

### Os três caminhos do `POST`

| Trio `(cd_pessoa, ds_contato, cd_tipo)` | Resposta |
|---|---|
| inédito | **201**, linha nova |
| idêntico a linha **excluída** | **201**, linha revivida, `cd_contato` **antigo** |
| idêntico a linha **viva** | **409** com mensagem explícita |

### `PUT` e a mesma armadilha por outra porta

Mudar `ds_contato` ou `cd_tipo` pode formar um trio que já existe em outra linha da pessoa, **inclusive uma excluída**. A checagem é a mesma, ignorando a própria linha — exatamente como `PessoaRepository::loginExiste()` faz com `ignorarCdPessoa`.

Reativar pelo `PUT` não acontece: `PUT` altera a linha apontada pela URL. Colidir com uma excluída é 409, e o caminho para reviver é o `POST`.

### Status

`200`/`201` no sucesso; `401` sem token; `403` sem o par ACL; `404` pessoa inexistente, de outro cliente, ou contato que não é dela; `409` duplicata; `422` validação.

## Arquitetura

| Artefato | Caminho | Papel |
|---|---|---|
| `UnimPessoaContato` | `app/Model/Pessoa/` | `SoftDeletes`, `DELETED_AT = 'dt_excluido'`, `timestamps = false`, `belongsTo` do tipo |
| `ContatoRepositoryInterface` + `ContatoRepository` | `app/Repository/Pessoa/` | consultas e escrita, incluindo a busca `withTrashed()` do trio |
| `ContatoService` | `app/Service/Pessoa/` | reativação, duplicata, resolução da pessoa |
| `ContatoController` | `app/Controller/Pessoa/` | quatro actions |
| `CreateContatoRequest`, `UpdateContatoRequest` | `app/Request/Pessoa/` | |
| `ValidaContatoPorTipo` | `app/Request/Pessoa/Concerns/` | quarto trait da pasta |
| `ContatoResource` | `app/Resource/Pessoa/` | recorte campo a campo |
| `ContatoSchema` | `app/Swagger/` | |

O model do tipo (`UnimPessoaContatoTipo`) já existe em `app/Model/Dominio/`, criado na entrega 1.

`ContatoController` precisa de `#[OA\HyperfServer(name: 'http')]` na classe. Sem ele o `gen:swagger` publica o schema em `components` e **nenhum path** — falha silenciosa que a entrega 1 descobriu empiricamente.

### Uma guarda de tenant, reusada

`ContatoService` resolve a pessoa por `PessoaRepositoryInterface`, em vez de escrever o próprio `WHERE cd_cliente`. Uma segunda cópia do filtro de tenant é um segundo lugar para esquecer dele.

## Escopo de tenant

`unim_pessoa_contato` não tem `cd_cliente`, e `cd_contato` é `AUTO_INCREMENT` **global**: o valor 771 pertence a algum cliente, e não necessariamente ao que está chamando.

1. Resolver a pessoa primeiro, sempre com tenant: `WHERE cd_pessoa = ? AND cd_cliente = ? AND dt_excluido IS NULL`. Não achou → **404**, nunca 403 — 403 confirmaria que o `cd_pessoa` existe em outro cliente.
2. Nunca consultar `unim_pessoa_contato` só por `cd_pessoa`.
3. `PUT`/`DELETE` exigem `WHERE cd_contato = ? AND cd_pessoa = ?`. Sem isso, adivinhar um id edita contato de outro cliente **mesmo com a pessoa da URL correta**.
4. `cd_pessoa` nunca é aceito no payload. Só da URL.

## LGPD

Telefone e e-mail são dado pessoal. Aqui eles não passam pelo mecanismo de `sensivel` da entrega 1, porque esse mecanismo recorta a resposta de `/pessoas` por `?fields=` e estes contatos vivem num endpoint próprio: pedir a rota **é** o pedido explícito.

A proteção é o par ACL, que é o mesmo de `/pessoas`. Quem pode ler uma pessoa pode ler os contatos dela; nada nesta entrega estreita ou alarga isso.

Não há máscara na leitura. A API devolve o que está gravado, inclusive o que o legado gravou fora do formato.

## Documentação

Regra 1: atributos e artefato no mesmo commit, e a conferência é no `storage/swagger/http.json`.

Ditos explicitamente, porque nenhum deles é adivinhável:

- a reativação **reusa** o `cd_contato` de uma linha excluída, então o id não é imutável no tempo;
- o `UNIQUE` existe sobre todas as linhas, inclusive as excluídas;
- o texto é gravado como veio, então `(47)99185-0309` e `47991850309` são contatos **diferentes** para o índice;
- a regra de formato depende do `cd_tipo` enviado, e a mensagem de 422 diz qual regra falhou;
- não há `meta`, porque não pagina;
- a leitura devolve o legado como está, inclusive `EMAIL` que não é e-mail.

## Testes

Regra 4: teste vale pelo bug que pega.

| Teste | Falha que pega |
|---|---|
| excluir e recriar idêntico revive a **mesma** linha (`cd_contato` igual) | a reativação não acontecer e nascer linha nova |
| duplicata de linha **viva** → 409 com frase, não 23000 genérico | falta da checagem explícita |
| `PUT`/`DELETE` com `cd_contato` de **outra pessoa** → 404 | o vazamento pelo id global |
| pessoa de **outro cliente** → 404 em todas as quatro rotas | guarda de tenant |
| telefone mascarado → 201 e gravado **com** a máscara | normalização entrando por engano |
| `EMAIL` com CPF → 422; telefone de 3 dígitos → 422; `SITE` com texto solto → 422 | regra por tipo não aplicada |
| `cd_tipo` inexistente → **422**, não 409 | `exists` ausente, FK virando 23000 |
| `PUT` que colide com linha **excluída** → 409 | o `UNIQUE` ignorar soft delete |
| `DELETE` é soft: a linha permanece com `dt_excluido` preenchido | virar hard delete |
| `GET` não devolve excluídos | scope do `SoftDeletes` ausente |

Fora: contagem de tipos ou de linhas (dado volátil de produção), getters, e repetir pelo endpoint o que já está provado em unidade.

Testes usam `HyperfTest\Support\TenantDeTeste`; `cd_cliente = 1` e `cd_perfil = 1` não existem nesta base. A limpeza de `TenantDeTeste::limpar()` **precisa passar a apagar `unim_pessoa_contato`** antes de `unim_pessoa`, senão a FK `RESTRICT` bloqueia a limpeza da suíte.

## Escopo

**Entra:** as quatro rotas; model, repository, service, resource, requests e schema; validação por tipo; reativação; checagem de duplicata; expansão do rótulo do tipo; Swagger completo com `http.json` regenerado; a limpeza de contato em `TenantDeTeste`.

**Não entra:** `contatos` no `?fields=` de `/pessoas`; extensão `hasMany` do núcleo de seleção; paginação; normalização de máscara; correção de qualquer dado legado; endereço (entrega 2, bloqueada por `ALTER TABLE`); e o `sn_excluido`, que não existe para este projeto.

## Risco aceito

**Duplicata por formato continua possível.** `(47)99185-0309` e `47991850309` convivem como contatos distintos, porque o `UNIQUE` compara texto. É o comportamento de hoje; a API não piora nem melhora.

**A validação nova rejeita o que o legado aceitou.** Não há como cadastrar por esta API um `EMAIL` que não seja e-mail, embora existam 534 mil linhas onde alguns não são. Vale só para escrita nova.

**A leitura devolve lixo legado.** Normalizar na leitura faria a API mentir sobre o que está gravado.

**`cd_contato` deixa de ser imutável no tempo.** Um id revivido reaparece depois de ter sumido da listagem. É consequência direta da decisão 2, e está documentado no contrato.

**Telefone internacional não cabe na regra.** 10 ou 11 dígitos cobre o Brasil. Um número estrangeiro é rejeitado, e hoje não há caso conhecido na base — se aparecer, a regra precisa mudar antes do cadastro, não depois.
