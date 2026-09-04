# Deskhelper Mapa de Telas

## Publicas

### `/deskhelper`

Objetivo:

- apresentar o produto
- oferecer entrada para login e cadastro

Blocos:

- cabecalho simples
- resumo do que o sistema faz
- botao `Entrar`
- botao `Solicitar acesso`

### `/deskhelper/login`

Objetivo:

- autenticar usuario aprovado

Campos:

- e-mail
- senha

Acoes:

- entrar
- ir para cadastro

### `/deskhelper/register`

Objetivo:

- cadastrar novo usuario

Campos:

- nome completo
- e-mail
- posto/graducao
- setor
- telefone
- senha
- confirmar senha

Resultado:

- conta criada com status `pending`
- redirecionar para tela de aguardando aprovacao

### `/deskhelper/register/pending`

Objetivo:

- informar que o cadastro aguarda liberacao do admin

## Autenticadas

### `/deskhelper/app`

Objetivo:

- ser a landing page do usuario autenticado

Para `user`:

- resumo dos meus tickets
- atalhos para `Novo ticket` e `Minhas ocorrencias`

Para `admin`:

- totais simples
- pendencias de aprovacao
- atalhos para usuarios e tickets

### `/deskhelper/tickets`

Objetivo:

- listar tickets

Visao `user`:

- apenas tickets do proprio usuario

Visao `admin`:

- todos os tickets

Filtros do MVP:

- busca
- status
- categoria
- prioridade

### `/deskhelper/tickets/new`

Objetivo:

- abrir nova ocorrencia

Campos:

- titulo
- categoria
- prioridade
- descricao

### `/deskhelper/tickets/{id}`

Objetivo:

- visualizar detalhes
- comentar
- alterar status e atribuicao se admin

Blocos:

- cabecalho do ticket
- descricao
- timeline de eventos
- lista de comentarios
- formulario de comentario
- painel lateral de metadados

## Administracao

### `/deskhelper/admin`

Objetivo:

- painel administrativo simples

Blocos:

- usuarios pendentes
- tickets abertos
- tickets em andamento
- tickets aguardando usuario

### `/deskhelper/admin/users/pending`

Objetivo:

- aprovar e rejeitar cadastros

Colunas:

- nome
- e-mail
- setor
- data de cadastro
- acoes

### `/deskhelper/admin/users`

Objetivo:

- listar usuarios ativos e inativos

Filtros:

- status
- role
- busca

### `/deskhelper/admin/categories`

Objetivo:

- manter categorias fixas

Escopo inicial:

- listagem
- ativar/desativar
- ordenar

## Navegacao Recomendada

### Usuario comum

- Inicio
- Novo ticket
- Minhas ocorrencias
- Minha conta
- Sair

### Admin

- Painel
- Usuarios pendentes
- Usuarios
- Ocorrencias
- Categorias
- Minha conta
- Sair
