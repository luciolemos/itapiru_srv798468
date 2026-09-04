<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Dashboard\DashboardRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\App;
use Slim\Views\Twig;

return static function (App $app, callable $ensureCsrfToken, callable $isValidCsrf, callable $flashPull, callable $navbarAuthContext, callable $buildGroupedSections, array $allowedAdminAvatarFiles, callable $normalizeAdminAvatarFile, callable $adminAvatarConfigKey, callable $isValidUploadedAvatarPath, callable $resolveAdminAvatarStoredValue, callable $normalizeAdminUsername): void {
    $app->get('/admin/avatar', function (Request $request, Response $response) use ($resolveAdminAvatarStoredValue, $normalizeAdminAvatarFile, $isValidUploadedAvatarPath) {
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


    $app->get('/admin/account', function (Request $request, Response $response) use ($app, $ensureCsrfToken, $flashPull, $navbarAuthContext, $buildGroupedSections, $allowedAdminAvatarFiles, $normalizeAdminAvatarFile, $adminAvatarConfigKey, $isValidUploadedAvatarPath) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', app_url('/login'))->withStatus(302);
        }

        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $twig = $app->getContainer()->get(Twig::class);
        $meta = $repo->getMeta();
        $sections = $repo->getSections();
        $adminUsername = (string) ($_SESSION['admin_user'] ?? 'admin');

        $storedAvatar = trim($repo->getConfigValue($adminAvatarConfigKey($adminUsername), 'face6_620_620.png'));
        $selectedAvatar = $normalizeAdminAvatarFile($storedAvatar);
        $currentAvatarUrl = app_url('/admin/avatar?v=' . rawurlencode(substr(sha1($storedAvatar), 0, 12)));
        $isCustomAvatar = false;

        if ($isValidUploadedAvatarPath($storedAvatar)) {
            $isCustomAvatar = true;
            $selectedAvatar = '';
        }

        $avatarOptions = array_map(
            static fn (string $filename): array => [
                'filename' => $filename,
                'url' => app_url('/assets/img/avatar/' . $filename),
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

    $app->post('/admin/account/update', function (Request $request, Response $response) use ($app, $isValidCsrf, $allowedAdminAvatarFiles, $normalizeAdminAvatarFile, $adminAvatarConfigKey, $normalizeAdminUsername) {
        if (empty($_SESSION['is_admin'])) {
            return $response->withHeader('Location', app_url('/login'))->withStatus(302);
        }

        if (!$isValidCsrf($request)) {
            $_SESSION['admin_flash'] = 'Falha de validação CSRF. Atualize a página e tente novamente.';
            return $response->withHeader('Location', app_url('/admin/account'))->withStatus(302);
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
            return $response->withHeader('Location', app_url('/admin/account'))->withStatus(302);
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
            return $response->withHeader('Location', app_url('/admin/account'))->withStatus(302);
        }

        if ($avatarUpload instanceof UploadedFileInterface && !in_array($avatarUpload->getError(), [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE], true)) {
            $_SESSION['admin_flash'] = 'Falha no upload da foto. Tente novamente.';
            return $response->withHeader('Location', app_url('/admin/account'))->withStatus(302);
        }

        $wantsPasswordChange = ($newPassword !== '' || $confirmPassword !== '');
        $newPasswordHash = null;

        if ($wantsPasswordChange) {
            if ($currentPassword === '') {
                $_SESSION['admin_flash'] = 'Informe a senha atual para redefinir a senha.';
                return $response->withHeader('Location', app_url('/admin/account'))->withStatus(302);
            }

            if (!$repo->verifyAdmin($currentUsername, $currentPassword)) {
                $_SESSION['admin_flash'] = 'Senha atual inválida.';
                return $response->withHeader('Location', app_url('/admin/account'))->withStatus(302);
            }

            if ($newPassword === '' || mb_strlen($newPassword) < 8) {
                $_SESSION['admin_flash'] = 'A nova senha deve ter pelo menos 8 caracteres.';
                return $response->withHeader('Location', app_url('/admin/account'))->withStatus(302);
            }

            if ($newPassword !== $confirmPassword) {
                $_SESSION['admin_flash'] = 'A confirmação da nova senha não confere.';
                return $response->withHeader('Location', app_url('/admin/account'))->withStatus(302);
            }

            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        if ($hasUploadedAvatar) {
            $maxSize = 2 * 1024 * 1024;
            if ($avatarUpload->getSize() > $maxSize) {
                $_SESSION['admin_flash'] = 'A foto deve ter no máximo 2MB.';
                return $response->withHeader('Location', app_url('/admin/account'))->withStatus(302);
            }

            $clientFilename = strtolower((string) ($avatarUpload->getClientFilename() ?? ''));
            $extension = pathinfo($clientFilename, PATHINFO_EXTENSION);
            $allowedExtensions = ['png', 'jpg', 'jpeg', 'webp'];
            if (!in_array($extension, $allowedExtensions, true)) {
                $_SESSION['admin_flash'] = 'Formato de foto inválido. Use PNG, JPG, JPEG ou WEBP.';
                return $response->withHeader('Location', app_url('/admin/account'))->withStatus(302);
            }

            $uploadDir = dirname(__DIR__) . '/public/assets/img/avatar/uploads';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                $_SESSION['admin_flash'] = 'Falha ao preparar diretório de upload da foto.';
                return $response->withHeader('Location', app_url('/admin/account'))->withStatus(302);
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
                return $response->withHeader('Location', app_url('/admin/account'))->withStatus(302);
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

            return $response->withHeader('Location', app_url('/admin/account'))->withStatus(302);
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

        return $response->withHeader('Location', app_url('/admin?entity=sections'))->withStatus(302);
    });

    $app->get('/admin/preferences', function (Request $request, Response $response) {
        return $response->withHeader('Location', app_url('/admin/account'))->withStatus(302);
    });

    $app->post('/admin/preferences/avatar', function (Request $request, Response $response) {
        return $response->withHeader('Location', app_url('/admin/account'))->withStatus(302);
    });
};
