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

### Administração

- `/itapiru/login`
- `/itapiru/admin?entity=groups`
- `/itapiru/admin?entity=subgroups`
- `/itapiru/admin?entity=cards`

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
composer install
php -S 0.0.0.0:8081 -t public
```

Acesso local: `http://127.0.0.1:8081/itapiru`

## Operação do banco

- Banco: `var/data/itapiru.sqlite`
- Guia operacional: [docs/sqlite-operacao.md](/itapiru/readme-sqlite)

## Troubleshooting rápido

- **Rename de slug criou grupo novo**: conferir rota de update e recarregar admin com `Ctrl+F5`.
- **Não exclui subgrupo**: verificar cards vinculados.
- **Sidebar não refletiu criação**: validar persistência em `groups`/`sections` e recarregar página.
- **Formulário inválido**: abrir a tela correta (novo/editar) e reenviar.
