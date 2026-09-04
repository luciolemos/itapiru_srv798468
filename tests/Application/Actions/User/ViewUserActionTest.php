<?php

declare(strict_types=1);

namespace Tests\Application\Actions\User;

use App\Infrastructure\Persistence\Dashboard\DashboardRepository;
use Tests\TestCase;

class ViewUserActionTest extends TestCase
{
    public function testDashboardSectionRouteRespondsWithSuccess()
    {
        $app = $this->getAppInstance();

        $request = $this->createRequest('GET', '/itapiru/secao-1');
        $response = $app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('1ª Seção', (string) $response->getBody());
    }

    public function testInvalidDashboardSectionRedirectsToDashboardHome()
    {
        $app = $this->getAppInstance();

        $request = $this->createRequest('GET', '/itapiru/secao-inexistente');
        $response = $app->handle($request);

        $this->assertEquals(302, $response->getStatusCode());
        $this->assertEquals('/itapiru', $response->getHeaderLine('Location'));
    }

    public function testAdminOnlyGroupIsHiddenFromVisitorsAndAccessibleToAdministrators(): void
    {
        $app = $this->getAppInstance();
        /** @var DashboardRepository $repo */
        $repo = $app->getContainer()->get(DashboardRepository::class);
        $group = null;

        foreach ($repo->getAllGroups() as $candidate) {
            if ((int) ($candidate['subgroups_count'] ?? 0) > 0) {
                $group = $candidate;
                break;
            }
        }

        $this->assertNotNull($group);
        $groupSlug = (string) $group['slug'];
        $sectionSlug = '';
        foreach ($repo->getSections() as $slug => $section) {
            if ((string) ($section['group_slug'] ?? '') === $groupSlug) {
                $sectionSlug = (string) $slug;
                break;
            }
        }

        $this->assertNotSame('', $sectionSlug);
        $repo->setGroupVisibility($groupSlug, 'admin');

        try {
            $visitorHomeResponse = $app->handle($this->createRequest('GET', '/itapiru'));
            $menuLinkPattern = '/href="\/itapiru\/' . preg_quote($sectionSlug, '/') . '"\s+hx-boost="false"\s+class="db-menu-item db-menu-subitem"/';
            $this->assertDoesNotMatchRegularExpression(
                $menuLinkPattern,
                (string) $visitorHomeResponse->getBody()
            );

            $visitorResponse = $app->handle($this->createRequest('GET', '/itapiru/' . $sectionSlug));
            $this->assertEquals(302, $visitorResponse->getStatusCode());
            $this->assertEquals('/itapiru', $visitorResponse->getHeaderLine('Location'));

            $_SESSION['is_admin'] = true;
            $_SESSION['admin_user'] = 'admin';
            $adminHomeResponse = $app->handle($this->createRequest('GET', '/itapiru'));
            $this->assertMatchesRegularExpression(
                $menuLinkPattern,
                (string) $adminHomeResponse->getBody()
            );

            $adminResponse = $app->handle($this->createRequest('GET', '/itapiru/' . $sectionSlug));
            $this->assertEquals(200, $adminResponse->getStatusCode());
        } finally {
            $repo->setGroupVisibility($groupSlug, 'public');
            $_SESSION = [];
        }
    }
}
