# Operação do banco SQLite

Persistência principal do itapiru:

- `/var/www/itapiru/var/data/itapiru.sqlite`

Observação: é um arquivo binário. Não editar como texto.

## Sumário

1. [Estrutura e regras atuais](#estrutura-e-regras-atuais)
2. [Abrir banco no terminal](#abrir-banco-no-terminal)
3. [Diagnóstico rápido](#diagnóstico-rápido)
4. [Operações de manutenção](#operações-de-manutenção)
5. [Renomeação de slug de grupo](#renomeação-de-slug-de-grupo-com-subgrupos)
6. [Verificar índices de unicidade](#verificar-índices-de-unicidade)
7. [Quando algo parecer inconsistente](#quando-algo-parecer-inconsistente)
8. [Fallback sem sqlite3](#fallback-sem-comando-sqlite3)

## Estrutura e regras atuais

Tabelas principais:

- `groups` (grupos)
- `sections` (subgrupos)
- `cards` (cards)
- `admins` (login admin)

Regras de integridade ativas:

- `groups.slug` é único.
- `groups.label` é único case-insensitive via índice `uq_groups_label_nocase`.
- Subgrupo sempre aponta para grupo existente (`sections.group_id`).
- Não exclui grupo com subgrupos vinculados.
- Não exclui subgrupo com cards vinculados.

## Abrir banco no terminal

```bash
cd /var/www/itapiru
sqlite3 var/data/itapiru.sqlite
```

Comandos úteis:

```sql
.tables
.schema groups
.schema sections
.schema cards
PRAGMA table_info(groups);
PRAGMA table_info(sections);
PRAGMA table_info(cards);
```

## Diagnóstico rápido

Grupos com quantidade de subgrupos:

```sql
SELECT g.id, g.slug, g.label, g.sort_order, COUNT(s.slug) AS subgroups
FROM groups g
LEFT JOIN sections s ON s.group_id = g.id
GROUP BY g.id, g.slug, g.label, g.sort_order
ORDER BY g.sort_order, g.id;
```

Subgrupos e grupo associado:

```sql
SELECT s.slug AS subgroup_slug, s.label AS subgroup_label, g.slug AS group_slug, g.label AS group_label
FROM sections s
LEFT JOIN groups g ON g.id = s.group_id
ORDER BY s.slug;
```

Cards por subgrupo:

```sql
SELECT s.slug, s.label, COUNT(c.id) AS cards
FROM sections s
LEFT JOIN cards c ON c.section_slug = s.slug
GROUP BY s.slug, s.label
ORDER BY s.slug;
```

## Operações de manutenção

### Backup

```bash
cd /var/www/itapiru
mkdir -p var/backups
cp var/data/itapiru.sqlite var/backups/itapiru-$(date +%F-%H%M%S).sqlite
```

### Restore

```bash
cd /var/www/itapiru
cp var/backups/itapiru-AAAA-MM-DD-HHMMSS.sqlite var/data/itapiru.sqlite
```

## Fluxo operacional recomendado

1. Fazer backup antes de qualquer ajuste manual no banco.
2. Rodar consultas de diagnóstico (grupos, subgrupos, cards).
3. Executar correção pontual.
4. Validar vínculos (`sections.group_id`) e contagens de cards.
5. Recarregar painel admin (`/itapiru/admin`, `Ctrl+F5`) e retestar fluxo funcional.

## Renomeação de slug de grupo (com subgrupos)

Regra da aplicação:

- O rename de grupo com subgrupos é suportado.
- Se o slug destino já existir, a lógica de serviço faz merge seguro de subgrupos no grupo alvo.

Validação pós-operação:

```sql
SELECT g.slug, COUNT(s.slug) AS subgroups
FROM groups g
LEFT JOIN sections s ON s.group_id = g.id
GROUP BY g.slug
ORDER BY g.slug;
```

## Verificar índices de unicidade

```sql
SELECT name, sql
FROM sqlite_master
WHERE type = 'index'
	AND tbl_name = 'groups'
ORDER BY name;
```

Deve existir o índice:

- `uq_groups_label_nocase`

## Quando algo parecer inconsistente

Checklist:

1. Fazer backup do `.sqlite`.
2. Confirmar duplicidade de grupos por `label` e por `slug`.
3. Confirmar vínculo `sections.group_id`.
4. Conferir contagem de cards por subgrupo antes de excluir.
5. Recarregar UI (`Ctrl+F5`) após operações administrativas.

## Fallback sem comando sqlite3

```bash
php -r '$pdo=new PDO("sqlite:/var/www/itapiru/var/data/itapiru.sqlite");foreach($pdo->query("SELECT name FROM sqlite_master WHERE type=\"table\" ORDER BY name") as $r){echo $r["name"],PHP_EOL;}'
```
