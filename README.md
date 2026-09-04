# Itapiru

O itapiru é um painel com sidebar dinâmico, no qual os menus e submenus são gerados e mantidos pelo próprio usuário admin diretamente no frontend. O objetivo do projeto é centralizar, em uma única experiência de navegação, cards com links para páginas web e sistemas de interesse operacional de cada seção da OM, com gestão simples e segura de conteúdo. A proposta é reduzir dispersão de acessos, padronizar a organização por contexto (grupo/subgrupo) e permitir evolução contínua do painel sem necessidade de edição manual de arquivos em produção. O sidebar de itapiru suporta menus em dois níveis apenas (menus/submenus). 

- Nível 1 do menu: Grupo (Menu)
- Nível 2 do menu: Subgrupo (Submenu)
- Conteúdo final: cards vinculados ao subgrupo

Depois que grupos e subgrupos são criados no admin, o próprio admin cria e mantém a visão de cards de cada subgrupo, sem edição manual de arquivo. Os slugs de grupos e subgrupos são únicos e podem ser renomeados. Os cards são identificados por ID único e podem ser editados/movidos entre subgrupos com validação de consistência.

## Sumário

1. [O que a aplicação faz](#o-que-a-aplicação-faz)
2. [Endpoints principais](#endpoints-principais)
3. [Menu em 2 níveis](#menu-em-2-níveis)
4. [Stack e persistência](#stack-e-persistência)
5. [Modelo de dados](#modelo-de-dados-resumo)
6. [Comportamento dos CRUDs](#comportamento-dos-cruds)
7. [Segurança do admin](#segurança-do-admin)
8. [Seed e bootstrap](#seed-e-bootstrap)
9. [Execução local](#rodar-localmente)
10. [Operação do banco](#operação-do-banco)
11. [Troubleshooting rápido](#troubleshooting-rápido)
12. [Solicitações e notificações](#solicitações-e-notificações)
13. [Execução e testes](#execução-e-testes)
14. [Planejamento do Deskhelper](#planejamento-do-deskhelper)

## O que a aplicação faz

- Exibe um painel público em `/itapiru`.
- Monta o menu lateral com hierarquia de 2 níveis (grupos e subgrupos).
- Exibe cards da seção ativa (`/itapiru/{subgroup_slug}`).
- Permite CRUD completo no admin para grupos, subgrupos e cards.
- Mantém integridade dos vínculos no banco para evitar inconsistências.
- Permite renomear slugs de grupos e subgrupos sem perder o vínculo estrutural.

## Endpoints principais

### Público

- `/itapiru`
- `/itapiru/{subgroup_slug}`
- `/itapiru/readme`
- `/itapiru/contato`
- `/itapiru/solicitar-card`

### Administração

- `/itapiru/login`
- `/itapiru/admin?entity=groups`
- `/itapiru/admin?entity=subgroups`
- `/itapiru/admin?entity=cards`
- `/itapiru/admin?entity=requests`

## Menu em 2 níveis

O sidebar é montado a partir de `groups` + `sections`.

- Criou um grupo: ele aparece no nível 1.
- Criou um subgrupo nesse grupo: ele aparece no nível 2 dentro do grupo.
- Renomeou slug de grupo: os subgrupos continuam vinculados e aparecem no grupo renomeado.
- A ordenação segue `sort_order`, com fallback por nome/slug.

## Stack e persistência

- PHP 8+
- Slim Framework 4
- Twig
- SQLite (`var/data/itapiru.sqlite`)

## Modelo de dados (resumo)

- `groups`: grupos do nível 1 (`slug`, `label`, `sort_order`)
- `sections`: subgrupos do nível 2 (`slug`, `label`, `description`, `group_id`, `sort_order`)
- `cards`: cards vinculados ao subgrupo (`section_slug`, metadados visuais e link)
- `admins`: autenticação do painel

Regras de integridade:

- `groups.slug` único
- `groups.label` único case-insensitive (`uq_groups_label_nocase`)
- subgrupo só é criado/atualizado com grupo existente
- não exclui grupo com subgrupos
- não exclui subgrupo com cards

## Comportamento dos CRUDs

### Grupos

- Create: `POST /itapiru/admin/groups/create`
- Update: `POST /itapiru/admin/groups/update`
- Delete: `POST /itapiru/admin/groups/delete`

Regras importantes:

- Renomear slug de grupo povoado é suportado.
- Se o slug destino já existir, o backend faz merge seguro de vínculos.

### Subgrupos

- Create: `POST /itapiru/admin/sections/create`
- Update: `POST /itapiru/admin/sections/update`
- Delete: `POST /itapiru/admin/sections/delete`

Regras importantes:

- Subgrupo sempre pertence a um grupo válido.
- Renomear slug de subgrupo atualiza os cards vinculados automaticamente.

### Cards

- Create: `POST /itapiru/admin/cards/create`
- Update: `POST /itapiru/admin/cards/update`
- Delete: `POST /itapiru/admin/cards/delete`

Regras importantes:

- No formulário, o select de subgrupo depende do grupo selecionado.
- A UI exibe apenas subgrupos do grupo escolhido.
- O backend valida a combinação grupo + subgrupo.
- Se houver combinação cruzada, o salvamento é bloqueado.

## Segurança do admin

- CSRF obrigatório em todos os `POST`.
- Guardas por origem de formulário (`_form`) para evitar envio em rota errada.
- Throttle de login com bloqueio temporário após tentativas inválidas.

## Seed e bootstrap

- Seed inicial: `app/content/dashboard.php`
- Seed executa apenas em banco novo.
- Bootstrap aplica migrações e consolidação para manter unicidade de grupos.

## Rodar localmente

```bash
cd /var/www/itapiru
cp .env.example .env
composer install
composer start
```

Acesso local: `http://127.0.0.1:8080`.

No servidor local, as rotas são atendidas na raiz: `/`, `/login` e `/admin`.
Em produção, quando publicado no subdiretório, elas são `/itapiru`,
`/itapiru/login` e `/itapiru/admin`.

## Rodar com Docker

O Docker de desenvolvimento usa PHP 8.4, `mbstring` e `pdo_sqlite`.

```bash
cd /var/www/itapiru
docker compose run --rm slim composer install
docker compose up --build
```

A aplicação ficará disponível em `http://127.0.0.1:8080`. O diretório do
projeto, incluindo `var/data/`, é montado no container; os dados SQLite locais
permanecem no host. Para encerrar, use `docker compose down`.

## Configuracao de ambiente

Copie `.env.example` para `.env` e ajuste somente o necessario.

- `APP_ENV=development` e apropriado para desenvolvimento local; no servidor publicado use `APP_ENV=production`, que desabilita a exibicao de detalhes de erro ao usuario.
- `APP_BASE_PATH` pode ficar vazio: a aplicacao detecta `/itapiru` quando esta publicada nesse subdiretorio. Defina-o apenas quando a deteccao nao for aplicavel.
- `APP_DB_PATH` e opcional. Vazio usa `var/data/itapiru.sqlite`; informe um caminho absoluto apenas quando o banco estiver em outro local.
- `ADMIN_USER` e `ADMIN_PASS` definem somente o administrador criado no primeiro bootstrap de um banco novo. Em producao, ambos sao obrigatorios nesse momento e `ADMIN_PASS` deve ter pelo menos 12 caracteres; nao alteram uma conta ja existente.

### Deploy seguro

`.env` nao faz parte do Git. No servidor publicado, a fonte protegida fica em
`/var/www/.itapiru-config/itapiru.env`, fora do diretorio de checkout. Apos cada
deploy que atualize o repositorio, execute:

```bash
cd /var/www/itapiru
./scripts/restore-env.sh
```

Configure esse comando como etapa posterior ao checkout no mecanismo de deploy.
Ele recria `.env` com permissao `640` e propriedade `luciolemos:www-data`, sem
exibir os valores. Antes do primeiro deploy, confirme que a fonte externa existe;
o script interrompe a operacao se ela estiver ausente em vez de iniciar o sistema
sem configuracao.

## Execução e testes

Instale as dependências PHP e JavaScript antes dos testes:

```bash
composer install
npm ci
```

Validações estáticas:

```bash
composer validate --no-check-publish
php vendor/phpstan/phpstan/phpstan analyse --configuration phpstan.neon.dist --no-progress
php vendor/squizlabs/php_codesniffer/bin/phpcs --standard=phpcs.xml --extensions=php -n src app tests
```

Testes PHP:

```bash
php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml
```

Os testes PHP usam `APP_BASE_PATH=/itapiru` para validar as URLs publicadas e
criam um SQLite temporário exclusivo em `/tmp`. O banco em `var/data/` não é
aberto nem alterado pela suíte.

Testes visuais:

```bash
npx playwright install chromium
npm run test:visual
```

As specs visuais atualmente usam URLs com o prefixo `/itapiru`, mas
`composer start` atende na raiz. Por isso, a suíte visual completa não é uma
validação local confiável até que a configuração e as specs usem o mesmo
prefixo. Para verificações locais focadas, inicie o servidor e execute uma
spec adaptada à rota local.

## Operação do banco

- Banco: `var/data/itapiru.sqlite`
- Guia operacional: [docs/sqlite-operacao.md](/itapiru/readme-sqlite)

## Troubleshooting rápido

- **Rename de slug criou grupo novo**: conferir rota de update e recarregar admin com `Ctrl+F5`.
- **Não exclui subgrupo**: verificar cards vinculados.
- **Sidebar não refletiu criação**: validar persistência em `groups`/`sections` e recarregar página.
- **Formulário inválido**: abrir a tela correta (novo/editar) e reenviar.

## Solicitações e notificações

- O histórico de solicitações é cumulativo: registros com status `pending`, `approved` e `rejected` permanecem salvos e não são apagados ao aprovar/rejeitar.
- O sino da topbar do admin exibe apenas as pendências mais recentes, com `LIMIT 5`.
- O contador do sino reflete o total real de solicitações pendentes no banco.

## Planejamento do Deskhelper

Os artefatos iniciais do `deskhelper` foram registrados em:

- [docs/deskhelper-mvp.md](/var/www/itapiru/docs/deskhelper-mvp.md)
- [docs/deskhelper-schema.sql](/var/www/itapiru/docs/deskhelper-schema.sql)
- [docs/deskhelper-backlog.md](/var/www/itapiru/docs/deskhelper-backlog.md)
- [docs/deskhelper-telas.md](/var/www/itapiru/docs/deskhelper-telas.md)
