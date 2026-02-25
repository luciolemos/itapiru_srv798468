<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Dashboard;

use PDO;

class DashboardRepository
{
    private PDO $pdo;

    public function __construct(private string $dbPath, private string $seedPath)
    {
        $this->bootstrap();
    }

    public function getMeta(): array
    {
        $stmt = $this->pdo->query('SELECT config_key, config_value FROM app_config');
        $meta = [
            'title' => 'Dashboard Público',
            'subtitle' => 'Painel público com cards dinâmicos por seção',
        ];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['config_key'] === 'title' || $row['config_key'] === 'subtitle') {
                $meta[$row['config_key']] = (string) $row['config_value'];
            }
        }

        return $meta;
    }

    public function getAllGroups(): array
    {
        $stmt = $this->pdo->query('SELECT g.id, g.slug, g.label, g.sort_order, COUNT(s.slug) AS subgroups_count
            FROM groups g
            LEFT JOIN sections s ON s.group_id = g.id
            GROUP BY g.id, g.slug, g.label, g.sort_order
            ORDER BY g.sort_order ASC, g.label ASC, g.slug ASC');

        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $groups[] = [
                'id' => (int) ($row['id'] ?? 0),
                'slug' => (string) ($row['slug'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'order' => (int) ($row['sort_order'] ?? 0),
                'subgroups_count' => (int) ($row['subgroups_count'] ?? 0),
            ];
        }

        return $groups;
    }

    public function getGroupsBySlug(): array
    {
        $groupsBySlug = [];
        foreach ($this->getAllGroups() as $group) {
            $groupsBySlug[(string) $group['slug']] = $group;
        }

        return $groupsBySlug;
    }

    public function countSubgroupsByGroupSlug(string $groupSlug): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sections s
            INNER JOIN groups g ON g.id = s.group_id
            WHERE g.slug = :slug');
        $stmt->execute(['slug' => $groupSlug]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function countCardsBySectionSlug(string $sectionSlug): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM cards WHERE section_slug = :slug');
        $stmt->execute(['slug' => $sectionSlug]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function createGroup(string $slug, string $label, int $sortOrder): void
    {
        $normalizedLabel = trim($label);
        if ($normalizedLabel === '') {
            $normalizedLabel = $slug;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO groups (slug, label, sort_order) VALUES (:slug, :label, :sort_order)'
        );

        $stmt->execute([
            'slug' => $slug,
            'label' => $normalizedLabel,
            'sort_order' => $sortOrder,
        ]);

        $this->syncLegacySectionGroupLabels();
    }

    public function updateGroup(string $originalSlug, string $newSlug, string $label, int $sortOrder): void
    {
        $normalizedOriginalSlug = $this->normalizeSlug($originalSlug, '');
        $normalizedNewSlug = $this->normalizeSlug($newSlug, '');

        if ($normalizedOriginalSlug === '' || $normalizedNewSlug === '') {
            throw new \RuntimeException('Slug inválido para atualização de grupo.');
        }

        $oldGroupId = $this->getGroupIdBySlug($normalizedOriginalSlug);
        if ($oldGroupId <= 0) {
            throw new \RuntimeException('Grupo original não encontrado.');
        }

        $targetGroupId = $this->getGroupIdBySlug($normalizedNewSlug);

        $this->pdo->beginTransaction();
        try {
            if ($targetGroupId > 0 && $targetGroupId !== $oldGroupId) {
                $stmt = $this->pdo->prepare('UPDATE sections SET group_id = :target_id WHERE group_id = :old_id');
                $stmt->execute([
                    'target_id' => $targetGroupId,
                    'old_id' => $oldGroupId,
                ]);

                $stmt = $this->pdo->prepare('UPDATE groups
                    SET label = :label, sort_order = :sort_order
                    WHERE id = :target_id');
                $stmt->execute([
                    'label' => $label,
                    'sort_order' => $sortOrder,
                    'target_id' => $targetGroupId,
                ]);

                $stmt = $this->pdo->prepare('DELETE FROM groups WHERE id = :old_id');
                $stmt->execute(['old_id' => $oldGroupId]);
            } else {
                $stmt = $this->pdo->prepare('UPDATE groups
                    SET slug = :new_slug, label = :label, sort_order = :sort_order
                    WHERE id = :old_id');
                $stmt->execute([
                    'new_slug' => $normalizedNewSlug,
                    'label' => $label,
                    'sort_order' => $sortOrder,
                    'old_id' => $oldGroupId,
                ]);
            }

            $this->syncLegacySectionGroupLabels();
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }
    }

    public function deleteGroup(string $slug): int
    {
        $subgroupsCount = $this->countSubgroupsByGroupSlug($slug);
        if ($subgroupsCount > 0) {
            throw new \RuntimeException('Não é possível excluir grupo com subgrupos vinculados.');
        }

        $stmt = $this->pdo->prepare('DELETE FROM groups WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);

        if ($stmt->rowCount() <= 0) {
            return 0;
        }

        return 1;
    }

    public function getSections(): array
    {
        $stmt = $this->pdo->query('SELECT s.slug, s.label, s.description, s.sort_order,
                COALESCE(g.label, "Geral") AS group_label,
                COALESCE(g.slug, "geral") AS group_slug
            FROM sections s
            LEFT JOIN groups g ON g.id = s.group_id
            ORDER BY g.sort_order ASC, g.label ASC, s.sort_order ASC, s.slug ASC');

        $sections = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sections[(string) $row['slug']] = [
                'label' => (string) ($row['label'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'group' => (string) ($row['group_label'] ?? 'Geral'),
                'group_slug' => (string) ($row['group_slug'] ?? 'geral'),
                'order' => (int) ($row['sort_order'] ?? 0),
            ];
        }

        return $sections;
    }

    public function getCardsBySection(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM cards ORDER BY section_slug ASC, sort_order ASC, id ASC');
        $cardsBySection = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $slug = (string) $row['section_slug'];
            if (!isset($cardsBySection[$slug])) {
                $cardsBySection[$slug] = [];
            }

            $cardsBySection[$slug][] = $this->hydrateCard($row);
        }

        return $cardsBySection;
    }

    public function getCardsForSection(string $sectionSlug): array
    {
        $stmt = $this->pdo->prepare('SELECT c.*, COALESCE(g.slug, "geral") AS group_slug, COALESCE(g.label, "Geral") AS group_label
            FROM cards c
            LEFT JOIN sections s ON s.slug = c.section_slug
            LEFT JOIN groups g ON g.id = s.group_id
            WHERE c.section_slug = :slug
            ORDER BY c.sort_order ASC, c.id ASC');
        $stmt->execute(['slug' => $sectionSlug]);

        return array_map(fn (array $row): array => $this->hydrateCard($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getAllCards(): array
    {
        $stmt = $this->pdo->query('SELECT c.*, COALESCE(g.slug, "geral") AS group_slug, COALESCE(g.label, "Geral") AS group_label,
            COALESCE(s.label, c.section_slug) AS section_label
            FROM cards c
            LEFT JOIN sections s ON s.slug = c.section_slug
            LEFT JOIN groups g ON g.id = s.group_id
            ORDER BY g.sort_order ASC, g.label ASC, c.section_slug ASC, c.sort_order ASC, c.id ASC');

        return array_map(fn (array $row): array => $this->hydrateCard($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function upsertSection(string $slug, string $label, string $description, string $groupSlug, int $sortOrder): void
    {
        $rawGroupReference = trim($groupSlug);
        $normalizedGroupSlug = $this->normalizeSlug($groupSlug, '');

        $groupId = 0;
        if ($normalizedGroupSlug !== '') {
            $groupId = $this->getGroupIdBySlug($normalizedGroupSlug);
        }

        if ($groupId <= 0 && $rawGroupReference !== '') {
            $groupId = $this->getGroupIdByLabel($rawGroupReference);
        }

        if ($groupId <= 0) {
            throw new \RuntimeException('Grupo inválido. Selecione um grupo existente antes de criar o subgrupo.');
        }

        $groupLabel = $this->getGroupLabelById($groupId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO sections (slug, label, description, group_id, group_label, sort_order)
             VALUES (:slug, :label, :description, :group_id, :group_label, :sort_order)
             ON CONFLICT(slug) DO UPDATE SET
                label = excluded.label,
                description = excluded.description,
                group_id = excluded.group_id,
                group_label = excluded.group_label,
                sort_order = excluded.sort_order'
        );

        $stmt->execute([
            'slug' => $slug,
            'label' => $label,
            'description' => $description,
            'group_id' => $groupId,
            'group_label' => $groupLabel,
            'sort_order' => $sortOrder,
        ]);
    }

    public function deleteSection(string $slug): void
    {
        $cardsCount = $this->countCardsBySectionSlug($slug);
        if ($cardsCount > 0) {
            throw new \RuntimeException('Não é possível excluir subgrupo com cards vinculados.');
        }

        $stmt = $this->pdo->prepare('DELETE FROM sections WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
    }

    public function renameGroupLabel(string $oldLabel, string $newLabel): int
    {
        $oldNormalized = trim($oldLabel);
        $newNormalized = trim($newLabel);

        if ($oldNormalized === '' || $newNormalized === '' || $oldNormalized === $newNormalized) {
            return 0;
        }

        $oldSlug = $this->normalizeSlug($oldNormalized, 'geral');
        $newSlug = $this->normalizeSlug($newNormalized, 'geral');
        $oldId = $this->getGroupIdBySlug($oldSlug);
        if ($oldId <= 0) {
            return 0;
        }

        $this->createGroup($newSlug, $newNormalized, 1);
        $newId = $this->getGroupIdBySlug($newSlug);
        if ($newId <= 0) {
            return 0;
        }

        $stmt = $this->pdo->prepare('UPDATE sections SET group_id = :new_group_id WHERE group_id = :old_group_id');
        $stmt->execute([
            'new_group_id' => $newId,
            'old_group_id' => $oldId,
        ]);

        $updated = $stmt->rowCount();

        $stmt = $this->pdo->prepare('DELETE FROM groups WHERE id = :id');
        $stmt->execute(['id' => $oldId]);

        $this->syncLegacySectionGroupLabels();

        return $updated;
    }

    public function renameSection(string $oldSlug, string $newSlug, string $label, string $description, string $groupSlug, int $sortOrder): void
    {
        if ($oldSlug === $newSlug) {
            $this->upsertSection($newSlug, $label, $description, $groupSlug, $sortOrder);
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $this->upsertSection($newSlug, $label, $description, $groupSlug, $sortOrder);

            $stmt = $this->pdo->prepare('UPDATE cards SET section_slug = :new_slug WHERE section_slug = :old_slug');
            $stmt->execute([
                'new_slug' => $newSlug,
                'old_slug' => $oldSlug,
            ]);

            $stmt = $this->pdo->prepare('DELETE FROM sections WHERE slug = :slug');
            $stmt->execute(['slug' => $oldSlug]);

            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }
    }

    public function createCard(array $card): void
    {
        $sectionSlug = $this->resolveSectionSlugForCard($card);

        $stmt = $this->pdo->prepare(
            'INSERT INTO cards (section_slug, title, href, external, icon, status, metric, trend, description, sort_order)
             VALUES (:section_slug, :title, :href, :external, :icon, :status, :metric, :trend, :description, :sort_order)'
        );

        $stmt->execute([
            'section_slug' => $sectionSlug,
            'title' => $card['title'],
            'href' => $card['href'],
            'external' => $card['external'] ? 1 : 0,
            'icon' => $this->normalizeIcon((string) ($card['icon'] ?? 'bi-globe2')),
            'status' => $card['status'],
            'metric' => $card['metric'],
            'trend' => $card['trend'],
            'description' => $card['description'],
            'sort_order' => $card['order'],
        ]);
    }

    public function updateCard(int $id, array $card): void
    {
        $sectionSlug = $this->resolveSectionSlugForCard($card);

        $stmt = $this->pdo->prepare(
            'UPDATE cards SET section_slug = :section_slug, title = :title, href = :href, external = :external,
             icon = :icon, status = :status, metric = :metric, trend = :trend, description = :description,
             sort_order = :sort_order WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'section_slug' => $sectionSlug,
            'title' => $card['title'],
            'href' => $card['href'],
            'external' => $card['external'] ? 1 : 0,
            'icon' => $this->normalizeIcon((string) ($card['icon'] ?? 'bi-globe2')),
            'status' => $card['status'],
            'metric' => $card['metric'],
            'trend' => $card['trend'],
            'description' => $card['description'],
            'sort_order' => $card['order'],
        ]);
    }

    public function deleteCard(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM cards WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function verifyAdmin(string $username, string $password): bool
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM admins WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $hash = $stmt->fetchColumn();

        if (!is_string($hash) || $hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    private function bootstrap(): void
    {
        $isNewDatabase = !is_file($this->dbPath);

        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->createSchema();
        $this->ensureSectionsGroupColumn();
        $this->ensureUniqueGroupLabels();
        $this->seedIfEmpty($isNewDatabase);
        $this->ensureDefaultAdmin();
        $this->syncLegacySectionGroupLabels();
    }

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS app_config (config_key TEXT PRIMARY KEY, config_value TEXT NOT NULL)');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            label TEXT NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS sections (
            slug TEXT PRIMARY KEY,
            label TEXT NOT NULL,
            description TEXT NOT NULL,
            group_label TEXT NOT NULL DEFAULT "Geral",
            group_id INTEGER,
            sort_order INTEGER NOT NULL DEFAULT 0
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS cards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            section_slug TEXT NOT NULL,
            title TEXT NOT NULL,
            href TEXT NOT NULL DEFAULT "#",
            external INTEGER NOT NULL DEFAULT 0,
            icon TEXT NOT NULL DEFAULT "bi-globe2",
            status TEXT NOT NULL DEFAULT "Interno",
            metric TEXT NOT NULL DEFAULT "",
            trend TEXT NOT NULL DEFAULT "",
            description TEXT NOT NULL DEFAULT "",
            sort_order INTEGER NOT NULL DEFAULT 0
        )');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_groups_sort ON groups(sort_order)');
    }

    private function ensureUniqueGroupLabels(): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('UPDATE groups SET label = TRIM(label)');
            $this->pdo->exec('UPDATE groups SET label = slug WHERE TRIM(COALESCE(label, "")) = ""');

            $groups = $this->pdo->query('SELECT g.id, g.slug, g.label, g.sort_order, COUNT(s.slug) AS subgroups_count
                FROM groups g
                LEFT JOIN sections s ON s.group_id = g.id
                GROUP BY g.id, g.slug, g.label, g.sort_order
                ORDER BY g.id ASC')->fetchAll(PDO::FETCH_ASSOC);

            $groupsByLabel = [];
            foreach ($groups as $group) {
                $label = trim((string) ($group['label'] ?? ''));
                $labelKey = function_exists('mb_strtolower') ? mb_strtolower($label) : strtolower($label);
                if ($labelKey === '') {
                    $labelKey = '__empty__';
                }

                if (!isset($groupsByLabel[$labelKey])) {
                    $groupsByLabel[$labelKey] = [];
                }

                $groupsByLabel[$labelKey][] = [
                    'id' => (int) ($group['id'] ?? 0),
                    'slug' => (string) ($group['slug'] ?? ''),
                    'sort_order' => (int) ($group['sort_order'] ?? 99),
                    'subgroups_count' => (int) ($group['subgroups_count'] ?? 0),
                ];
            }

            foreach ($groupsByLabel as $duplicates) {
                if (count($duplicates) <= 1) {
                    continue;
                }

                usort($duplicates, static function (array $left, array $right): int {
                    if ($left['subgroups_count'] !== $right['subgroups_count']) {
                        return $right['subgroups_count'] <=> $left['subgroups_count'];
                    }

                    if ($left['sort_order'] !== $right['sort_order']) {
                        return $left['sort_order'] <=> $right['sort_order'];
                    }

                    return $left['id'] <=> $right['id'];
                });

                $canonicalId = (int) ($duplicates[0]['id'] ?? 0);
                if ($canonicalId <= 0) {
                    continue;
                }

                foreach (array_slice($duplicates, 1) as $duplicate) {
                    $duplicateId = (int) ($duplicate['id'] ?? 0);
                    if ($duplicateId <= 0 || $duplicateId === $canonicalId) {
                        continue;
                    }

                    $stmt = $this->pdo->prepare('UPDATE sections SET group_id = :canonical_id WHERE group_id = :duplicate_id');
                    $stmt->execute([
                        'canonical_id' => $canonicalId,
                        'duplicate_id' => $duplicateId,
                    ]);

                    $stmt = $this->pdo->prepare('DELETE FROM groups WHERE id = :id');
                    $stmt->execute(['id' => $duplicateId]);
                }
            }

            $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_groups_label_nocase ON groups(label COLLATE NOCASE)');
            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }
    }

    private function ensureSectionsGroupColumn(): void
    {
        $columns = $this->pdo->query('PRAGMA table_info(sections)')->fetchAll(PDO::FETCH_ASSOC);
        $hasGroupLabel = false;
        $hasGroupId = false;

        foreach ($columns as $column) {
            $columnName = (string) ($column['name'] ?? '');
            if ($columnName === 'group_label') {
                $hasGroupLabel = true;
            }
            if ($columnName === 'group_id') {
                $hasGroupId = true;
            }
        }

        if (!$hasGroupLabel) {
            $this->pdo->exec('ALTER TABLE sections ADD COLUMN group_label TEXT NOT NULL DEFAULT "Geral"');
        }

        if (!$hasGroupId) {
            $this->pdo->exec('ALTER TABLE sections ADD COLUMN group_id INTEGER');
        }

        $this->pdo->exec('UPDATE sections SET group_label = "Geral" WHERE TRIM(COALESCE(group_label, "")) = ""');

        $rows = $this->pdo->query('SELECT slug, group_label FROM sections')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $groupLabel = trim((string) ($row['group_label'] ?? ''));
            if ($groupLabel === '') {
                $groupLabel = 'Geral';
            }

            $groupSlug = $this->normalizeSlug($groupLabel, 'geral');
            $groupId = $this->getOrCreateGroupIdBySlug($groupSlug, $groupLabel, 1);

            $stmt = $this->pdo->prepare('UPDATE sections SET group_id = :group_id WHERE slug = :slug');
            $stmt->execute([
                'group_id' => $groupId,
                'slug' => (string) ($row['slug'] ?? ''),
            ]);
        }

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_sections_group_id ON sections(group_id)');
    }

    private function seedIfEmpty(bool $isNewDatabase): void
    {
        if (!$isNewDatabase) {
            return;
        }

        $sectionCount = (int) $this->pdo->query('SELECT COUNT(*) FROM sections')->fetchColumn();
        if ($sectionCount > 0 || !is_file($this->seedPath)) {
            return;
        }

        $seed = require $this->seedPath;
        if (!is_array($seed)) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $title = (string) ($seed['title'] ?? 'Dashboard Público');
            $subtitle = (string) ($seed['subtitle'] ?? 'Painel público com cards dinâmicos por seção');
            $cfg = $this->pdo->prepare('INSERT INTO app_config (config_key, config_value) VALUES (:k, :v) ON CONFLICT(config_key) DO UPDATE SET config_value = excluded.config_value');
            $cfg->execute(['k' => 'title', 'v' => $title]);
            $cfg->execute(['k' => 'subtitle', 'v' => $subtitle]);

            $order = 1;
            foreach (($seed['sections'] ?? []) as $slug => $section) {
                $groupLabel = trim((string) ($section['group'] ?? 'Geral'));
                if ($groupLabel === '') {
                    $groupLabel = 'Geral';
                }

                $groupSlug = $this->normalizeSlug($groupLabel, 'geral');
                $this->createGroup($groupSlug, $groupLabel, $order);

                $this->upsertSection(
                    (string) $slug,
                    (string) ($section['label'] ?? $slug),
                    (string) ($section['description'] ?? ''),
                    $groupSlug,
                    $order++
                );
            }

            foreach (($seed['cardsBySection'] ?? []) as $sectionSlug => $cards) {
                foreach ((array) $cards as $card) {
                    if (!is_array($card)) {
                        continue;
                    }

                    $this->createCard([
                        'section_slug' => (string) $sectionSlug,
                        'title' => (string) ($card['title'] ?? ''),
                        'href' => trim((string) ($card['href'] ?? '#')) === '' ? '#' : (string) ($card['href'] ?? '#'),
                        'external' => (bool) ($card['external'] ?? false),
                        'icon' => (string) ($card['icon'] ?? 'bi-globe2'),
                        'status' => (string) ($card['status'] ?? 'Interno'),
                        'metric' => (string) ($card['metric'] ?? ''),
                        'trend' => (string) ($card['trend'] ?? ''),
                        'description' => (string) ($card['description'] ?? ''),
                        'order' => (int) ($card['order'] ?? 0),
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $throwable) {
            $this->pdo->rollBack();
            throw $throwable;
        }
    }

    private function ensureDefaultAdmin(): void
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $username = trim((string) ($_ENV['ADMIN_USER'] ?? 'admin'));
        $password = (string) ($_ENV['ADMIN_PASS'] ?? 'admin123');

        $stmt = $this->pdo->prepare('INSERT INTO admins (username, password_hash, created_at) VALUES (:username, :password_hash, :created_at)');
        $stmt->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function hydrateCard(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'section_slug' => (string) ($row['section_slug'] ?? ''),
            'group_slug' => (string) ($row['group_slug'] ?? ''),
            'group_label' => (string) ($row['group_label'] ?? ''),
            'section_label' => (string) ($row['section_label'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'href' => (string) ($row['href'] ?? '#'),
            'external' => (int) ($row['external'] ?? 0) === 1,
            'icon' => $this->normalizeIcon((string) ($row['icon'] ?? 'bi-globe2')),
            'status' => (string) ($row['status'] ?? 'Interno'),
            'metric' => (string) ($row['metric'] ?? ''),
            'trend' => (string) ($row['trend'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'order' => (int) ($row['sort_order'] ?? 0),
        ];
    }

    private function resolveSectionSlugForCard(array $card): string
    {
        $subgroupSlug = strtolower(trim((string) ($card['subgroup_slug'] ?? '')));
        if ($subgroupSlug === '') {
            $subgroupSlug = strtolower(trim((string) ($card['section_slug'] ?? '')));
        }

        return $subgroupSlug !== '' ? $subgroupSlug : 'geral';
    }

    private function normalizeIcon(string $icon): string
    {
        $value = trim($icon);
        if ($value === '') {
            return 'bi-globe2';
        }

        return match ($value) {
            '🌐' => 'bi-globe2',
            '📌' => 'bi-pin-angle',
            '⚓' => 'bi-life-preserver',
            '✈️' => 'bi-airplane',
            '🛡️' => 'bi-shield',
            '🎖️' => 'bi-award',
            default => $value,
        };
    }

    private function normalizeSlug(string $value, string $fallback): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : $fallback;
    }

    private function getGroupIdBySlug(string $slug): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM groups WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function getGroupLabelById(int $groupId): string
    {
        if ($groupId <= 0) {
            return 'Geral';
        }

        $stmt = $this->pdo->prepare('SELECT label FROM groups WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $groupId]);
        $label = $stmt->fetchColumn();

        if (!is_string($label) || trim($label) === '') {
            return 'Geral';
        }

        return trim($label);
    }

    private function getGroupIdByLabel(string $label): int
    {
        $normalized = trim($label);
        if ($normalized === '') {
            return 0;
        }

        $stmt = $this->pdo->prepare('SELECT id FROM groups WHERE TRIM(label) = :label COLLATE NOCASE LIMIT 1');
        $stmt->execute(['label' => $normalized]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function getOrCreateGroupIdBySlug(string $slug, string $label, int $sortOrder): int
    {
        $groupId = $this->getGroupIdBySlug($slug);
        if ($groupId > 0) {
            return $groupId;
        }

        $normalizedLabel = trim($label) !== '' ? trim($label) : 'Geral';
        $this->createGroup($slug, $normalizedLabel, $sortOrder);

        return $this->getGroupIdBySlug($slug);
    }

    private function syncLegacySectionGroupLabels(): void
    {
        $this->pdo->exec('UPDATE sections
            SET group_label = COALESCE((
                SELECT g.label FROM groups g WHERE g.id = sections.group_id
            ), "Geral")');
    }
}
