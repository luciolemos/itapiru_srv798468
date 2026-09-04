<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Dashboard\DashboardRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return static function (App $app, callable $isValidCsrf, callable $resolveOriginalSlugFromReferer, callable $normalizeHref): void {
    $app->post('/admin/groups/create', function (Request $request, Response $response) use ($app, $isValidCsrf, $resolveOriginalSlugFromReferer) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', app_url('/login'))->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];

        if ((string) ($data['_form'] ?? '') !== 'group_create') {
            $_SESSION['admin_flash'] = 'Formulário inválido para criação de grupo. Recarregue a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=groups&mode=new'))->withStatus(302);
        }

        $originalSlug = strtolower(trim((string) ($data['original_slug'] ?? '')));
        if ($originalSlug === '') {
            $originalSlug = $resolveOriginalSlugFromReferer($request, 'groups');
        }

        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug) ?? '';
        $slug = trim((string) preg_replace('/-+/', '-', $slug), '-');

        if ($slug === '') {
            $_SESSION['admin_flash'] = 'Informe um slug válido para criar o grupo.';
            return $response->withHeader('Location', app_url('/admin?entity=groups&mode=new'))->withStatus(302);
        }

        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            $label = $slug;
        }

        $existing = $repo->getGroupsBySlug();
        $allGroups = $repo->getAllGroups();

        $referer = trim($request->getHeaderLine('Referer'));
        $refererQuery = parse_url($referer, PHP_URL_QUERY);
        $refererParams = [];
        if (is_string($refererQuery) && $refererQuery !== '') {
            parse_str($refererQuery, $refererParams);
        }

        $refererEntity = strtolower(trim((string) ($refererParams['entity'] ?? '')));
        $refererMode = strtolower(trim((string) ($refererParams['mode'] ?? '')));
        if ($originalSlug === '' && $refererEntity === 'groups' && $refererMode === 'edit') {
            $_SESSION['admin_flash'] = 'Ação de edição detectada em rota de criação. Reabra a tela de edição e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
        }

        $normalizedLabel = function_exists('mb_strtolower')
            ? mb_strtolower(trim($label))
            : strtolower(trim($label));

        $sameLabelGroups = array_values(array_filter($allGroups, static function (array $group) use ($normalizedLabel): bool {
            $groupLabel = trim((string) ($group['label'] ?? ''));
            $groupLabel = function_exists('mb_strtolower') ? mb_strtolower($groupLabel) : strtolower($groupLabel);

            return $normalizedLabel !== '' && $groupLabel === $normalizedLabel;
        }));

        if ($originalSlug !== '' && isset($existing[$originalSlug])) {
            $repo->updateGroup($originalSlug, $slug, $label, max(1, (int) ($data['order'] ?? 99)));
            $_SESSION['admin_flash'] = 'Grupo atualizado com sucesso.';

            return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
        }

        if ($originalSlug === '' && count($sameLabelGroups) > 0) {
            $_SESSION['admin_flash'] = 'Já existe um grupo com esse nome. Use editar no grupo existente para renomear o slug.';
            return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
        }

        if (isset($existing[$slug])) {
            $_SESSION['admin_flash'] = sprintf('Já existe um grupo com slug "%s".', $slug);
            return $response->withHeader('Location', app_url('/admin?entity=groups&mode=new'))->withStatus(302);
        }

        $repo->createGroup($slug, $label, max(1, (int) ($data['order'] ?? 99)));
        $_SESSION['admin_flash'] = 'Grupo criado com sucesso.';

        return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
    });

    $app->post('/admin/groups/update', function (Request $request, Response $response) use ($app, $isValidCsrf) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', app_url('/login'))->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];

        if ((string) ($data['_form'] ?? '') !== 'group_update') {
            $_SESSION['admin_flash'] = 'Formulário inválido para atualização de grupo. Recarregue a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
        }

        $originalSlug = strtolower(trim((string) ($data['original_slug'] ?? '')));
        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug) ?? '';
        $slug = trim((string) preg_replace('/-+/', '-', $slug), '-');

        if ($originalSlug === '' || $slug === '') {
            $_SESSION['admin_flash'] = 'Informe um slug válido para atualizar o grupo.';
            return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
        }

        $groups = $repo->getGroupsBySlug();
        if (!isset($groups[$originalSlug])) {
            $_SESSION['admin_flash'] = 'Grupo original não encontrado.';
            return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
        }

        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            $label = $slug;
        }

        $repo->updateGroup($originalSlug, $slug, $label, max(1, (int) ($data['order'] ?? 99)));
        $_SESSION['admin_flash'] = 'Grupo atualizado com sucesso.';

        return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
    });

    $app->post('/admin/groups/delete', function (Request $request, Response $response) use ($app, $isValidCsrf) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', app_url('/login'))->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];

        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        if ($slug !== '') {
            $subgroupsCount = $repo->countSubgroupsByGroupSlug($slug);
            if ($subgroupsCount > 0) {
                $_SESSION['admin_flash'] = sprintf('Não é possível excluir o grupo. Existem %d subgrupo(s) vinculado(s).', $subgroupsCount);
                return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
            }

            $repo->deleteGroup($slug);
            $_SESSION['admin_flash'] = 'Grupo removido com sucesso.';
        }

        return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
    });

    $app->post('/admin/groups/visibility', function (Request $request, Response $response) use ($app, $isValidCsrf) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', app_url('/login'))->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];
        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        $groups = $repo->getGroupsBySlug();

        if ($slug === '' || !isset($groups[$slug])) {
            $_SESSION['admin_flash'] = 'Grupo não encontrado.';
            return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
        }

        $isAdminOnly = (string) ($groups[$slug]['visibility'] ?? 'public') === 'admin';
        $repo->setGroupVisibility($slug, $isAdminOnly ? 'public' : 'admin');
        $_SESSION['admin_flash'] = $isAdminOnly
            ? 'Grupo definido como público.'
            : 'Grupo visível somente para administradores.';

        return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
    });

    $app->post('/admin/sections/create', function (Request $request, Response $response) use ($app, $isValidCsrf, $resolveOriginalSlugFromReferer) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', app_url('/login'))->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=subgroups'))->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];

        if ((string) ($data['_form'] ?? '') !== 'subgroup_create') {
            $_SESSION['admin_flash'] = 'Formulário inválido para criação de subgrupo. Recarregue a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=subgroups&mode=new'))->withStatus(302);
        }

        $rawSlug = trim((string) ($data['slug'] ?? ''));
        $originalSlug = strtolower(trim((string) ($data['original_slug'] ?? '')));
        if ($originalSlug === '') {
            $originalSlug = $resolveOriginalSlugFromReferer($request, 'subgroups');
        }
        $slug = strtolower($rawSlug);
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            $_SESSION['admin_flash'] = 'Informe um slug válido para criar a seção.';
            return $response->withHeader('Location', app_url('/admin?entity=subgroups&mode=new'))->withStatus(302);
        }

        $groupSlug = strtolower(trim((string) ($data['group_slug'] ?? '')));
        $groupSlug = preg_replace('/[^a-z0-9\-]/', '-', $groupSlug) ?? '';
        $groupSlug = trim((string) preg_replace('/-+/', '-', $groupSlug), '-');
        if ($groupSlug === '') {
            $_SESSION['admin_flash'] = 'Selecione um grupo válido para o subgrupo.';
            return $response->withHeader('Location', app_url('/admin?entity=subgroups&mode=new'))->withStatus(302);
        }

        $groups = $repo->getGroupsBySlug();
        if (!isset($groups[$groupSlug])) {
            $_SESSION['admin_flash'] = 'Grupo selecionado não existe mais. Atualize a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=subgroups&mode=new'))->withStatus(302);
        }

        $sections = $repo->getSections();
        if ($originalSlug !== '' && isset($sections[$originalSlug])) {
            if ($slug !== $originalSlug && isset($sections[$slug])) {
                $_SESSION['admin_flash'] = sprintf('Já existe uma seção com slug "%s".', $slug);
                return $response->withHeader('Location', app_url('/admin?entity=subgroups&mode=edit&slug=' . rawurlencode($originalSlug)))->withStatus(302);
            }

            $repo->renameSection(
                $originalSlug,
                $slug,
                trim((string) ($data['label'] ?? $slug)),
                trim((string) ($data['description'] ?? '')),
                $groupSlug,
                max(1, (int) ($data['order'] ?? 99))
            );
            $_SESSION['admin_flash'] = 'Subgrupo atualizado com sucesso.';

            return $response->withHeader('Location', app_url('/admin?entity=subgroups'))->withStatus(302);
        }

        if (isset($sections[$slug])) {
            $_SESSION['admin_flash'] = sprintf('Já existe uma seção com slug "%s".', $slug);
            return $response->withHeader('Location', app_url('/admin?entity=subgroups&mode=new'))->withStatus(302);
        }

        $repo->upsertSection(
            $slug,
            trim((string) ($data['label'] ?? $slug)),
            trim((string) ($data['description'] ?? '')),
            $groupSlug,
            max(1, (int) ($data['order'] ?? 99))
        );
        $_SESSION['admin_flash'] = 'Subgrupo criado com sucesso.';

        return $response->withHeader('Location', app_url('/admin?entity=subgroups'))->withStatus(302);
    });

    $app->post('/admin/sections/update', function (Request $request, Response $response) use ($app, $isValidCsrf) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', app_url('/login'))->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=subgroups'))->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];

        $originalSlug = strtolower(trim((string) ($data['original_slug'] ?? '')));
        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($originalSlug === '' || $slug === '') {
            $_SESSION['admin_flash'] = 'Informe um slug válido para atualizar a seção.';
            return $response->withHeader('Location', app_url('/admin?entity=subgroups'))->withStatus(302);
        }

        $sections = $repo->getSections();
        if (!isset($sections[$originalSlug])) {
            $_SESSION['admin_flash'] = 'Seção original não encontrada.';
            return $response->withHeader('Location', app_url('/admin?entity=subgroups'))->withStatus(302);
        }

        if ($slug !== $originalSlug && isset($sections[$slug])) {
            $_SESSION['admin_flash'] = sprintf('Já existe uma seção com slug "%s".', $slug);
            return $response->withHeader('Location', app_url('/admin?entity=subgroups&mode=edit&slug=' . rawurlencode($originalSlug)))->withStatus(302);
        }

        $groupSlug = strtolower(trim((string) ($data['group_slug'] ?? '')));
        $groupSlug = preg_replace('/[^a-z0-9\-]/', '-', $groupSlug) ?? '';
        $groupSlug = trim((string) preg_replace('/-+/', '-', $groupSlug), '-');
        if ($groupSlug === '') {
            $_SESSION['admin_flash'] = 'Selecione um grupo válido para o subgrupo.';
            return $response->withHeader('Location', app_url('/admin?entity=subgroups&mode=edit&slug=' . rawurlencode($originalSlug)))->withStatus(302);
        }

        $groups = $repo->getGroupsBySlug();
        if (!isset($groups[$groupSlug])) {
            $_SESSION['admin_flash'] = 'Grupo selecionado não existe mais. Atualize a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=subgroups&mode=edit&slug=' . rawurlencode($originalSlug)))->withStatus(302);
        }

        $repo->renameSection(
            $originalSlug,
            $slug,
            trim((string) ($data['label'] ?? $slug)),
            trim((string) ($data['description'] ?? '')),
            $groupSlug,
            max(1, (int) ($data['order'] ?? 99))
        );
        $_SESSION['admin_flash'] = 'Subgrupo atualizado com sucesso.';

        return $response->withHeader('Location', app_url('/admin?entity=subgroups'))->withStatus(302);
    });

    $app->post('/admin/sections/rename-group', function (Request $request, Response $response) use ($isValidCsrf) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', app_url('/login'))->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
        }

        $_SESSION['admin_flash'] = 'Fluxo antigo de renomear grupo foi desativado. Use editar grupo em Admin > Grupos.';
        return $response->withHeader('Location', app_url('/admin?entity=groups'))->withStatus(302);
    });

    $app->post('/admin/sections/delete', function (Request $request, Response $response) use ($app, $isValidCsrf) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', app_url('/login'))->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=subgroups'))->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];

        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        if ($slug !== '') {
            $cardsCount = $repo->countCardsBySectionSlug($slug);
            if ($cardsCount > 0) {
                $_SESSION['admin_flash'] = sprintf('Não é possível excluir o subgrupo. Existem %d card(s) vinculado(s).', $cardsCount);
                return $response->withHeader('Location', app_url('/admin?entity=subgroups'))->withStatus(302);
            }

            $repo->deleteSection($slug);
            $_SESSION['admin_flash'] = 'Subgrupo removido com sucesso.';
        }

        return $response->withHeader('Location', app_url('/admin?entity=subgroups'))->withStatus(302);
    });

    $app->post('/admin/cards/create', function (Request $request, Response $response) use ($app, $isValidCsrf, $normalizeHref) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', app_url('/login'))->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=cards'))->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];

        $groupSlug = strtolower(trim((string) ($data['group_slug'] ?? '')));
        $subgroupSlug = strtolower(trim((string) ($data['subgroup_slug'] ?? ($data['section_slug'] ?? ''))));

        $sections = $repo->getSections();
        if ($subgroupSlug === '' || !isset($sections[$subgroupSlug])) {
            $_SESSION['admin_flash'] = 'Subgrupo inválido. Selecione um subgrupo existente.';
            return $response->withHeader('Location', app_url('/admin?entity=cards&mode=new'))->withStatus(302);
        }

        $subgroupGroupSlug = strtolower(trim((string) ($sections[$subgroupSlug]['group_slug'] ?? '')));
        if ($groupSlug !== '' && $groupSlug !== $subgroupGroupSlug) {
            $_SESSION['admin_flash'] = 'O subgrupo selecionado não pertence ao grupo informado.';
            return $response->withHeader('Location', app_url('/admin?entity=cards&mode=new'))->withStatus(302);
        }

        $repo->createCard([
            'group_slug' => $subgroupGroupSlug,
            'subgroup_slug' => $subgroupSlug,
            'section_slug' => $subgroupSlug,
            'title' => trim((string) ($data['title'] ?? '')),
            'href' => $normalizeHref((string) ($data['href'] ?? '#')),
            'external' => ((string) ($data['external'] ?? '0')) === '1',
            'icon' => trim((string) ($data['icon'] ?? 'bi-globe2')) ?: 'bi-globe2',
            'status' => trim((string) ($data['status'] ?? 'Interno')) ?: 'Interno',
            'description' => trim((string) ($data['description'] ?? '')),
            'order' => max(1, (int) ($data['order'] ?? 99)),
        ]);

        $_SESSION['admin_flash'] = 'Card criado com sucesso.';
        return $response->withHeader('Location', app_url('/admin?entity=cards'))->withStatus(302);
    });

    $app->post('/admin/cards/update', function (Request $request, Response $response) use ($app, $isValidCsrf, $normalizeHref) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', app_url('/login'))->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=cards'))->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];
        $id = (int) ($data['id'] ?? 0);

        $groupSlug = strtolower(trim((string) ($data['group_slug'] ?? '')));
        $subgroupSlug = strtolower(trim((string) ($data['subgroup_slug'] ?? ($data['section_slug'] ?? ''))));

        $sections = $repo->getSections();
        if ($subgroupSlug === '' || !isset($sections[$subgroupSlug])) {
            $_SESSION['admin_flash'] = 'Subgrupo inválido. Selecione um subgrupo existente.';
            $redirect = $id > 0
                ? app_url('/admin?entity=cards&mode=edit&id=' . $id)
                : app_url('/admin?entity=cards');
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $subgroupGroupSlug = strtolower(trim((string) ($sections[$subgroupSlug]['group_slug'] ?? '')));
        if ($groupSlug !== '' && $groupSlug !== $subgroupGroupSlug) {
            $_SESSION['admin_flash'] = 'O subgrupo selecionado não pertence ao grupo informado.';
            $redirect = $id > 0
                ? app_url('/admin?entity=cards&mode=edit&id=' . $id)
                : app_url('/admin?entity=cards');
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        if ($id > 0) {
            $repo->updateCard($id, [
                'group_slug' => $subgroupGroupSlug,
                'subgroup_slug' => $subgroupSlug,
                'section_slug' => $subgroupSlug,
                'title' => trim((string) ($data['title'] ?? '')),
                'href' => $normalizeHref((string) ($data['href'] ?? '#')),
                'external' => ((string) ($data['external'] ?? '0')) === '1',
                'icon' => trim((string) ($data['icon'] ?? 'bi-globe2')) ?: 'bi-globe2',
                'status' => trim((string) ($data['status'] ?? 'Interno')) ?: 'Interno',
                'description' => trim((string) ($data['description'] ?? '')),
                'order' => max(1, (int) ($data['order'] ?? 99)),
            ]);
            $_SESSION['admin_flash'] = 'Card atualizado com sucesso.';
        }

        return $response->withHeader('Location', app_url('/admin?entity=cards'))->withStatus(302);
    });

    $app->post('/admin/cards/delete', function (Request $request, Response $response) use ($app, $isValidCsrf) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', app_url('/login'))->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin?entity=cards'))->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];
        $id = (int) ($data['id'] ?? 0);

        if ($id > 0) {
            $repo->deleteCard($id);
            $_SESSION['admin_flash'] = 'Card removido com sucesso.';
        }

        return $response->withHeader('Location', app_url('/admin?entity=cards'))->withStatus(302);
    });
};
