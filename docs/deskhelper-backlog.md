# Deskhelper Backlog Tecnico

## Fase 0. Fundacao

- criar app `deskhelper` como dominio separado, com `.env`, base path e banco proprios
- definir nome, branding minimo e navegacao inicial
- criar migracao inicial a partir de [deskhelper-schema.sql](/var/www/itapiru/docs/deskhelper-schema.sql)
- criar seed tecnico minimo para roles e categorias padrao
- adicionar estrutura de testes para autenticacao e tickets

## Fase 1. Acesso e Usuarios

### Entregas

- formulario de cadastro
- login/logout
- sessao autenticada
- tela de aguardando aprovacao
- admin lista pendentes
- admin aprova/rejeita
- admin lista usuarios

### Tarefas tecnicas

- criar repositorio de usuarios
- criar servico de senha com `password_hash`
- criar middleware de autenticacao
- criar middleware de autorizacao por role
- criar protecao CSRF nos formularios
- criar eventos de auditoria de aprovacao, rejeicao e troca de role

### Testes minimos

- cadastro cria usuario `pending`
- usuario `pending` nao acessa o painel
- usuario aprovado faz login
- usuario rejeitado nao faz login
- admin aprova e rejeita sem quebrar sessao

## Fase 2. Tickets MVP

### Entregas

- criar ticket
- listar meus tickets
- listar todos os tickets para admin
- visualizar ticket
- comentar em ticket
- alterar status
- atribuir ticket

### Tarefas tecnicas

- criar repositorio de tickets
- criar servico para transicao de status
- registrar eventos em `ticket_events`
- atualizar `updated_at`, `resolved_at` e `closed_at`
- validar acesso por dono do ticket ou admin

### Testes minimos

- usuario cria ticket
- usuario so visualiza tickets proprios
- admin visualiza todos
- admin altera status
- admin atribui ticket
- comentario gera evento

## Fase 3. Operacao Inicial

### Entregas

- categorias fixas
- filtros por status, categoria e prioridade
- ordenacao por atualizacao
- painel admin com totais simples

### Tarefas tecnicas

- consultas paginadas
- filtros server-side
- seeds de categorias
- validacao de categorias ativas

### Testes minimos

- filtro retorna apenas tickets esperados
- categoria inativa nao pode ser usada em novo ticket

## Fase 4. Endurecimento

- politicas de sessao
- throttle de login
- mensagens de erro consistentes
- logs operacionais
- revisao de permissao em todas as rotas
- smoke tests de fluxos principais

## Ordem Recomendada de Implementacao

1. migracao inicial + repositorios
2. cadastro e login
3. aprovacao de usuario
4. CRUD minimo de tickets
5. comentarios e historico
6. filtros admin
7. endurecimento de seguranca

## Fora do MVP

- anexos
- e-mail
- SLA
- automacoes
- base de conhecimento
- regras avancadas de atribuicao
- multi-fila por setor
