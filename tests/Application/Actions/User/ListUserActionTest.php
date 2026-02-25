<?php

declare(strict_types=1);

namespace Tests\Application\Actions\User;

use Tests\TestCase;

class ListUserActionTest extends TestCase
{
    public function testDashboardHomeRouteRespondsWithSuccess()
    {
        $app = $this->getAppInstance();

        $request = $this->createRequest('GET', '/itapiru');
        $response = $app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Dashboard Público', (string) $response->getBody());
    }
}
