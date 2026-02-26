<?php

declare(strict_types=1);

date_default_timezone_set('America/Fortaleza');

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use League\CommonMark\CommonMarkConverter;
use App\Infrastructure\Persistence\Dashboard\DashboardRepository;
use Slim\App;
use Slim\Views\Twig;

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

        return '/itapiru/admin/avatar?v=' . rawurlencode($version);
    };

    $navbarAuthContext = static function () use ($ensureCsrfToken, $resolveAdminAvatarUrl): array {
        $adminUsername = (string) ($_SESSION['admin_user'] ?? '');

        return [
            'isAdminLogged' => !empty($_SESSION['is_admin']),
            'adminUsername' => $adminUsername,
            'adminAvatarUrl' => $resolveAdminAvatarUrl($adminUsername),
            'csrfToken' => $ensureCsrfToken(),
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

        foreach ($groups as $group) {
            $groupSlug = strtolower(trim((string) ($group['slug'] ?? '')));
            if ($groupSlug === '') {
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

    $app->get('/', function (Request $request, Response $response) use ($app) {
        return $response
            ->withHeader('Location', '/itapiru')
            ->withStatus(302);
    });

    $app->get('/itapiru', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections) {
        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);
        $meta = $repo->getMeta();
        $sections = $repo->getSections();
        $groups = $repo->getAllGroups();
        $cardsBySection = $repo->getCardsBySection();
        $lastUpdated = date('d/m/Y H:i');
        $totalCards = 0;

        foreach ($cardsBySection as $sectionCards) {
            $totalCards += count($sectionCards);
        }

        return $twig->render($response, 'dashboard-home.twig', array_merge([
            'sections' => $sections,
            'groupedSections' => $buildGroupedSections($sections, $groups),
            'activeSection' => 'home',
            'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
            'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
            'lastUpdated' => $lastUpdated,
            'summary' => [
                'groups' => count($groups),
                'cards' => $totalCards,
                'lastUpdated' => $lastUpdated,
            ],
        ], $navbarAuthContext()));
    });

    $app->get('/itapiru/', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections) {
        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);
        $meta = $repo->getMeta();
        $sections = $repo->getSections();
        $groups = $repo->getAllGroups();
        $cardsBySection = $repo->getCardsBySection();
        $lastUpdated = date('d/m/Y H:i');
        $totalCards = 0;

        foreach ($cardsBySection as $sectionCards) {
            $totalCards += count($sectionCards);
        }

        return $twig->render($response, 'dashboard-home.twig', array_merge([
            'sections' => $sections,
            'groupedSections' => $buildGroupedSections($sections, $groups),
            'activeSection' => 'home',
            'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
            'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
            'lastUpdated' => $lastUpdated,
            'summary' => [
                'groups' => count($groups),
                'cards' => $totalCards,
                'lastUpdated' => $lastUpdated,
            ],
        ], $navbarAuthContext()));
    });

    $app->get('/itapiru/readme', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections) {
        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);

        $meta = $repo->getMeta();
        $sections = $repo->getSections();
        $lastUpdated = date('d/m/Y H:i');
        $readmePath = dirname(__DIR__) . '/README.md';
        $readmeContent = is_file($readmePath)
            ? (string) file_get_contents($readmePath)
            : 'Guia do usuário não encontrado.';

        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $readmeHtml = $converter->convert($readmeContent)->getContent();

        return $twig->render($response, 'dashboard-readme.twig', array_merge([
            'sections' => $sections,
            'groupedSections' => $buildGroupedSections($sections, $repo->getAllGroups()),
            'activeSection' => 'readme',
            'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
            'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
            'lastUpdated' => $lastUpdated,
            'readmePageTitle' => 'Guia do usuário',
            'readmePageSubtitle' => 'Guia rápido de conteúdo e manutenção do dashboard',
            'readmeHtml' => $readmeHtml,
        ], $navbarAuthContext()));
    });

    $app->get('/itapiru/readme-seed', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections) {
        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);

        $meta = $repo->getMeta();
        $sections = $repo->getSections();
        $lastUpdated = date('d/m/Y H:i');
        $readmePath = __DIR__ . '/content/README.md';
        $readmeContent = is_file($readmePath)
            ? (string) file_get_contents($readmePath)
            : 'Guia de seed não encontrado.';

        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $readmeHtml = $converter->convert($readmeContent)->getContent();

        return $twig->render($response, 'dashboard-readme.twig', array_merge([
            'sections' => $sections,
            'groupedSections' => $buildGroupedSections($sections, $repo->getAllGroups()),
            'activeSection' => 'readme-seed',
            'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
            'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
            'lastUpdated' => $lastUpdated,
            'readmePageTitle' => 'Guia de Seed',
            'readmePageSubtitle' => 'Referência do arquivo app/content/dashboard.php',
            'readmeHtml' => $readmeHtml,
        ], $navbarAuthContext()));
    });

    $app->get('/itapiru/readme-sqlite', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections) {
        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);

        $meta = $repo->getMeta();
        $sections = $repo->getSections();
        $lastUpdated = date('d/m/Y H:i');
        $readmePath = dirname(__DIR__) . '/docs/sqlite-operacao.md';
        $readmeContent = is_file($readmePath)
            ? (string) file_get_contents($readmePath)
            : 'Guia de operação do SQLite não encontrado.';

        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $readmeHtml = $converter->convert($readmeContent)->getContent();

        return $twig->render($response, 'dashboard-readme.twig', array_merge([
            'sections' => $sections,
            'groupedSections' => $buildGroupedSections($sections, $repo->getAllGroups()),
            'activeSection' => 'readme-sqlite',
            'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
            'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
            'lastUpdated' => $lastUpdated,
            'readmePageTitle' => 'Guia de Operação SQLite',
            'readmePageSubtitle' => 'Consultas, diagnóstico e manutenção do banco',
            'readmeHtml' => $readmeHtml,
        ], $navbarAuthContext()));
    });

    $app->get('/itapiru/contato', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections) {
        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);

        $meta = $repo->getMeta();
        $sections = $repo->getSections();
        $lastUpdated = date('d/m/Y H:i');

        return $twig->render($response, 'dashboard-contact.twig', array_merge([
            'sections' => $sections,
            'groupedSections' => $buildGroupedSections($sections, $repo->getAllGroups()),
            'activeSection' => 'contato',
            'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
            'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
            'lastUpdated' => $lastUpdated,
            'formErrors' => [],
            'formData' => [
                'name' => '',
                'email' => '',
                'subject' => '',
                'message' => '',
                'website' => '',
            ],
            'formSuccess' => false,
        ], $navbarAuthContext()));
    });

    $app->post('/itapiru/contato', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections) {
        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);

        $meta = $repo->getMeta();
        $sections = $repo->getSections();
        $lastUpdated = date('d/m/Y H:i');

        $parsedBody = $request->getParsedBody();
        $payload = is_array($parsedBody) ? $parsedBody : [];

        $name = trim((string) ($payload['name'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $subject = trim((string) ($payload['subject'] ?? ''));
        $message = trim((string) ($payload['message'] ?? ''));
        $website = trim((string) ($payload['website'] ?? ''));

        $formErrors = [];

        if ($website !== '') {
            return $twig->render($response, 'dashboard-contact.twig', array_merge([
                'sections' => $sections,
                'groupedSections' => $buildGroupedSections($sections, $repo->getAllGroups()),
                'activeSection' => 'contato',
                'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
                'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
                'lastUpdated' => $lastUpdated,
                'formErrors' => [],
                'formData' => [
                    'name' => '',
                    'email' => '',
                    'subject' => '',
                    'message' => '',
                    'website' => '',
                ],
                'formSuccess' => true,
            ], $navbarAuthContext()));
        }

        if ($name === '') {
            $formErrors['name'] = 'Informe seu nome.';
        }

        if ($email === '') {
            $formErrors['email'] = 'Informe seu e-mail.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $formErrors['email'] = 'Informe um e-mail válido.';
        }

        if ($subject === '') {
            $formErrors['subject'] = 'Informe o assunto.';
        }

        if ($message === '') {
            $formErrors['message'] = 'Informe sua mensagem.';
        } elseif (mb_strlen($message) > 1500) {
            $formErrors['message'] = 'A mensagem deve ter no máximo 1500 caracteres.';
        }

        $formSuccess = false;

        if ($formErrors === []) {
            $logLine = sprintf(
                "[%s] nome=%s | email=%s | assunto=%s | mensagem=%s%s",
                date('Y-m-d H:i:s'),
                str_replace(["\r", "\n"], ' ', $name),
                str_replace(["\r", "\n"], ' ', $email),
                str_replace(["\r", "\n"], ' ', $subject),
                str_replace(["\r", "\n"], ' ', $message),
                PHP_EOL
            );

            $logFile = __DIR__ . '/../logs/contact-messages.log';
            @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

            $formSuccess = true;
            $name = '';
            $email = '';
            $subject = '';
            $message = '';
        }

        return $twig->render($response, 'dashboard-contact.twig', array_merge([
            'sections' => $sections,
            'groupedSections' => $buildGroupedSections($sections, $repo->getAllGroups()),
            'activeSection' => 'contato',
            'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
            'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
            'lastUpdated' => $lastUpdated,
            'formErrors' => $formErrors,
            'formData' => [
                'name' => $name,
                'email' => $email,
                'subject' => $subject,
                'message' => $message,
                'website' => '',
            ],
            'formSuccess' => $formSuccess,
        ], $navbarAuthContext()));
    });

    $app->get('/itapiru/login', function (Request $request, Response $response) use ($app, $ensureCsrfToken, $buildAdminLoginViewData) {
        if (!empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', '/itapiru/admin')->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);

        return $twig->render(
            $response,
            'admin-login.twig',
            $buildAdminLoginViewData($repo, $ensureCsrfToken(), null)
        );
    });

    $app->post('/itapiru/login', function (Request $request, Response $response) use ($app, $isValidCsrf, $ensureCsrfToken, $buildAdminLoginViewData) {
        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);

        if (!$isValidCsrf($request)) {
            return $twig->render(
                $response,
                'admin-login.twig',
                $buildAdminLoginViewData($repo, $ensureCsrfToken(), 'Sessão expirada. Atualize a página e tente novamente.')
            );
        }

        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        $now = time();
        $windowSeconds = 300;
        $maxAttempts = 5;
        $lockSeconds = 600;

        $attempts = $_SESSION['admin_login_attempts'] ?? [
            'count' => 0,
            'first' => $now,
            'locked_until' => 0,
        ];

        if (!is_array($attempts)) {
            $attempts = [
                'count' => 0,
                'first' => $now,
                'locked_until' => 0,
            ];
        }

        $lockedUntil = (int) ($attempts['locked_until'] ?? 0);
        if ($lockedUntil > $now) {
            $remaining = $lockedUntil - $now;
            return $twig->render(
                $response,
                'admin-login.twig',
                $buildAdminLoginViewData(
                    $repo,
                    $ensureCsrfToken(),
                    sprintf('Muitas tentativas. Tente novamente em %d segundos.', $remaining)
                )
            );
        }

        $firstAttemptTs = (int) ($attempts['first'] ?? $now);
        if (($now - $firstAttemptTs) > $windowSeconds) {
            $attempts = [
                'count' => 0,
                'first' => $now,
                'locked_until' => 0,
            ];
        }

        if ($repo->verifyAdmin($username, $password)) {
            $_SESSION['is_admin'] = true;
            $_SESSION['admin_user'] = $username;
            $_SESSION['admin_login_attempts'] = [
                'count' => 0,
                'first' => $now,
                'locked_until' => 0,
            ];
            return $response->withHeader('Location', '/itapiru/admin')->withStatus(302);
        }

        $attempts['count'] = (int) ($attempts['count'] ?? 0) + 1;
        if ($attempts['count'] >= $maxAttempts) {
            $attempts['locked_until'] = $now + $lockSeconds;
            $attempts['count'] = 0;
            $attempts['first'] = $now;
            $_SESSION['admin_login_attempts'] = $attempts;

            return $twig->render(
                $response,
                'admin-login.twig',
                $buildAdminLoginViewData(
                    $repo,
                    $ensureCsrfToken(),
                    sprintf('Muitas tentativas. Acesso bloqueado por %d minutos.', (int) ($lockSeconds / 60))
                )
            );
        }

        $_SESSION['admin_login_attempts'] = $attempts;

        return $twig->render(
            $response,
            'admin-login.twig',
            $buildAdminLoginViewData($repo, $ensureCsrfToken(), 'Usuário ou senha inválidos.')
        );
    });

    $app->post('/itapiru/logout', function (Request $request, Response $response) use ($isValidCsrf) {
        if (!$isValidCsrf($request)) {
            return $response->withHeader('Location', '/itapiru/admin')->withStatus(302);
        }

        unset($_SESSION['is_admin'], $_SESSION['admin_user']);
        return $response->withHeader('Location', '/itapiru/login')->withStatus(302);
    });

    $app->get('/itapiru/admin/avatar', function (Request $request, Response $response) use ($resolveAdminAvatarStoredValue, $normalizeAdminAvatarFile, $isValidUploadedAvatarPath) {
        $adminUsername = (string) ($_SESSION['admin_user'] ?? 'admin');
        $stored = $resolveAdminAvatarStoredValue($adminUsername);

        $relativePath = 'face6_620_620.png';
        if ($isValidUploadedAvatarPath($stored)) {
            $relativePath = $stored;
        } else {
            $relativePath = $normalizeAdminAvatarFile($stored);
        }

        $absolutePath = dirname(__DIR__) . '/public/assets/img/avatar/' . $relativePath;
        if (!is_file($absolutePath)) {
            $absolutePath = dirname(__DIR__) . '/public/assets/img/avatar/face6_620_620.png';
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $mimeType = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        $response->getBody()->write((string) file_get_contents($absolutePath));

        return $response
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Cache-Control', 'private, max-age=60')
            ->withStatus(200);
    });

    $app->get('/itapiru/admin', function (Request $request, Response $response) use ($app, $ensureCsrfToken, $flashPull, $navbarAuthContext, $buildGroupedSections) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', '/itapiru/login')->withStatus(302);
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

        $query = $request->getQueryParams();
        $entity = (string) ($query['entity'] ?? 'groups');
        if ($entity === 'sections') {
            $entity = 'subgroups';
        }
        if (!in_array($entity, ['groups', 'subgroups', 'cards'], true)) {
            $entity = 'groups';
        }

        $mode = (string) ($query['mode'] ?? 'list');
        if (!in_array($mode, ['list', 'new', 'edit'], true)) {
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

        $allowedPerPage = [5, 10, 15, 20, 25, 50];
        $perPage = (int) ($query['per_page'] ?? 10);
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 10;
        }
        $requestedPage = max(1, (int) ($query['page'] ?? 1));

        $listedGroups = $groups;
        $listedSections = $sections;
        $listedCards = $filteredCards;

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
            'filteredCards' => $filteredCards,
            'cardFilters' => $cardFilters,
            'groupsPagination' => $groupsPagination,
            'sectionsPagination' => $sectionsPagination,
            'cardsPagination' => $cardsPagination,
            'groupsPaginationQuery' => $groupsPaginationQuery,
            'sectionsPaginationQuery' => $sectionsPaginationQuery,
            'cardsPaginationQuery' => $cardsPaginationQuery,
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
        ], $navbarAuthContext()));
    });

    $app->get('/itapiru/admin/account', function (Request $request, Response $response) use ($app, $ensureCsrfToken, $flashPull, $navbarAuthContext, $buildGroupedSections, $allowedAdminAvatarFiles, $normalizeAdminAvatarFile, $adminAvatarConfigKey, $isValidUploadedAvatarPath) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', '/itapiru/login')->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);
        $meta = $repo->getMeta();
        $sections = $repo->getSections();
        $adminUsername = (string) ($_SESSION['admin_user'] ?? 'admin');

        $storedAvatar = trim($repo->getConfigValue($adminAvatarConfigKey($adminUsername), 'face6_620_620.png'));
        $selectedAvatar = $normalizeAdminAvatarFile($storedAvatar);
        $currentAvatarUrl = '/itapiru/admin/avatar?v=' . rawurlencode(substr(sha1($storedAvatar), 0, 12));
        $isCustomAvatar = false;

        if ($isValidUploadedAvatarPath($storedAvatar)) {
            $isCustomAvatar = true;
            $selectedAvatar = '';
        }

        $avatarOptions = array_map(
            static fn (string $filename): array => [
                'filename' => $filename,
                'url' => '/assets/img/avatar/' . $filename,
                'label' => strtoupper(str_replace(['face', '_620_620.png'], ['avatar ', ''], $filename)),
            ],
            $allowedAdminAvatarFiles
        );

        return $twig->render($response, 'admin-account.twig', array_merge([
            'sections' => $sections,
            'groupedSections' => $buildGroupedSections($sections, $repo->getAllGroups()),
            'activeSection' => 'admin',
            'adminEntity' => 'account',
            'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
            'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
            'lastUpdated' => date('d/m/Y H:i'),
            'csrfToken' => $ensureCsrfToken(),
            'flashMessage' => $flashPull(),
            'avatarOptions' => $avatarOptions,
            'accountForm' => [
                'username' => $adminUsername,
                'avatar' => $selectedAvatar,
                'is_custom_avatar' => $isCustomAvatar,
                'current_avatar_url' => $currentAvatarUrl,
                'current_avatar_value' => $isCustomAvatar ? $storedAvatar : '',
            ],
            'adminUsername' => $adminUsername,
        ], $navbarAuthContext()));
    });

    $app->post('/itapiru/admin/account/update', function (Request $request, Response $response) use ($app, $isValidCsrf, $allowedAdminAvatarFiles, $normalizeAdminAvatarFile, $adminAvatarConfigKey, $normalizeAdminUsername) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', '/itapiru/login')->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin/account')->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];

        $currentUsername = (string) ($_SESSION['admin_user'] ?? 'admin');
        $newUsername = $normalizeAdminUsername((string) ($data['username'] ?? ''));
        $requestedAvatar = trim((string) ($data['avatar'] ?? ''));
        $currentAvatarValue = trim((string) ($data['avatar_current'] ?? ''));
        $currentPassword = (string) ($data['current_password'] ?? '');
        $newPassword = (string) ($data['new_password'] ?? '');
        $confirmPassword = (string) ($data['confirm_password'] ?? '');
        $uploadedFiles = $request->getUploadedFiles();
        $avatarUpload = $uploadedFiles['avatar_upload'] ?? null;

        if ($newUsername === '' || preg_match('/^[a-zA-Z0-9._\-]{3,60}$/', $newUsername) !== 1) {
            $_SESSION['admin_flash'] = 'Informe um nome de usuário válido (3 a 60 caracteres: letras, números, ponto, underscore e hífen).';
            return $response->withHeader('Location', '/itapiru/admin/account')->withStatus(302);
        }

        $hasUploadedAvatar = $avatarUpload instanceof UploadedFileInterface && $avatarUpload->getError() === UPLOAD_ERR_OK;
        $hasSelectedDefaultAvatar = in_array($requestedAvatar, $allowedAdminAvatarFiles, true);
        $hasCurrentCustomAvatar = false;

        if ($currentAvatarValue !== '' && preg_match('/^uploads\/[a-z0-9._\-]+\.(png|jpe?g|webp)$/i', $currentAvatarValue) === 1) {
            $currentCustomPath = dirname(__DIR__) . '/public/assets/img/avatar/' . $currentAvatarValue;
            $hasCurrentCustomAvatar = is_file($currentCustomPath);
        }

        $normalizedAvatar = $hasSelectedDefaultAvatar
            ? $normalizeAdminAvatarFile($requestedAvatar)
            : ($hasCurrentCustomAvatar ? $currentAvatarValue : 'face6_620_620.png');

        if (!$hasUploadedAvatar && !$hasSelectedDefaultAvatar && !$hasCurrentCustomAvatar) {
            $_SESSION['admin_flash'] = 'Avatar inválido. Selecione uma opção da lista.';
            return $response->withHeader('Location', '/itapiru/admin/account')->withStatus(302);
        }

        if ($avatarUpload instanceof UploadedFileInterface && !in_array($avatarUpload->getError(), [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE], true)) {
            $_SESSION['admin_flash'] = 'Falha no upload da foto. Tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin/account')->withStatus(302);
        }

        $wantsPasswordChange = ($newPassword !== '' || $confirmPassword !== '');
        $newPasswordHash = null;

        if ($wantsPasswordChange) {
            if ($currentPassword === '') {
                $_SESSION['admin_flash'] = 'Informe a senha atual para redefinir a senha.';
                return $response->withHeader('Location', '/itapiru/admin/account')->withStatus(302);
            }

            if (!$repo->verifyAdmin($currentUsername, $currentPassword)) {
                $_SESSION['admin_flash'] = 'Senha atual inválida.';
                return $response->withHeader('Location', '/itapiru/admin/account')->withStatus(302);
            }

            if ($newPassword === '' || mb_strlen($newPassword) < 8) {
                $_SESSION['admin_flash'] = 'A nova senha deve ter pelo menos 8 caracteres.';
                return $response->withHeader('Location', '/itapiru/admin/account')->withStatus(302);
            }

            if ($newPassword !== $confirmPassword) {
                $_SESSION['admin_flash'] = 'A confirmação da nova senha não confere.';
                return $response->withHeader('Location', '/itapiru/admin/account')->withStatus(302);
            }

            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        if ($hasUploadedAvatar) {
            $maxSize = 2 * 1024 * 1024;
            if ($avatarUpload->getSize() > $maxSize) {
                $_SESSION['admin_flash'] = 'A foto deve ter no máximo 2MB.';
                return $response->withHeader('Location', '/itapiru/admin/account')->withStatus(302);
            }

            $clientFilename = strtolower((string) ($avatarUpload->getClientFilename() ?? ''));
            $extension = pathinfo($clientFilename, PATHINFO_EXTENSION);
            $allowedExtensions = ['png', 'jpg', 'jpeg', 'webp'];
            if (!in_array($extension, $allowedExtensions, true)) {
                $_SESSION['admin_flash'] = 'Formato de foto inválido. Use PNG, JPG, JPEG ou WEBP.';
                return $response->withHeader('Location', '/itapiru/admin/account')->withStatus(302);
            }

            $uploadDir = dirname(__DIR__) . '/public/assets/img/avatar/uploads';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                $_SESSION['admin_flash'] = 'Falha ao preparar diretório de upload da foto.';
                return $response->withHeader('Location', '/itapiru/admin/account')->withStatus(302);
            }

            $fileSafeUser = strtolower(preg_replace('/[^a-z0-9._\-]/i', '-', $newUsername) ?? 'admin');
            $fileSafeUser = trim((string) preg_replace('/-+/', '-', $fileSafeUser), '-');
            if ($fileSafeUser === '') {
                $fileSafeUser = 'admin';
            }

            $targetFilename = sprintf('%s-%s-%s.%s', $fileSafeUser, date('YmdHis'), bin2hex(random_bytes(4)), $extension);
            $targetPath = $uploadDir . '/' . $targetFilename;

            try {
                $avatarUpload->moveTo($targetPath);
            } catch (\Throwable $throwable) {
                $_SESSION['admin_flash'] = 'Falha ao salvar a foto enviada.';
                return $response->withHeader('Location', '/itapiru/admin/account')->withStatus(302);
            }

            $normalizedAvatar = 'uploads/' . $targetFilename;
        }

        try {
            $repo->updateAdminAccount($currentUsername, $newUsername, $newPasswordHash);
        } catch (\Throwable $throwable) {
            $message = strtolower($throwable->getMessage());
            if (strpos($message, 'unique') !== false || strpos($message, 'already exists') !== false) {
                $_SESSION['admin_flash'] = 'Já existe outro usuário admin com esse nome.';
            } else {
                $_SESSION['admin_flash'] = 'Falha ao atualizar conta administrativa.';
            }

            return $response->withHeader('Location', '/itapiru/admin/account')->withStatus(302);
        }

        $currentAvatarKey = $adminAvatarConfigKey($currentUsername);
        $nextAvatarKey = $adminAvatarConfigKey($newUsername);

        $repo->setConfigValue($nextAvatarKey, $normalizedAvatar);
        if ($nextAvatarKey !== $currentAvatarKey) {
            $repo->deleteConfigValue($currentAvatarKey);
        }

        $_SESSION['admin_user'] = $newUsername;
        $_SESSION['admin_flash'] = $wantsPasswordChange
            ? 'Conta atualizada com sucesso. Nome, senha e foto foram salvos.'
            : 'Conta atualizada com sucesso. Nome e foto foram salvos.';

        return $response->withHeader('Location', '/itapiru/admin?entity=sections')->withStatus(302);
    });

    $app->get('/itapiru/admin/preferences', function (Request $request, Response $response) {
        return $response->withHeader('Location', '/itapiru/admin/account')->withStatus(302);
    });

    $app->post('/itapiru/admin/preferences/avatar', function (Request $request, Response $response) {
        return $response->withHeader('Location', '/itapiru/admin/account')->withStatus(302);
    });

    $app->post('/itapiru/admin/groups/create', function (Request $request, Response $response) use ($app, $isValidCsrf, $resolveOriginalSlugFromReferer) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', '/itapiru/login')->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin?entity=groups')->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];

        if ((string) ($data['_form'] ?? '') !== 'group_create') {
            $_SESSION['admin_flash'] = 'Formulário inválido para criação de grupo. Recarregue a página e tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin?entity=groups&mode=new')->withStatus(302);
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
            return $response->withHeader('Location', '/itapiru/admin?entity=groups&mode=new')->withStatus(302);
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
            return $response->withHeader('Location', '/itapiru/admin?entity=groups')->withStatus(302);
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

            return $response->withHeader('Location', '/itapiru/admin?entity=groups')->withStatus(302);
        }

        if ($originalSlug === '' && count($sameLabelGroups) > 0) {
            $_SESSION['admin_flash'] = 'Já existe um grupo com esse nome. Use editar no grupo existente para renomear o slug.';
            return $response->withHeader('Location', '/itapiru/admin?entity=groups')->withStatus(302);
        }

        if (isset($existing[$slug])) {
            $_SESSION['admin_flash'] = sprintf('Já existe um grupo com slug "%s".', $slug);
            return $response->withHeader('Location', '/itapiru/admin?entity=groups&mode=new')->withStatus(302);
        }

        $repo->createGroup($slug, $label, max(1, (int) ($data['order'] ?? 99)));
        $_SESSION['admin_flash'] = 'Grupo criado com sucesso.';

        return $response->withHeader('Location', '/itapiru/admin?entity=groups')->withStatus(302);
    });

    $app->post('/itapiru/admin/groups/update', function (Request $request, Response $response) use ($app, $isValidCsrf) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', '/itapiru/login')->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin?entity=groups')->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];

        if ((string) ($data['_form'] ?? '') !== 'group_update') {
            $_SESSION['admin_flash'] = 'Formulário inválido para atualização de grupo. Recarregue a página e tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin?entity=groups')->withStatus(302);
        }

        $originalSlug = strtolower(trim((string) ($data['original_slug'] ?? '')));
        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug) ?? '';
        $slug = trim((string) preg_replace('/-+/', '-', $slug), '-');

        if ($originalSlug === '' || $slug === '') {
            $_SESSION['admin_flash'] = 'Informe um slug válido para atualizar o grupo.';
            return $response->withHeader('Location', '/itapiru/admin?entity=groups')->withStatus(302);
        }

        $groups = $repo->getGroupsBySlug();
        if (!isset($groups[$originalSlug])) {
            $_SESSION['admin_flash'] = 'Grupo original não encontrado.';
            return $response->withHeader('Location', '/itapiru/admin?entity=groups')->withStatus(302);
        }

        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            $label = $slug;
        }

        $repo->updateGroup($originalSlug, $slug, $label, max(1, (int) ($data['order'] ?? 99)));
        $_SESSION['admin_flash'] = 'Grupo atualizado com sucesso.';

        return $response->withHeader('Location', '/itapiru/admin?entity=groups')->withStatus(302);
    });

    $app->post('/itapiru/admin/groups/delete', function (Request $request, Response $response) use ($app, $isValidCsrf) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', '/itapiru/login')->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin?entity=groups')->withStatus(302);
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
                return $response->withHeader('Location', '/itapiru/admin?entity=groups')->withStatus(302);
            }

            $repo->deleteGroup($slug);
            $_SESSION['admin_flash'] = 'Grupo removido com sucesso.';
        }

        return $response->withHeader('Location', '/itapiru/admin?entity=groups')->withStatus(302);
    });

    $app->post('/itapiru/admin/sections/create', function (Request $request, Response $response) use ($app, $isValidCsrf, $resolveOriginalSlugFromReferer) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', '/itapiru/login')->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin?entity=subgroups')->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $payload = $request->getParsedBody();
        $data = is_array($payload) ? $payload : [];

        if ((string) ($data['_form'] ?? '') !== 'subgroup_create') {
            $_SESSION['admin_flash'] = 'Formulário inválido para criação de subgrupo. Recarregue a página e tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin?entity=subgroups&mode=new')->withStatus(302);
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
            return $response->withHeader('Location', '/itapiru/admin?entity=subgroups&mode=new')->withStatus(302);
        }

        $groupSlug = strtolower(trim((string) ($data['group_slug'] ?? '')));
        $groupSlug = preg_replace('/[^a-z0-9\-]/', '-', $groupSlug) ?? '';
        $groupSlug = trim((string) preg_replace('/-+/', '-', $groupSlug), '-');
        if ($groupSlug === '') {
            $_SESSION['admin_flash'] = 'Selecione um grupo válido para o subgrupo.';
            return $response->withHeader('Location', '/itapiru/admin?entity=subgroups&mode=new')->withStatus(302);
        }

        $groups = $repo->getGroupsBySlug();
        if (!isset($groups[$groupSlug])) {
            $_SESSION['admin_flash'] = 'Grupo selecionado não existe mais. Atualize a página e tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin?entity=subgroups&mode=new')->withStatus(302);
        }

        $sections = $repo->getSections();
        if ($originalSlug !== '' && isset($sections[$originalSlug])) {
            if ($slug !== $originalSlug && isset($sections[$slug])) {
                $_SESSION['admin_flash'] = sprintf('Já existe uma seção com slug "%s".', $slug);
                return $response->withHeader('Location', '/itapiru/admin?entity=subgroups&mode=edit&slug=' . rawurlencode($originalSlug))->withStatus(302);
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

            return $response->withHeader('Location', '/itapiru/admin?entity=subgroups')->withStatus(302);
        }

        if (isset($sections[$slug])) {
            $_SESSION['admin_flash'] = sprintf('Já existe uma seção com slug "%s".', $slug);
            return $response->withHeader('Location', '/itapiru/admin?entity=subgroups&mode=new')->withStatus(302);
        }

        $repo->upsertSection(
            $slug,
            trim((string) ($data['label'] ?? $slug)),
            trim((string) ($data['description'] ?? '')),
            $groupSlug,
            max(1, (int) ($data['order'] ?? 99))
        );
        $_SESSION['admin_flash'] = 'Subgrupo criado com sucesso.';

        return $response->withHeader('Location', '/itapiru/admin?entity=subgroups')->withStatus(302);
    });

    $app->post('/itapiru/admin/sections/update', function (Request $request, Response $response) use ($app, $isValidCsrf) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', '/itapiru/login')->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin?entity=subgroups')->withStatus(302);
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
            return $response->withHeader('Location', '/itapiru/admin?entity=subgroups')->withStatus(302);
        }

        $sections = $repo->getSections();
        if (!isset($sections[$originalSlug])) {
            $_SESSION['admin_flash'] = 'Seção original não encontrada.';
            return $response->withHeader('Location', '/itapiru/admin?entity=subgroups')->withStatus(302);
        }

        if ($slug !== $originalSlug && isset($sections[$slug])) {
            $_SESSION['admin_flash'] = sprintf('Já existe uma seção com slug "%s".', $slug);
            return $response->withHeader('Location', '/itapiru/admin?entity=subgroups&mode=edit&slug=' . rawurlencode($originalSlug))->withStatus(302);
        }

        $groupSlug = strtolower(trim((string) ($data['group_slug'] ?? '')));
        $groupSlug = preg_replace('/[^a-z0-9\-]/', '-', $groupSlug) ?? '';
        $groupSlug = trim((string) preg_replace('/-+/', '-', $groupSlug), '-');
        if ($groupSlug === '') {
            $_SESSION['admin_flash'] = 'Selecione um grupo válido para o subgrupo.';
            return $response->withHeader('Location', '/itapiru/admin?entity=subgroups&mode=edit&slug=' . rawurlencode($originalSlug))->withStatus(302);
        }

        $groups = $repo->getGroupsBySlug();
        if (!isset($groups[$groupSlug])) {
            $_SESSION['admin_flash'] = 'Grupo selecionado não existe mais. Atualize a página e tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin?entity=subgroups&mode=edit&slug=' . rawurlencode($originalSlug))->withStatus(302);
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

        return $response->withHeader('Location', '/itapiru/admin?entity=subgroups')->withStatus(302);
    });

    $app->post('/itapiru/admin/sections/rename-group', function (Request $request, Response $response) use ($isValidCsrf) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', '/itapiru/login')->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin?entity=groups')->withStatus(302);
        }

        $_SESSION['admin_flash'] = 'Fluxo antigo de renomear grupo foi desativado. Use editar grupo em Admin > Grupos.';
        return $response->withHeader('Location', '/itapiru/admin?entity=groups')->withStatus(302);
    });

    $app->post('/itapiru/admin/sections/delete', function (Request $request, Response $response) use ($app, $isValidCsrf) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', '/itapiru/login')->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin?entity=subgroups')->withStatus(302);
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
                return $response->withHeader('Location', '/itapiru/admin?entity=subgroups')->withStatus(302);
            }

            $repo->deleteSection($slug);
            $_SESSION['admin_flash'] = 'Subgrupo removido com sucesso.';
        }

        return $response->withHeader('Location', '/itapiru/admin?entity=subgroups')->withStatus(302);
    });

    $app->post('/itapiru/admin/cards/create', function (Request $request, Response $response) use ($app, $isValidCsrf, $normalizeHref) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', '/itapiru/login')->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin?entity=cards')->withStatus(302);
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
            return $response->withHeader('Location', '/itapiru/admin?entity=cards&mode=new')->withStatus(302);
        }

        $subgroupGroupSlug = strtolower(trim((string) ($sections[$subgroupSlug]['group_slug'] ?? '')));
        if ($groupSlug !== '' && $groupSlug !== $subgroupGroupSlug) {
            $_SESSION['admin_flash'] = 'O subgrupo selecionado não pertence ao grupo informado.';
            return $response->withHeader('Location', '/itapiru/admin?entity=cards&mode=new')->withStatus(302);
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
            'metric' => trim((string) ($data['metric'] ?? '')),
            'trend' => trim((string) ($data['trend'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'order' => max(1, (int) ($data['order'] ?? 99)),
        ]);

        $_SESSION['admin_flash'] = 'Card criado com sucesso.';
        return $response->withHeader('Location', '/itapiru/admin?entity=cards')->withStatus(302);
    });

    $app->post('/itapiru/admin/cards/update', function (Request $request, Response $response) use ($app, $isValidCsrf, $normalizeHref) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', '/itapiru/login')->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin?entity=cards')->withStatus(302);
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
                ? '/itapiru/admin?entity=cards&mode=edit&id=' . $id
                : '/itapiru/admin?entity=cards';
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $subgroupGroupSlug = strtolower(trim((string) ($sections[$subgroupSlug]['group_slug'] ?? '')));
        if ($groupSlug !== '' && $groupSlug !== $subgroupGroupSlug) {
            $_SESSION['admin_flash'] = 'O subgrupo selecionado não pertence ao grupo informado.';
            $redirect = $id > 0
                ? '/itapiru/admin?entity=cards&mode=edit&id=' . $id
                : '/itapiru/admin?entity=cards';
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
                'metric' => trim((string) ($data['metric'] ?? '')),
                'trend' => trim((string) ($data['trend'] ?? '')),
                'description' => trim((string) ($data['description'] ?? '')),
                'order' => max(1, (int) ($data['order'] ?? 99)),
            ]);
            $_SESSION['admin_flash'] = 'Card atualizado com sucesso.';
        }

        return $response->withHeader('Location', '/itapiru/admin?entity=cards')->withStatus(302);
    });

    $app->post('/itapiru/admin/cards/delete', function (Request $request, Response $response) use ($app, $isValidCsrf) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', '/itapiru/login')->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', '/itapiru/admin?entity=cards')->withStatus(302);
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

        return $response->withHeader('Location', '/itapiru/admin?entity=cards')->withStatus(302);
    });

    $app->get('/admin/login', function (Request $request, Response $response) {
        return $response->withHeader('Location', '/itapiru/login')->withStatus(302);
    });

    $app->get('/admin', function (Request $request, Response $response) {
        return $response->withHeader('Location', '/itapiru/admin')->withStatus(302);
    });

    $app->get('/itapiru/{section}', function (Request $request, Response $response, array $args) use ($app, $navbarAuthContext, $buildGroupedSections) {
        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);
        $meta = $repo->getMeta();
        $sections = $repo->getSections();
        $lastUpdated = date('d/m/Y H:i');
        $activeSection = strtolower((string) ($args['section'] ?? 'secao-1'));

        if (!isset($sections[$activeSection])) {
            return $response
                ->withHeader('Location', '/itapiru')
                ->withStatus(302);
        }

        $activeSectionMeta = $sections[$activeSection];
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
