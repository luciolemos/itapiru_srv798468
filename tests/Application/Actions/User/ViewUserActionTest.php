<?php

declare(strict_types=1);

namespace Tests\Application\Actions\User;

use Tests\TestCase;

class ViewUserActionTest extends TestCase
{
    public function testDashboardSectionRouteRespondsWithSuccess()
    {
        $app = $this->getAppInstance();

        $request = $this->createRequest('GET', '/itapiru/1secao');
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
}
