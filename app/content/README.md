# Guia de Conteúdo (Seed)

Este diretório contém o arquivo de seed inicial do itapiru.

## Sumário

1. [Arquivo principal](#arquivo-principal)
2. [Papel deste arquivo hoje](#papel-deste-arquivo-hoje)
3. [Estrutura esperada do seed](#estrutura-esperada-do-seed)
4. [Regras importantes](#regras-importantes)
5. [Observações de operação](#observações-de-operação)
6. [Onde o conteúdo é renderizado](#onde-o-conteúdo-é-renderizado)

## Arquivo principal

- `app/content/dashboard.php`

## Papel deste arquivo hoje

`dashboard.php` é usado para **inicialização do banco novo** (primeiro bootstrap).

- Se o SQLite ainda não existe, os dados iniciais são carregados a partir deste arquivo.
- Se o SQLite já existe, o conteúdo operacional passa a ser gerenciado pelo admin/frontend.

Em produção normal, grupos, subgrupos e cards devem ser mantidos via:

- `/itapiru/admin?entity=groups`
- `/itapiru/admin?entity=subgroups`
- `/itapiru/admin?entity=cards`

## Estrutura esperada do seed

O arquivo retorna um array com blocos como:

- `title`: título do painel
- `subtitle`: subtítulo
- `sections`: subgrupos iniciais (com grupo)
- `cardsBySection`: cards iniciais por subgrupo

Exemplo simplificado:

```php
return [
    'title' => 'Painel Público',
    'subtitle' => 'Painel público com cards dinâmicos por seção',
    'sections' => [
        'secao-1' => [
            'label' => '1ª Seção',
            'description' => 'Descrição da seção',
            'group' => 'Seções da OM',
            'order' => 1,
        ],
    ],
    'cardsBySection' => [
        'secao-1' => [
            [
                'title' => 'Sistema Interno',
                'href' => 'http://intranet.local',
                'status' => 'Interno',
                'description' => 'Acesso rápido',
                'order' => 1,
            ],
        ],
    ],
];
```

## Regras importantes

1. O slug de cada item em `sections` deve ser único.
2. As chaves de `cardsBySection` devem apontar para slugs existentes em `sections`.
3. O campo `group` em `sections` define o grupo inicial do subgrupo no menu em 2 níveis.
4. O status de card deve seguir os valores usados na UI (`Interno`, `Externo`, `Sistema`).

## Observações de operação

- Alterar este arquivo **não atualiza automaticamente** uma base já em uso.
- Para base existente, use o painel admin.
- Para ambiente novo, o seed é aplicado no primeiro bootstrap do repositório.

## Fluxo recomendado

1. Banco novo: inicializar aplicação (seed aplicado via `dashboard.php`).
2. Operação diária: administrar conteúdo no frontend (`/itapiru/admin`).
3. Ajustes estruturais: versionar mudanças no código e documentação.

## Onde o conteúdo é renderizado

- Home pública: `templates/dashboard-home.twig`
- Página de subgrupo/cards: `templates/dashboard.twig`
- Regras de roteamento e render: `app/routes.php`
- Persistência e bootstrap: `src/Infrastructure/Persistence/Dashboard/DashboardRepository.php`
