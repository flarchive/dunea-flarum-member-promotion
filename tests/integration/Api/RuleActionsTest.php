<?php

namespace Dunea\MemberPromotion\Tests\integration\Api;

use Carbon\Carbon;
use Dunea\MemberPromotion\Service\RuleService;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;

class RuleActionsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('dunea-member-promotion');
        $now = Carbon::now('UTC');
        $this->prepareDatabase([
            'users' => [$this->normalUser()],
            'groups' => [['id' => 5, 'name_singular' => '正式会员', 'name_plural' => '正式会员', 'is_hidden' => 0]],
            'member_promotion_rules' => [[
                'id' => 1,
                'name' => '正式会员',
                'description' => null,
                'enabled' => 1,
                'archived' => 0,
                'priority' => 0,
                'prerequisite_group_id' => null,
                'target_group_id' => 5,
                'remove_prerequisite' => 0,
                'age_enabled' => 1,
                'age_minutes' => 4320,
                'age_operator' => '>',
                'replies_enabled' => 1,
                'replies_count' => 10,
                'replies_operator' => '>',
                'version' => 1,
                'created_by' => 1,
                'updated_by' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            'member_promotion_grants' => [[
                'id' => 1,
                'user_id' => 2,
                'rule_id' => 1,
                'rule_version' => 1,
                'group_id' => 5,
                'status' => 'active',
                'granted_at' => $now,
                'ended_at' => null,
            ]],
        ]);
    }

    public function test_delete_hides_rule_but_retains_audit_dependencies(): void
    {
        $response = $this->send($this->request('DELETE', '/api/member-promotion/rules/1', ['authenticatedAs' => 1]));
        $this->assertSame(204, $response->getStatusCode());

        $db = $this->app()->getContainer()->make('db');
        $rule = $db->table('member_promotion_rules')->find(1);
        $this->assertNotNull($rule);
        $this->assertSame(0, (int) $rule->enabled);
        $this->assertSame(1, (int) $rule->archived);
        $this->assertTrue($db->table('member_promotion_grants')->where('rule_id', 1)->exists());

        $dashboard = $this->send($this->request('GET', '/api/member-promotion/dashboard', ['authenticatedAs' => 1]));
        $payload = json_decode((string) $dashboard->getBody(), true);
        $this->assertSame([], $payload['rules']);
    }

    public function test_deleted_rule_cannot_be_copied_or_deleted_again(): void
    {
        $this->send($this->request('DELETE', '/api/member-promotion/rules/1', ['authenticatedAs' => 1]));

        $copy = $this->send($this->request('POST', '/api/member-promotion/rules/1/copy', ['authenticatedAs' => 1]));
        $delete = $this->send($this->request('DELETE', '/api/member-promotion/rules/1', ['authenticatedAs' => 1]));

        $this->assertSame(422, $copy->getStatusCode());
        $this->assertSame(422, $delete->getStatusCode());
    }

    public function test_disabled_copy_and_metadata_only_update_do_not_schedule_full_scans(): void
    {
        $container = $this->app()->getContainer();
        $db = $container->make('db');
        $rules = $container->make(RuleService::class);

        $copy = $rules->copy(1, 1);
        $this->assertSame(0, (int) $copy->enabled);
        $this->assertSame(0, $db->table('member_promotion_jobs')->count());

        $rules->update(1, ['description' => '仅修改说明'], 1, true);
        $this->assertSame(0, $db->table('member_promotion_jobs')->count());

        $rules->update(1, ['repliesCount' => 11], 1, true);
        $this->assertSame(1, $db->table('member_promotion_jobs')->where('type', 'scan_users')->count());
    }
}
