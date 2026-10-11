<?php

namespace Dunea\MemberPromotion\Tests\integration\Api;

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;

class AdminAuthorizationTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('dunea-member-promotion');
        $this->prepareDatabase(['users' => [$this->normalUser()]]);
    }

    public function test_guest_cannot_access_admin_endpoint(): void
    {
        $response = $this->send($this->request('GET', '/api/member-promotion/dashboard'));
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_normal_user_cannot_access_admin_endpoint(): void
    {
        $response = $this->send($this->request('GET', '/api/member-promotion/dashboard', ['authenticatedAs' => 2]));
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_admin_can_access_admin_endpoint(): void
    {
        $response = $this->send($this->request('GET', '/api/member-promotion/dashboard', ['authenticatedAs' => 1]));
        $this->assertEquals(200, $response->getStatusCode());

        $settings = json_decode((string) $response->getBody(), true)['settings'];
        $this->assertArrayNotHasKey('batchSize', $settings);
        $this->assertArrayNotHasKey('timeBudgetSeconds', $settings);
    }

    public function test_settings_endpoint_ignores_removed_runtime_limit_fields(): void
    {
        $this->setting('dunea-member-promotion.batch_size', '7');
        $this->setting('dunea-member-promotion.time_budget_seconds', '8');
        $request = $this->requestWithJsonBody(
            $this->request('POST', '/api/member-promotion/settings', ['authenticatedAs' => 1]),
            [
                'enabled' => false,
                'excludedGroupIds' => [],
                'batchSize' => 999999,
                'timeBudgetSeconds' => 999999,
                'logRetentionDays' => 90,
            ]
        );

        $response = $this->send($request);
        $settings = $this->app()->getContainer()->make('flarum.settings');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('7', $settings->get('dunea-member-promotion.batch_size'));
        $this->assertSame('8', $settings->get('dunea-member-promotion.time_budget_seconds'));
    }
}
