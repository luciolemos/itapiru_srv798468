<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use League\CommonMark\CommonMarkConverter;
use App\Infrastructure\Persistence\Dashboard\DashboardRepository;
use Slim\App;
use Slim\Views\Twig;

date_default_timezone_set('America/Fortaleza');

if (!function_exists('app_base_path')) {
    function app_base_path(): string
    {
        $configuredBasePath = trim((string) ($_ENV['APP_BASE_PATH'] ?? ''));
        if ($configuredBasePath !== '' && $configuredBasePath !== '/') {
            return '/' . trim($configuredBasePath, '/');
        }

        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $scriptDir = trim(str_replace('\\', '/', dirname($scriptName)));

        if ($scriptDir === '' || $scriptDir === '.' || $scriptDir === '/') {
            return '';
        }

        return '/' . trim($scriptDir, '/');
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        $basePath = app_base_path();
        $normalizedPath = trim($path);

        if ($normalizedPath === '' || $normalizedPath === '/') {
            return $basePath !== '' ? $basePath : '/';
        }

        return ($basePath !== '' ? $basePath : '') . '/' . ltrim($normalizedPath, '/');
    }
}

return function (App $app) {
    $flashPull = static function (): ?string {
        $message = $_SESSION['admin_flash'] ?? null;
        unset($_SESSION['admin_flash']);
        return is_string($message) && $message !== '' ? $message : null;
    };

    $ensureCsrfToken = static function (): string {
        $token = $_SESSION['csrf_token'] ?? null;
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION['csrf_token'] = $token;
        }

        return $token;
    };

    $isValidCsrf = static function (Request $request): bool {
        $sessionToken = $_SESSION['csrf_token'] ?? null;
        if (!is_string($sessionToken) || $sessionToken === '') {
            return false;
        }

        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];
        $providedToken = (string) ($data['csrf_token'] ?? '');

        return $providedToken !== '' && hash_equals($sessionToken, $providedToken);
    };

    $resolveOriginalSlugFromReferer = static function (Request $request, string $expectedEntity): string {
        $referer = trim($request->getHeaderLine('Referer'));
        if ($referer === '') {
            return '';
        }

        $query = parse_url($referer, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return '';
        }

        parse_str($query, $params);
        if (!is_array($params)) {
            return '';
        }

        $entity = strtolower(trim((string) ($params['entity'] ?? '')));
        $mode = strtolower(trim((string) ($params['mode'] ?? '')));
        $slug = strtolower(trim((string) ($params['slug'] ?? '')));

        if ($expectedEntity === 'subgroups' && $entity === 'sections') {
            $entity = 'subgroups';
        }

        if ($entity !== $expectedEntity || $mode !== 'edit' || $slug === '') {
            return '';
        }

        return $slug;
    };

    $allowedAdminAvatarFiles = [
        'face2_620_620.png',
        'face6_620_620.png',
        'face7_620_620.png',
        'face8_620_620.png',
        'face9_620_620.png',
        'face10_620_620.png',
    ];

    $normalizeAdminAvatarFile = static function (?string $value) use ($allowedAdminAvatarFiles): string {
        $raw = trim((string) $value);
        if (!in_array($raw, $allowedAdminAvatarFiles, true)) {
            return 'face6_620_620.png';
        }

        return $raw;
    };

    $isValidUploadedAvatarPath = static function (?string $value): bool {
        $raw = trim((string) $value);

        return preg_match('/^uploads\/[a-z0-9._\-]+\.(png|jpe?g|webp)$/i', $raw) === 1;
    };

    $adminAvatarConfigKey = static function (string $username): string {
        $normalized = strtolower(trim($username));
        if ($normalized === '') {
            return 'admin.avatar.default';
        }

        return 'admin.avatar.' . $normalized;
    };

    $resolveAdminAvatarStoredValue = static function (?string $username) use ($app, $adminAvatarConfigKey): string {
        $normalizedUser = trim((string) $username);
        if ($normalizedUser === '') {
            return 'face6_620_620.png';
        }

        try {
            /** @var DashboardRepository $repo */
            $repo = $app->getContainer()->get(DashboardRepository::class);
            return trim($repo->getConfigValue($adminAvatarConfigKey($normalizedUser), 'face6_620_620.png'));
        } catch (\Throwable $throwable) {
            return 'face6_620_620.png';
        }
    };

    $normalizeAdminUsername = static function (?string $value): string {
        $username = trim((string) $value);
        $username = preg_replace('/\s+/', '', $username) ?? $username;

        return $username;
    };

    $resolveAdminAvatarUrl = static function (?string $username) use ($resolveAdminAvatarStoredValue): string {
        $stored = $resolveAdminAvatarStoredValue($username);
        $version = substr(sha1($stored), 0, 12);

        return app_url('/admin/avatar?v=' . rawurlencode($version));
    };

    $navbarAuthContext = static function () use ($app, $ensureCsrfToken, $resolveAdminAvatarUrl): array {
        $adminUsername = (string) ($_SESSION['admin_user'] ?? '');
        $isAdminLogged = !empty($_SESSION['is_admin']);
        $adminPendingRequestsCount = 0;
        $adminPendingRequests = [];

        if ($isAdminLogged) {
            try {
                /** @var DashboardRepository $repo */
                $repo = $app->getContainer()->get(DashboardRepository::class);
                $adminPendingRequestsCount = $repo->countPendingCardRequests();
                $adminPendingRequests = $repo->getRecentPendingCardRequests(5);
            } catch (\Throwable $throwable) {
                $adminPendingRequestsCount = 0;
                $adminPendingRequests = [];
            }
        }

        return [
            'isAdminLogged' => $isAdminLogged,
            'adminUsername' => $adminUsername,
            'adminAvatarUrl' => $resolveAdminAvatarUrl($adminUsername),
            'csrfToken' => $ensureCsrfToken(),
            'adminPendingRequestsCount' => $adminPendingRequestsCount,
            'adminPendingRequests' => $adminPendingRequests,
        ];
    };

    $normalizeGroupLabel = static function (?string $rawLabel): string {
        $label = trim((string) $rawLabel);
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;
        if ($label === '') {
            return 'Geral';
        }

        return mb_substr($label, 0, 60);
    };

    $buildGroupedSections = static function (array $sections, array $groups = []) use ($normalizeGroupLabel): array {
        $grouped = [];
        $isAdminLogged = !empty($_SESSION['is_admin']);

        foreach ($groups as $group) {
            $groupSlug = strtolower(trim((string) ($group['slug'] ?? '')));
            $visibility = (string) ($group['visibility'] ?? 'public');
            if ($groupSlug === '' || (!$isAdminLogged && $visibility === 'admin')) {
                continue;
            }

            $groupLabel = $normalizeGroupLabel((string) ($group['label'] ?? $groupSlug));
            $grouped[$groupSlug] = [
                'label' => $groupLabel,
                'items' => [],
            ];
        }

        foreach ($sections as $slug => $section) {
            $groupLabel = $normalizeGroupLabel((string) ($section['group'] ?? ''));

            $groupSlug = strtolower(trim((string) ($section['group_slug'] ?? '')));
            $groupKey = $groupSlug !== ''
                ? $groupSlug
                : (function_exists('mb_strtolower') ? mb_strtolower($groupLabel) : strtolower($groupLabel));

            if (!$isAdminLogged && $groupSlug !== '' && !isset($grouped[$groupKey])) {
                continue;
            }

            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'label' => $groupLabel,
                    'items' => [],
                ];
            }

            $grouped[$groupKey]['items'][(string) $slug] = $section;
        }

        return array_values($grouped);
    };

    $collectSectionGroups = static function (array $sections) use ($normalizeGroupLabel): array {
        $groups = [];

        foreach ($sections as $section) {
            $groupLabel = $normalizeGroupLabel((string) ($section['group'] ?? ''));

            if (!in_array($groupLabel, $groups, true)) {
                $groups[] = $groupLabel;
            }
        }

        sort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return $groups;
    };

    $normalizeHref = static function (string $rawHref): string {
        $href = trim($rawHref);
        if ($href === '') {
            return '#';
        }

        if (preg_match('/^https?:\s*\/\//i', $href) === 1) {
            $href = preg_replace('/\s+/', '', $href) ?? $href;
        }

        return $href;
    };

    $requesterRanks = [
        'General de Exército',
        'General de Divisão',
        'General de Brigada',
        'Coronel',
        'Tenente-Coronel',
        'Major',
        'Capitão',
        '1º Tenente',
        '2º Tenente',
        'Aspirante-a-Oficial',
        'Subtenente',
        '1º Sargento',
        '2º Sargento',
        '3º Sargento',
        'Cabo (Cb)',
        'Soldado (Sd)',
    ];
    $requesterRanksMap = array_fill_keys($requesterRanks, true);

    $buildAdminLoginViewData = static function (
        DashboardRepository $repo,
        string $csrfToken,
        ?string $loginError
    ) use ($buildGroupedSections): array {
        $meta = $repo->getMeta();
        $sections = $repo->getSections();

        return [
            'sections' => $sections,
            'groupedSections' => $buildGroupedSections($sections, $repo->getAllGroups()),
            'activeSection' => 'login',
            'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
            'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
            'lastUpdated' => date('d/m/Y H:i'),
            'csrfToken' => $csrfToken,
            'loginError' => $loginError,
            'isAdminLogged' => !empty($_SESSION['is_admin']),
        ];
    };

    $app->options('/{routes:.*}', function (Request $request, Response $response) {
        return $response;
    });

    $registerPublicRoutes = require __DIR__ . "/routes/public.php";
    $registerPublicRoutes($app, $navbarAuthContext, $buildGroupedSections, $ensureCsrfToken, $isValidCsrf, $requesterRanks, $requesterRanksMap);

    $registerAuthRoutes = require __DIR__ . "/routes/auth.php";
    $registerAuthRoutes($app, $ensureCsrfToken, $isValidCsrf, $buildAdminLoginViewData);

    $registerAccountRoutes = require __DIR__ . "/routes/account.php";
    $registerAccountRoutes($app, $ensureCsrfToken, $isValidCsrf, $flashPull, $navbarAuthContext, $buildGroupedSections, $allowedAdminAvatarFiles, $normalizeAdminAvatarFile, $adminAvatarConfigKey, $isValidUploadedAvatarPath, $resolveAdminAvatarStoredValue, $normalizeAdminUsername);

    $app->get('/admin', function (Request $request, Response $response) use ($app, $ensureCsrfToken, $flashPull, $navbarAuthContext, $buildGroupedSections) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', app_url('/login'))->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);
        $meta = $repo->getMeta();
        $groups = $repo->getAllGroups();
        $groupsBySlug = $repo->getGroupsBySlug();
        $sections = $repo->getSections();
        $cards = $repo->getAllCards();
        $sectionCardsCount = [];
        foreach ($cards as $card) {
            $sectionSlug = (string) ($card['section_slug'] ?? '');
            if ($sectionSlug === '') {
                continue;
            }

            if (!isset($sectionCardsCount[$sectionSlug])) {
                $sectionCardsCount[$sectionSlug] = 0;
            }

            $sectionCardsCount[$sectionSlug]++;
        }

        $cardRequests = $repo->getCardRequests();

        $query = $request->getQueryParams();
        $entity = (string) ($query['entity'] ?? 'groups');
        if ($entity === 'sections') {
            $entity = 'subgroups';
        }
        if (!in_array($entity, ['groups', 'subgroups', 'cards', 'requests'], true)) {
            $entity = 'groups';
        }

        $mode = (string) ($query['mode'] ?? 'list');
        if (!in_array($mode, ['list', 'new', 'edit'], true)) {
            $mode = 'list';
        }
        if ($entity === 'requests') {
            $mode = 'list';
        }

        $editingGroup = null;
        if ($entity === 'groups' && $mode === 'edit') {
            $groupSlug = strtolower(trim((string) ($query['slug'] ?? '')));
            if ($groupSlug !== '' && isset($groupsBySlug[$groupSlug])) {
                $editingGroup = $groupsBySlug[$groupSlug];
            } else {
                $mode = 'list';
            }
        }

        $editingSection = null;
        if ($entity === 'subgroups' && $mode === 'edit') {
            $slug = strtolower(trim((string) ($query['slug'] ?? '')));
            if ($slug !== '' && isset($sections[$slug])) {
                $editingSection = [
                    'slug' => $slug,
                    'label' => (string) ($sections[$slug]['label'] ?? ''),
                    'description' => (string) ($sections[$slug]['description'] ?? ''),
                    'group' => (string) ($sections[$slug]['group'] ?? 'Geral'),
                    'group_slug' => (string) ($sections[$slug]['group_slug'] ?? 'geral'),
                    'order' => (int) ($sections[$slug]['order'] ?? 99),
                ];
            } else {
                $mode = 'list';
            }
        }

        $editingCard = null;
        if ($entity === 'cards' && $mode === 'edit') {
            $cardId = (int) ($query['id'] ?? 0);
            foreach ($cards as $card) {
                if ((int) ($card['id'] ?? 0) === $cardId) {
                    $editingCard = $card;
                    break;
                }
            }

            if (!is_array($editingCard)) {
                $mode = 'list';
            }
        }

        $cardFilters = [
            'q' => trim((string) ($query['card_q'] ?? '')),
            'group' => strtolower(trim((string) ($query['card_group'] ?? ''))),
            'section' => strtolower(trim((string) ($query['card_section'] ?? ''))),
            'status' => trim((string) ($query['card_status'] ?? '')),
        ];

        $requestFilters = [
            'q' => trim((string) ($query['request_q'] ?? '')),
            'status' => strtolower(trim((string) ($query['request_status'] ?? ''))),
        ];

        $allowedRequestStatuses = ['pending', 'approved', 'rejected'];
        if ($requestFilters['status'] !== '' && !in_array($requestFilters['status'], $allowedRequestStatuses, true)) {
            $requestFilters['status'] = '';
        }

        $allowedStatuses = ['Interno', 'Externo', 'Sistema'];
        if ($cardFilters['status'] !== '' && !in_array($cardFilters['status'], $allowedStatuses, true)) {
            $cardFilters['status'] = '';
        }

        if ($cardFilters['group'] !== '' && !isset($groupsBySlug[$cardFilters['group']])) {
            $cardFilters['group'] = '';
        }

        if ($cardFilters['section'] !== '' && !isset($sections[$cardFilters['section']])) {
            $cardFilters['section'] = '';
        }

        if (
            $cardFilters['group'] !== ''
            && $cardFilters['section'] !== ''
            && (string) ($sections[$cardFilters['section']]['group_slug'] ?? '') !== $cardFilters['group']
        ) {
            $cardFilters['section'] = '';
        }

        $filteredCards = $cards;
        if ($entity === 'cards' && $mode === 'list') {
            $searchTerm = function_exists('mb_strtolower')
                ? mb_strtolower($cardFilters['q'])
                : strtolower($cardFilters['q']);

            $filteredCards = array_values(array_filter($cards, static function (array $card) use ($cardFilters, $searchTerm): bool {
                if ($cardFilters['group'] !== '' && (string) ($card['group_slug'] ?? '') !== $cardFilters['group']) {
                    return false;
                }

                if ($cardFilters['section'] !== '' && (string) ($card['section_slug'] ?? '') !== $cardFilters['section']) {
                    return false;
                }

                if ($cardFilters['status'] !== '' && (string) ($card['status'] ?? '') !== $cardFilters['status']) {
                    return false;
                }

                if ($searchTerm !== '') {
                    $title = (string) ($card['title'] ?? '');
                    $description = (string) ($card['description'] ?? '');
                    $haystack = function_exists('mb_strtolower')
                        ? mb_strtolower($title . ' ' . $description)
                        : strtolower($title . ' ' . $description);

                    if (strpos($haystack, $searchTerm) === false) {
                        return false;
                    }
                }

                return true;
            }));
        }

        $filteredRequests = $cardRequests;
        if ($entity === 'requests' && $mode === 'list') {
            $requestSearchTerm = function_exists('mb_strtolower')
                ? mb_strtolower($requestFilters['q'])
                : strtolower($requestFilters['q']);

            $filteredRequests = array_values(array_filter($cardRequests, static function (array $requestRow) use ($requestFilters, $requestSearchTerm): bool {
                $status = strtolower(trim((string) ($requestRow['status'] ?? '')));
                if ($requestFilters['status'] !== '' && $status !== $requestFilters['status']) {
                    return false;
                }

                if ($requestSearchTerm !== '') {
                    $haystack = implode(' ', [
                        (string) ($requestRow['requester_rank'] ?? ''),
                        (string) ($requestRow['requester_name'] ?? ''),
                        (string) ($requestRow['requester_contact'] ?? ''),
                        (string) ($requestRow['title'] ?? ''),
                        (string) ($requestRow['justification'] ?? ''),
                        (string) ($requestRow['href'] ?? ''),
                        (string) ($requestRow['group_label'] ?? ''),
                        (string) ($requestRow['subgroup_label'] ?? ''),
                    ]);

                    $normalizedHaystack = function_exists('mb_strtolower')
                        ? mb_strtolower($haystack)
                        : strtolower($haystack);

                    if (strpos($normalizedHaystack, $requestSearchTerm) === false) {
                        return false;
                    }
                }

                return true;
            }));
        }

        $allowedPerPage = [5, 10, 15, 20, 25, 50];
        $perPage = (int) ($query['per_page'] ?? 10);
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 10;
        }
        $requestedPage = max(1, (int) ($query['page'] ?? 1));

        $listedGroups = $groups;
        $listedSections = $sections;
        $listedCards = $filteredCards;
        $listedRequests = $filteredRequests;

        $groupsPagination = [
            'page' => 1,
            'totalPages' => 1,
            'totalItems' => count($groups),
        ];

        $sectionsPagination = [
            'page' => 1,
            'totalPages' => 1,
            'totalItems' => count($sections),
        ];

        $cardsPagination = [
            'page' => 1,
            'totalPages' => 1,
            'totalItems' => count($filteredCards),
        ];

        $requestsPagination = [
            'page' => 1,
            'totalPages' => 1,
            'totalItems' => count($filteredRequests),
        ];

        $appendPaginationRange = static function (array $pagination, int $itemsPerPage): array {
            $totalItems = max(0, (int) ($pagination['totalItems'] ?? 0));
            $page = max(1, (int) ($pagination['page'] ?? 1));

            if ($totalItems === 0) {
                $pagination['startItem'] = 0;
                $pagination['endItem'] = 0;
                return $pagination;
            }

            $startItem = (($page - 1) * $itemsPerPage) + 1;
            $endItem = min($totalItems, $startItem + $itemsPerPage - 1);

            $pagination['startItem'] = $startItem;
            $pagination['endItem'] = $endItem;

            return $pagination;
        };

        if ($entity === 'groups' && $mode === 'list') {
            $totalItems = count($groups);
            $totalPages = max(1, (int) ceil($totalItems / $perPage));
            $page = min($requestedPage, $totalPages);
            $offset = ($page - 1) * $perPage;

            $listedGroups = array_slice($groups, $offset, $perPage);
            $groupsPagination = [
                'page' => $page,
                'totalPages' => $totalPages,
                'totalItems' => $totalItems,
            ];
        }

        if ($entity === 'subgroups' && $mode === 'list') {
            $totalItems = count($sections);
            $totalPages = max(1, (int) ceil($totalItems / $perPage));
            $page = min($requestedPage, $totalPages);
            $offset = ($page - 1) * $perPage;

            $listedSections = array_slice($sections, $offset, $perPage, true);
            $sectionsPagination = [
                'page' => $page,
                'totalPages' => $totalPages,
                'totalItems' => $totalItems,
            ];
        }

        if ($entity === 'cards' && $mode === 'list') {
            $totalItems = count($filteredCards);
            $totalPages = max(1, (int) ceil($totalItems / $perPage));
            $page = min($requestedPage, $totalPages);
            $offset = ($page - 1) * $perPage;

            $listedCards = array_slice($filteredCards, $offset, $perPage);
            $cardsPagination = [
                'page' => $page,
                'totalPages' => $totalPages,
                'totalItems' => $totalItems,
            ];
        }

        if ($entity === 'requests' && $mode === 'list') {
            $totalItems = count($filteredRequests);
            $totalPages = max(1, (int) ceil($totalItems / $perPage));
            $page = min($requestedPage, $totalPages);
            $offset = ($page - 1) * $perPage;

            $listedRequests = array_slice($filteredRequests, $offset, $perPage);
            $requestsPagination = [
                'page' => $page,
                'totalPages' => $totalPages,
                'totalItems' => $totalItems,
            ];
        }

        $groupsPagination = $appendPaginationRange($groupsPagination, $perPage);
        $sectionsPagination = $appendPaginationRange($sectionsPagination, $perPage);
        $cardsPagination = $appendPaginationRange($cardsPagination, $perPage);
        $requestsPagination = $appendPaginationRange($requestsPagination, $perPage);

        $groupsPaginationQuery = [
            'entity' => 'groups',
            'mode' => 'list',
            'per_page' => $perPage,
        ];

        $sectionsPaginationQuery = [
            'entity' => 'subgroups',
            'mode' => 'list',
            'per_page' => $perPage,
        ];

        $cardsPaginationQuery = [
            'entity' => 'cards',
            'mode' => 'list',
            'per_page' => $perPage,
            'card_q' => $cardFilters['q'],
            'card_group' => $cardFilters['group'],
            'card_section' => $cardFilters['section'],
            'card_status' => $cardFilters['status'],
        ];

        $requestsPaginationQuery = [
            'entity' => 'requests',
            'mode' => 'list',
            'per_page' => $perPage,
            'request_q' => $requestFilters['q'],
            'request_status' => $requestFilters['status'],
        ];

        return $twig->render($response, 'admin-dashboard.twig', array_merge([
            'groups' => $groups,
            'groupsBySlug' => $groupsBySlug,
            'sections' => $sections,
            'groupedSections' => $buildGroupedSections($sections, $repo->getAllGroups()),
            'cards' => $cards,
            'sectionCardsCount' => $sectionCardsCount,
            'listedGroups' => $listedGroups,
            'listedSections' => $listedSections,
            'listedCards' => $listedCards,
            'listedRequests' => $listedRequests,
            'filteredCards' => $filteredCards,
            'filteredRequests' => $filteredRequests,
            'cardFilters' => $cardFilters,
            'requestFilters' => $requestFilters,
            'groupsPagination' => $groupsPagination,
            'sectionsPagination' => $sectionsPagination,
            'cardsPagination' => $cardsPagination,
            'requestsPagination' => $requestsPagination,
            'groupsPaginationQuery' => $groupsPaginationQuery,
            'sectionsPaginationQuery' => $sectionsPaginationQuery,
            'cardsPaginationQuery' => $cardsPaginationQuery,
            'requestsPaginationQuery' => $requestsPaginationQuery,
            'allowedPerPage' => $allowedPerPage,
            'currentPerPage' => $perPage,
            'flashMessage' => $flashPull(),
            'csrfToken' => $ensureCsrfToken(),
            'lastUpdated' => date('d/m/Y H:i'),
            'activeSection' => 'admin',
            'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
            'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
            'isAdminView' => true,
            'adminEntity' => $entity,
            'adminMode' => $mode,
            'editingGroup' => $editingGroup,
            'editingSection' => $editingSection,
            'editingCard' => $editingCard,
            'cardRequests' => $cardRequests,
        ], $navbarAuthContext()))
            ->withHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache');
    });

    $registerCardRequestRoutes = require __DIR__ . "/routes/card-requests.php";
    $registerCardRequestRoutes($app, $isValidCsrf, $normalizeHref);

    $registerAdminCrudRoutes = require __DIR__ . "/routes/admin-crud.php";
    $registerAdminCrudRoutes($app, $isValidCsrf, $resolveOriginalSlugFromReferer, $normalizeHref);

    $app->get('/admin/login', function (Request $request, Response $response) {
        return $response->withHeader('Location', app_url('/login'))->withStatus(302);
    });

    $app->get('/{section}', function (Request $request, Response $response, array $args) use ($app, $navbarAuthContext, $buildGroupedSections) {
        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);
        $meta = $repo->getMeta();
        $sections = $repo->getSections();
        $lastUpdated = date('d/m/Y H:i');
        $activeSection = strtolower((string) ($args['section'] ?? 'secao-1'));

        if (!isset($sections[$activeSection])) {
            return $response
                ->withHeader('Location', app_url(''))
                ->withStatus(302);
        }

        $activeSectionMeta = $sections[$activeSection];
        $groupsBySlug = $repo->getGroupsBySlug();
        $groupSlug = strtolower((string) ($activeSectionMeta['group_slug'] ?? ''));
        $groupVisibility = (string) ($groupsBySlug[$groupSlug]['visibility'] ?? 'public');
        if ($groupVisibility === 'admin' && empty($_SESSION['is_admin'])) {
            return $response
                ->withHeader('Location', app_url(''))
                ->withStatus(302);
        }

        $activeCards = $repo->getCardsForSection($activeSection);

        return $twig->render($response, 'dashboard.twig', array_merge([
            'sections' => $sections,
            'groupedSections' => $buildGroupedSections($sections, $repo->getAllGroups()),
            'activeSection' => $activeSection,
            'activeSectionMeta' => $activeSectionMeta,
            'cards' => $activeCards,
            'lastUpdated' => $lastUpdated,
            'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
            'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
        ], $navbarAuthContext()));
    });
};
