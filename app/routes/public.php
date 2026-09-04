<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Dashboard\DashboardRepository;
use League\CommonMark\CommonMarkConverter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\Twig;

return static function (App $app, callable $navbarAuthContext, callable $buildGroupedSections, callable $ensureCsrfToken, callable $isValidCsrf, array $requesterRanks, array $requesterRanksMap): void {
    $app->get('/index.php', function (Request $request, Response $response) {
        return $response
            ->withHeader('Location', app_url(''))
            ->withStatus(302);
    });

    $app->get('/', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections) {
        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);
        $meta = $repo->getMeta();
        $sections = $repo->getSections();
        $groups = $repo->getAllGroups();
        $cardsBySection = $repo->getCardsBySection();
        $groupsBySlug = $repo->getGroupsBySlug();
        $isAdminLogged = !empty($_SESSION['is_admin']);

        if (!$isAdminLogged) {
            $groups = array_values(array_filter($groups, static function (array $group): bool {
                return (string) ($group['visibility'] ?? 'public') !== 'admin';
            }));
            $sections = array_filter($sections, static function (array $section) use ($groupsBySlug): bool {
                $groupSlug = strtolower((string) ($section['group_slug'] ?? ''));
                return (string) ($groupsBySlug[$groupSlug]['visibility'] ?? 'public') !== 'admin';
            });
            $cardsBySection = array_intersect_key($cardsBySection, $sections);
        }

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

    $app->get('/legacy-home', function (Request $request, Response $response) {
        return $response
            ->withHeader('Location', app_url(''))
            ->withStatus(302);
    });

    $app->get('/readme', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections) {
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

    $app->get('/readme-seed', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections) {
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

    $app->get('/readme-sqlite', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections) {
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

    $app->get('/guardiao', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections) {
        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);

        $meta = $repo->getMeta();
        $sections = $repo->getSections();
        $lastUpdated = date('d/m/Y H:i');
        $guardiaoHtml = '<figure class="db-guardian-figure db-guardian-zoom" style="--guardian-zoom-scale: 2.1;"><img class="db-guardian-zoom-image" src="' . app_url('/assets/img/guardiao16.png') . '" alt="Guardião 16"></figure>';

        return $twig->render($response, 'dashboard-readme.twig', array_merge([
            'sections' => $sections,
            'groupedSections' => $buildGroupedSections($sections, $repo->getAllGroups()),
            'activeSection' => 'guardiao',
            'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
            'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
            'lastUpdated' => $lastUpdated,
            'readmePageTitle' => 'Guardião do 16º BI Mtz',
            'readmePageSubtitle' => 'Assistente visual para consulta de documentação',
            'readmeHtml' => $guardiaoHtml,
        ], $navbarAuthContext()));
    });

    $app->get('/distintivo-om', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections) {
        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);

        $meta = $repo->getMeta();
        $sections = $repo->getSections();
        $lastUpdated = date('d/m/Y H:i');
        $distintivoHtml = '<figure class="db-guardian-figure"><img src="' . app_url('/assets/img/om3.png') . '" alt="Distintivo da OM"></figure>';

        return $twig->render($response, 'dashboard-readme.twig', array_merge([
            'sections' => $sections,
            'groupedSections' => $buildGroupedSections($sections, $repo->getAllGroups()),
            'activeSection' => 'distintivo-om',
            'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
            'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
            'lastUpdated' => $lastUpdated,
            'readmePageTitle' => 'Distintivo da OM',
            'readmePageSubtitle' => 'Identidade visual institucional',
            'readmeHtml' => $distintivoHtml,
        ], $navbarAuthContext()));
    });

        $app->get('/faq', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections) {
                /** @var DashboardRepository $repo */
                $repo = $app->getContainer()->get(DashboardRepository::class);
                $twig = $app->getContainer()->get(Twig::class);

                $meta = $repo->getMeta();
                $sections = $repo->getSections();
                $lastUpdated = date('d/m/Y H:i');
                $faqHtml = <<<'HTML'
<div class="db-faq-list" aria-label="Perguntas frequentes do Guardião">
    <p class="db-kicker">Solicitações</p>
    <details class="db-faq-item" open>
        <summary>Como solicito um novo card?</summary>
        <p>Use o menu <strong>Solicitar card</strong>, preencha os campos obrigatórios (posto/graduação, nome, e-mail, título, URL, grupo, subgrupo e descrição) e envie a solicitação.</p>
    </details>
    <details class="db-faq-item">
        <summary>Quem aprova as solicitações de card?</summary>
        <p>Usuários com acesso ao <strong>Admin</strong>. Em <strong>Admin &gt; Solicitações</strong>, o administrador pode aprovar (criando o card automaticamente) ou rejeitar.</p>
    </details>
    <details class="db-faq-item">
        <summary>A URL da solicitação tem alguma regra?</summary>
        <p>Sim. O campo <strong>URL</strong> é obrigatório e deve começar com <strong>https://</strong>.</p>
    </details>
    <details class="db-faq-item">
        <summary>Posso acompanhar o status da minha solicitação?</summary>
        <p>No momento, o acompanhamento é realizado pela Subseção de Desenvolvimento de Sistemas (SDS) da Seção de Informática da OM. Em caso de dúvida, entre em contato com o setor responsável pelo Dashboard através do ramal 6215 ou outro de nossos canais.</p>
    </details>

    <p class="db-kicker">Administração</p>
    <details class="db-faq-item" open>
        <summary>Posso criar card direto no Admin?</summary>
        <p>Sim. Em <strong>Admin &gt; Cards</strong>, o administrador pode cadastrar manualmente quando necessário.</p>
    </details>
    <details class="db-faq-item">
        <summary>Como crio um menu de grupo?</summary>
        <p>No menu <strong>Admin</strong>, acesse <strong>Grupos</strong> e cadastre um novo grupo. Ele passa a aparecer como agrupador no menu lateral conforme os subgrupos vinculados.</p>
    </details>
    <details class="db-faq-item">
        <summary>Como crio um menu de subgrupo?</summary>
        <p>No menu <strong>Admin</strong>, acesse <strong>Subgrupos</strong>, selecione o grupo pai e salve. O subgrupo é exibido dentro do grupo correspondente no menu lateral.</p>
    </details>
    <details class="db-faq-item">
        <summary>Posso editar o card depois de aprovado?</summary>
        <p>Sim. Em <strong>Admin &gt; Cards</strong>, localize o item e use a ação de edição para alterar dados e salvar as mudanças.</p>
    </details>
    <details class="db-faq-item">
        <summary>Quem pode criar e editar grupos, subgrupos e cards?</summary>
        <p>Apenas usuários com acesso ao menu <strong>Admin</strong> (perfil administrador autenticado) podem criar e editar grupos, subgrupos e cards no dashboard.</p>
    </details>
    <details class="db-faq-item">
        <summary>Como o Guardião me ajuda no dashboard?</summary>
        <p>O Guardião direciona você rapidamente para conteúdos de apoio, documentação e orientações de uso, reduzindo o tempo para encontrar informações importantes.</p>
    </details>
    <details class="db-faq-item">
        <summary>O botão do Guardião cobre algum botão da tela. O que faço?</summary>
        <p>Arraste o botão do Guardião para outro canto da tela. A posição é salva automaticamente no navegador.</p>
    </details>
    <details class="db-faq-item">
        <summary>Posso acessar esta FAQ pelo menu lateral?</summary>
        <p>Sim. No grupo <strong>Documentação</strong>, use o item <strong>FAQ do Guardião</strong> para abrir esta tela dentro da área interna do dashboard.</p>
    </details>
    <details class="db-faq-item">
        <summary>Como envio melhorias para o Guardião/FAQ?</summary>
        <p>Centralize sugestões na Subseção de Desenvolvimento de Sistemas (SDS) da Seção de Informática da OM para inclusão de novas perguntas e ajustes de texto conforme a rotina do batalhão, preferencialmente pelo ramal 6215 ou por outro de nossos canais.</p>
    </details>
</div>
HTML;

                return $twig->render($response, 'dashboard-readme.twig', array_merge([
                        'sections' => $sections,
                        'groupedSections' => $buildGroupedSections($sections, $repo->getAllGroups()),
                        'activeSection' => 'faq',
                        'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
                        'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
                        'lastUpdated' => $lastUpdated,
                        'readmePageTitle' => 'FAQ do Guardião',
                        'readmePageSubtitle' => 'Perguntas e respostas rápidas sobre uso do assistente virtual',
                        'readmeHtml' => $faqHtml,
                ], $navbarAuthContext()));
        });

    $app->get('/contato', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections) {
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

    $app->get('/solicitar-card', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections, $ensureCsrfToken, $requesterRanks) {
        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);

        $meta = $repo->getMeta();
        $sections = $repo->getSections();
        $groups = $repo->getAllGroups();
        $lastUpdated = date('d/m/Y H:i');

        return $twig->render($response, 'dashboard-card-request.twig', array_merge([
            'sections' => $sections,
            'groupedSections' => $buildGroupedSections($sections, $groups),
            'groups' => $groups,
            'requesterRanks' => $requesterRanks,
            'activeSection' => 'solicitar-card',
            'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
            'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
            'lastUpdated' => $lastUpdated,
            'csrfToken' => $ensureCsrfToken(),
            'formErrors' => [],
            'formSuccess' => false,
            'formData' => [
                'requester_rank' => '',
                'requester_name' => '',
                'requester_contact' => '',
                'title' => '',
                'href' => '',
                'group_slug' => '',
                'subgroup_slug' => '',
                'justification' => '',
                'website' => '',
            ],
        ], $navbarAuthContext()));
    });

    $app->post('/solicitar-card', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections, $ensureCsrfToken, $isValidCsrf, $requesterRanks, $requesterRanksMap) {
        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);

        $meta = $repo->getMeta();
        $sections = $repo->getSections();
        $groups = $repo->getAllGroups();
        $groupsBySlug = $repo->getGroupsBySlug();
        $lastUpdated = date('d/m/Y H:i');

        $parsedBody = $request->getParsedBody();
        $payload = is_array($parsedBody) ? $parsedBody : [];

        $formData = [
            'requester_name' => trim((string) ($payload['requester_name'] ?? '')),
            'requester_rank' => trim((string) ($payload['requester_rank'] ?? '')),
            'requester_contact' => trim((string) ($payload['requester_contact'] ?? '')),
            'title' => trim((string) ($payload['title'] ?? '')),
            'href' => trim((string) ($payload['href'] ?? '')),
            'group_slug' => strtolower(trim((string) ($payload['group_slug'] ?? ''))),
            'subgroup_slug' => strtolower(trim((string) ($payload['subgroup_slug'] ?? ''))),
            'justification' => trim((string) ($payload['justification'] ?? '')),
            'website' => trim((string) ($payload['website'] ?? '')),
        ];

        if ($formData['website'] !== '') {
            return $twig->render($response, 'dashboard-card-request.twig', array_merge([
                'sections' => $sections,
                'groupedSections' => $buildGroupedSections($sections, $groups),
                'groups' => $groups,
                'requesterRanks' => $requesterRanks,
                'activeSection' => 'solicitar-card',
                'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
                'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
                'lastUpdated' => $lastUpdated,
                'csrfToken' => $ensureCsrfToken(),
                'formErrors' => [],
                'formSuccess' => true,
                'formData' => [
                    'requester_rank' => '',
                    'requester_name' => '',
                    'requester_contact' => '',
                    'title' => '',
                    'href' => '',
                    'group_slug' => '',
                    'subgroup_slug' => '',
                    'justification' => '',
                    'website' => '',
                ],
            ], $navbarAuthContext()));
        }

        $formErrors = [];

        if (!$isValidCsrf($request)) {
            $formErrors['csrf'] = 'Sessão expirada. Atualize a página e tente novamente.';
        }

        if ($formData['requester_rank'] === '' || !isset($requesterRanksMap[$formData['requester_rank']])) {
            $formErrors['requester_rank'] = 'Selecione seu posto/graduação.';
        }

        if ($formData['requester_name'] === '') {
            $formErrors['requester_name'] = 'Informe seu nome.';
        }

        if ($formData['requester_contact'] === '') {
            $formErrors['requester_contact'] = 'Informe seu e-mail.';
        } elseif (filter_var($formData['requester_contact'], FILTER_VALIDATE_EMAIL) === false) {
            $formErrors['requester_contact'] = 'Informe um e-mail válido.';
        }

        if ($formData['title'] === '') {
            $formErrors['title'] = 'Informe o título do card.';
        }

        if ($formData['href'] === '') {
            $formErrors['href'] = 'Informe a URL completa (https://).';
        } elseif (preg_match('/^https:\/\//i', $formData['href']) !== 1) {
            $formErrors['href'] = 'A URL deve começar com https://';
        }

        if ($formData['group_slug'] === '' || !isset($groupsBySlug[$formData['group_slug']])) {
            $formErrors['group_slug'] = 'Selecione um grupo válido.';
        }

        if ($formData['subgroup_slug'] === '' || !isset($sections[$formData['subgroup_slug']])) {
            $formErrors['subgroup_slug'] = 'Selecione um subgrupo válido.';
        }

        if ($formData['subgroup_slug'] !== '' && isset($sections[$formData['subgroup_slug']])) {
            $sectionGroupSlug = strtolower((string) ($sections[$formData['subgroup_slug']]['group_slug'] ?? ''));
            if ($formData['group_slug'] !== '' && $sectionGroupSlug !== $formData['group_slug']) {
                $formErrors['subgroup_slug'] = 'O subgrupo selecionado não pertence ao grupo informado.';
            }
        }

        if ($formData['justification'] === '') {
            $formErrors['justification'] = 'Explique brevemente por que o card deve ser criado.';
        }

        $formSuccess = false;
        if ($formErrors === []) {
            $repo->createCardRequest([
                'requester_rank' => $formData['requester_rank'],
                'requester_name' => $formData['requester_name'],
                'requester_contact' => $formData['requester_contact'],
                'title' => $formData['title'],
                'href' => $formData['href'],
                'group_slug' => $formData['group_slug'],
                'subgroup_slug' => $formData['subgroup_slug'],
                'justification' => $formData['justification'],
                'status' => 'pending',
                'created_at' => date('c'),
            ]);

            $formSuccess = true;
            $formData = [
                'requester_rank' => '',
                'requester_name' => '',
                'requester_contact' => '',
                'title' => '',
                'href' => '',
                'group_slug' => '',
                'subgroup_slug' => '',
                'justification' => '',
                'website' => '',
            ];
        }

        return $twig->render($response, 'dashboard-card-request.twig', array_merge([
            'sections' => $sections,
            'groupedSections' => $buildGroupedSections($sections, $groups),
            'groups' => $groups,
            'requesterRanks' => $requesterRanks,
            'activeSection' => 'solicitar-card',
            'dashboardTitle' => $meta['title'] ?? 'Dashboard Público',
            'dashboardSubtitle' => $meta['subtitle'] ?? 'Painel público com cards dinâmicos por seção',
            'lastUpdated' => $lastUpdated,
            'csrfToken' => $ensureCsrfToken(),
            'formErrors' => $formErrors,
            'formSuccess' => $formSuccess,
            'formData' => $formData,
        ], $navbarAuthContext()));
    });

    $app->post('/contato', function (Request $request, Response $response) use ($app, $navbarAuthContext, $buildGroupedSections) {
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
};
