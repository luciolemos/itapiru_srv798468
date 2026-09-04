# Deskhelper MVP

## Objetivo

O `deskhelper` sera uma aplicacao separada do `itapiru`, focada em cadastro de usuarios, aprovacao de acesso e tratamento basico de ocorrencias internas.

O MVP precisa entregar:

- cadastro de usuario com aprovacao manual
- login e sessao autenticada
- perfis de acesso simples
- abertura e acompanhamento de ocorrencias
- visao administrativa completa
- historico minimo de acoes

Nao entra no MVP:

- anexos
- SLA
- notificacoes por e-mail
- automacoes
- dashboard gerencial avancado
- base de conhecimento
- filas complexas por setor

## Perfis

Perfis previstos:

- `pending`
- `user`
- `operator`
- `admin`

Recorte do MVP:

- ativar `user` e `admin`
- manter `operator` previsto no modelo para fase seguinte
- usar `pending` como estado de conta, nao como role operacional

## Fluxos Principais

### 1. Cadastro

1. visitante acessa o formulario de inscricao
2. informa dados pessoais e senha
3. conta e criada com status `pending`
4. admin visualiza o cadastro pendente
5. admin aprova ou rejeita
6. ao aprovar, a conta passa para `active`

### 2. Login

1. usuario aprovado informa e-mail e senha
2. sistema valida credenciais
3. cria sessao autenticada
4. redireciona para o painel do `deskhelper`

### 3. Abertura de ocorrencia

1. usuario autenticado abre nova ocorrencia
2. informa titulo, categoria, prioridade e descricao
3. ticket nasce como `open`
4. sistema registra evento de criacao

### 4. Tratamento

1. admin lista todas as ocorrencias
2. altera status
3. atribui responsavel quando necessario
4. comenta na ocorrencia
5. sistema registra historico de cada mudanca

### 5. Acompanhamento

- `user` visualiza apenas tickets criados por ele
- `admin` visualiza todos os tickets

## Modulos do MVP

### Acesso

- cadastro
- login
- logout
- tela de aguardando aprovacao

### Usuarios

- listar pendentes
- aprovar usuario
- rejeitar usuario
- listar usuarios
- ativar/desativar usuario
- alterar role

### Tickets

- criar ticket
- listar meus tickets
- listar todos os tickets
- visualizar ticket
- comentar
- atribuir
- alterar status
- filtrar por status, categoria e prioridade

### Auditoria

- aprovacoes e rejeicoes de usuario
- mudancas de role
- criacao de ticket
- mudancas de status
- atribuicoes
- comentarios

## Regras de Negocio

### Usuarios

- e-mail deve ser unico
- usuario `pending` nao acessa o painel operacional
- usuario `disabled` nao faz login
- aprovacao e rejeicao sao acoes exclusivas de admin

### Tickets

- todo ticket tem criador
- todo ticket nasce com status `open`
- usuario comum so ve tickets proprios
- usuario comum nao altera atribuicao
- usuario comum nao fecha ticket
- admin pode editar qualquer ticket
- comentario atualiza `updated_at`

### Status

Estados do MVP:

- `open`
- `in_progress`
- `waiting_user`
- `resolved`
- `closed`
- `cancelled`

Regras:

- apenas admin fecha ticket no MVP
- ticket `closed` nao recebe edicao comum
- comentario do solicitante em `waiting_user` pode recolocar ticket em `open` ou `in_progress`

## Criterios de Aceite

O MVP estara pronto quando:

- usuario consegue se cadastrar
- admin consegue aprovar ou rejeitar cadastro
- usuario aprovado consegue fazer login
- usuario autenticado consegue abrir ticket
- usuario consegue listar e consultar os proprios tickets
- admin consegue listar todos os tickets
- admin consegue alterar status e atribuicao
- historico minimo fica registrado
- rotas protegidas ficam indisponiveis sem autenticacao

## Decisoes Fechadas Para Implementacao

Para reduzir retrabalho, este documento fixa o recorte inicial:

- sistema separado do `itapiru`
- autenticacao propria
- cadastro aberto com aprovacao manual
- categorias fixas
- sem anexos no MVP
- sem notificacao por e-mail no MVP
- com historico obrigatorio desde a primeira entrega
