<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Persistence;

use App\Infrastructure\Persistence\Dashboard\DashboardRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DashboardRepositoryTest extends TestCase
{
    private string $databasePath;

    /** @var array<string, string|null> */
    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->databasePath = sys_get_temp_dir() . '/itapiru-dashboard-repository-' . uniqid('', true) . '.sqlite';
        foreach (['APP_ENV', 'ADMIN_USER', 'ADMIN_PASS'] as $key) {
            $this->environment[$key] = isset($_ENV[$key]) ? (string) $_ENV[$key] : null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                continue;
            }

            $_ENV[$key] = $value;
        }

        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function testNewProductionDatabaseRequiresInitialAdminCredentials(): void
    {
        $_ENV['APP_ENV'] = 'production';
        unset($_ENV['ADMIN_USER'], $_ENV['ADMIN_PASS']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('new production database requires ADMIN_USER and ADMIN_PASS');

        $this->createRepository();
    }

    public function testNewProductionDatabaseCreatesConfiguredAdministrator(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['ADMIN_USER'] = 'initial-admin';
        $_ENV['ADMIN_PASS'] = 'a-secure-initial-password';

        $repository = $this->createRepository();

        self::assertTrue($repository->verifyAdmin('initial-admin', 'a-secure-initial-password'));
    }

    private function createRepository(): DashboardRepository
    {
        return new DashboardRepository(
            $this->databasePath,
            dirname(__DIR__, 3) . '/app/content/dashboard.php'
        );
    }
}
