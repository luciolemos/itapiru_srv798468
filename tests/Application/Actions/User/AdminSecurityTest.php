<?php

declare(strict_types=1);

namespace Tests\Application\Actions\User;

use Tests\TestCase;

class AdminSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }

        $_SESSION = [];

        parent::tearDown();
    }

    public function testAdminLoginPostWithoutCsrfShowsSessionExpiredMessage(): void
    {
        $app = $this->getAppInstance();

        $request = $this->createRequest('POST', '/itapiru/login')->withParsedBody([
            'username' => 'admin',
            'password' => 'admin123',
        ]);

        $response = $app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Sessão expirada. Atualize a página e tente novamente.', (string) $response->getBody());
        $this->assertEmpty($_SESSION['is_admin'] ?? null);
    }

    public function testAdminLoginLocksAfterTooManyInvalidAttempts(): void
    {
        $app = $this->getAppInstance();

        $csrf = $this->fetchCsrfToken($app);

        $response = null;
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $request = $this->createRequest('POST', '/itapiru/login')->withParsedBody([
                'csrf_token' => $csrf,
                'username' => 'admin',
                'password' => 'senha-incorreta',
            ]);

            $response = $app->handle($request);
        }

        $this->assertNotNull($response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Muitas tentativas. Acesso bloqueado por 10 minutos.', (string) $response->getBody());

        $attemptState = $_SESSION['admin_login_attempts'] ?? null;
        $this->assertIsArray($attemptState);
        $this->assertGreaterThan(time(), (int) ($attemptState['locked_until'] ?? 0));
    }

    public function testAdminLoginWhileLockedShowsRemainingSecondsMessage(): void
    {
        $app = $this->getAppInstance();

        $csrf = $this->fetchCsrfToken($app);
        $_SESSION['admin_login_attempts'] = [
            'count' => 0,
            'first' => time(),
            'locked_until' => time() + 120,
        ];

        $request = $this->createRequest('POST', '/itapiru/login')->withParsedBody([
            'csrf_token' => $csrf,
            'username' => 'admin',
            'password' => 'admin123',
        ]);

        $response = $app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Muitas tentativas. Tente novamente em', (string) $response->getBody());
        $this->assertEmpty($_SESSION['is_admin'] ?? null);
    }

    public function testAdminSectionCreateWithInvalidCsrfSetsFlashAndRedirects(): void
    {
        $app = $this->getAppInstance();

        $_SESSION['is_admin'] = true;
        $_SESSION['admin_user'] = 'admin';
        $_SESSION['csrf_token'] = 'token-valido';

        $request = $this->createRequest('POST', '/itapiru/admin/sections/create')->withParsedBody([
            'csrf_token' => 'token-invalido',
            'slug' => 'nova-secao',
            'label' => 'Nova Seção',
            'description' => 'Descrição',
            'order' => 1,
        ]);

        $response = $app->handle($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/itapiru/admin?entity=subgroups', $response->getHeaderLine('Location'));
        $this->assertEquals('Falha de validação CSRF. Atualize a página e tente novamente.', $_SESSION['admin_flash'] ?? null);
    }

    public function testAdminLoginWithValidCsrfAndCredentialsRedirectsAndResetsAttempts(): void
    {
        $app = $this->getAppInstance();

        $csrf = $this->fetchCsrfToken($app);
        $_SESSION['admin_login_attempts'] = [
            'count' => 3,
            'first' => time() - 120,
            'locked_until' => 0,
        ];

        $request = $this->createRequest('POST', '/itapiru/login')->withParsedBody([
            'csrf_token' => $csrf,
            'username' => 'admin',
            'password' => 'admin123',
        ]);

        $response = $app->handle($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/itapiru/admin', $response->getHeaderLine('Location'));
        $this->assertTrue((bool) ($_SESSION['is_admin'] ?? false));
        $this->assertEquals('admin', $_SESSION['admin_user'] ?? null);

        $attemptState = $_SESSION['admin_login_attempts'] ?? null;
        $this->assertIsArray($attemptState);
        $this->assertSame(0, (int) ($attemptState['count'] ?? -1));
        $this->assertSame(0, (int) ($attemptState['locked_until'] ?? -1));
    }

    public function testAdminLogoutWithInvalidCsrfKeepsSessionAndRedirectsToAdmin(): void
    {
        $app = $this->getAppInstance();

        $_SESSION['is_admin'] = true;
        $_SESSION['admin_user'] = 'admin';
        $_SESSION['csrf_token'] = 'token-valido';

        $request = $this->createRequest('POST', '/itapiru/logout')->withParsedBody([
            'csrf_token' => 'token-invalido',
        ]);

        $response = $app->handle($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/itapiru/admin', $response->getHeaderLine('Location'));
        $this->assertTrue((bool) ($_SESSION['is_admin'] ?? false));
        $this->assertEquals('admin', $_SESSION['admin_user'] ?? null);
    }

    public function testAdminLogoutWithValidCsrfClearsSessionAndRedirectsToLogin(): void
    {
        $app = $this->getAppInstance();

        $_SESSION['is_admin'] = true;
        $_SESSION['admin_user'] = 'admin';
        $_SESSION['csrf_token'] = 'token-valido';

        $request = $this->createRequest('POST', '/itapiru/logout')->withParsedBody([
            'csrf_token' => 'token-valido',
        ]);

        $response = $app->handle($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/itapiru/login', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('is_admin', $_SESSION);
        $this->assertArrayNotHasKey('admin_user', $_SESSION);
    }

    private function fetchCsrfToken($app): string
    {
        $response = $app->handle($this->createRequest('GET', '/itapiru/login'));

        $this->assertEquals(200, $response->getStatusCode());

        $token = $_SESSION['csrf_token'] ?? null;
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        return $token;
    }
}
