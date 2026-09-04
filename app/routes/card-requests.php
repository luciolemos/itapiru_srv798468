<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Dashboard\DashboardRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return static function (App $app, callable $isValidCsrf, callable $normalizeHref): void {
    $app->post('/admin/card-requests/approve', function (Request $request, Response $response) use ($app, $isValidCsrf, $normalizeHref) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', app_url('/login'))->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha ao aprovar solicitação: token CSRF inválido.';
            return $response->withHeader('Location', app_url('/admin?entity=requests'))->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $data = (array) ($request->getParsedBody() ?? []);
        $id = (int) ($data['id'] ?? 0);
        $requestRow = $repo->getCardRequestById($id);

        if (!$requestRow || (string) ($requestRow['status'] ?? '') !== 'pending') {
            $_SESSION['admin_flash'] = 'Solicitação inválida ou já processada.';
            return $response->withHeader('Location', app_url('/admin?entity=requests'))->withStatus(302);
        }

        $sectionSlug = strtolower(trim((string) ($requestRow['subgroup_slug'] ?? '')));
        $title = trim((string) ($requestRow['title'] ?? ''));
        $href = $normalizeHref((string) ($requestRow['href'] ?? ''));
        $description = trim((string) ($requestRow['justification'] ?? ''));

        if ($sectionSlug === '' || $title === '' || preg_match('/^https:\/\//i', $href) !== 1) {
            $_SESSION['admin_flash'] = 'Falha ao aprovar: dados da solicitação estão incompletos.';
            return $response->withHeader('Location', app_url('/admin?entity=requests'))->withStatus(302);
        }

        try {
            $createdCardId = $repo->createCard([
                'section_slug' => $sectionSlug,
                'title' => $title,
                'href' => $href,
                'external' => true,
                'icon' => 'bi-globe2',
                'status' => 'Externo',
                'description' => $description,
                'order' => 99,
            ]);
            $repo->updateCardRequestStatus($id, 'approved', (string) ($_SESSION['admin_user'] ?? 'admin'), $createdCardId);
            $_SESSION['admin_flash'] = 'Solicitação aprovada e card criado com sucesso.';
        } catch (\Throwable $throwable) {
            $_SESSION['admin_flash'] = 'Falha ao aprovar solicitação: ' . $throwable->getMessage();
        }

        return $response->withHeader('Location', app_url('/admin?entity=requests'))->withStatus(302);
    });

    $app->post('/admin/card-requests/reject', function (Request $request, Response $response) use ($app, $isValidCsrf) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', app_url('/login'))->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha ao rejeitar solicitação: token CSRF inválido.';
            return $response->withHeader('Location', app_url('/admin?entity=requests'))->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $data = (array) ($request->getParsedBody() ?? []);
        $id = (int) ($data['id'] ?? 0);
        $note = trim((string) ($data['admin_note'] ?? ''));
        $requestRow = $repo->getCardRequestById($id);

        if (!$requestRow || (string) ($requestRow['status'] ?? '') !== 'pending') {
            $_SESSION['admin_flash'] = 'Solicitação inválida ou já processada.';
            return $response->withHeader('Location', app_url('/admin?entity=requests'))->withStatus(302);
        }

        $repo->updateCardRequestStatus($id, 'rejected', (string) ($_SESSION['admin_user'] ?? 'admin'), null, $note);
        $_SESSION['admin_flash'] = 'Solicitação rejeitada.';

        return $response->withHeader('Location', app_url('/admin?entity=requests'))->withStatus(302);
    });
};
