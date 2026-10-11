<?php

namespace Dunea\MemberPromotion\Tests\integration;

use Carbon\Carbon;
use Dunea\MemberPromotion\Service\Evaluator;
use Dunea\MemberPromotion\Service\RuleService;
use Dunea\MemberPromotion\Listener\ManualGroupChangeListener;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\Event\GroupsChanged;
use Flarum\User\User;

class EvaluatorTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('dunea-member-promotion');
        $this->setting('dunea-member-promotion.enabled', '1');
        $this->prepareDatabase([
            'users' => [array_merge($this->normalUser(), ['joined_at' => Carbon::now()->subDays(10)])],
            'groups' => [
                ['id' => 5, 'name_singular' => '正式会员', 'name_plural' => '正式会员', 'is_hidden' => 0],
                ['id' => 6, 'name_singular' => '活跃会员', 'name_plural' => '活跃会员', 'is_hidden' => 0],
                ['id' => 7, 'name_singular' => '其他身份', 'name_plural' => '其他身份', 'is_hidden' => 0],
            ],
            'member_promotion_counters' => [['user_id' => 2, 'valid_replies' => 20, 'initialized' => 1, 'updated_at' => Carbon::now()]],
            'member_promotion_rules' => [
                $this->rule(1, null, 5, 1),
                $this->rule(2, 5, 6, 2),
            ],
        ]);
    }

    public function test_multiple_stages_promote_continuously_and_idempotently(): void
    {
        $evaluator = $this->app()->getContainer()->make(Evaluator::class);
        $evaluator->evaluate(2);
        $db = $this->app()->getContainer()->make('db');

        $this->assertSame([5, 6], array_map('intval', $db->table('group_user')->where('user_id', 2)->orderBy('group_id')->pluck('group_id')->all()));
        $this->assertSame(2, $db->table('member_promotion_grants')->where('user_id', 2)->count());

        $evaluator->evaluate(2);
        $this->assertSame(2, $db->table('member_promotion_grants')->where('user_id', 2)->count());
        $this->assertSame(2, $db->table('member_promotion_logs')->count(), 'No-op reevaluation must not append audit rows.');
    }

    public function test_dry_run_has_no_business_writes(): void
    {
        $db = $this->app()->getContainer()->make('db');
        $report = $this->app()->getContainer()->make(Evaluator::class)->evaluate(2, true);

        $this->assertSame('would_promote', $report['rules'][0]['status']);
        $this->assertSame(0, $db->table('group_user')->where('user_id', 2)->count());
        $this->assertSame(0, $db->table('member_promotion_grants')->count());
        $this->assertSame(0, $db->table('member_promotion_logs')->count());
        $this->assertSame(0, $db->table('member_promotion_pending')->count());
    }

    public function test_evaluation_prefetches_rule_related_data_in_constant_queries(): void
    {
        $container = $this->app()->getContainer();
        $db = $container->make('db');
        $evaluator = $container->make(Evaluator::class);
        $activeRules = $container->make(RuleService::class)->activeOrdered();
        $evaluator->evaluate(2, true, null, $activeRules); // Warm schema and immutable group metadata caches.

        $db->flushQueryLog();
        $db->enableQueryLog();
        $evaluator->evaluate(2, true, null, $activeRules);
        $queries = $db->getQueryLog();
        $db->disableQueryLog();

        $this->assertLessThanOrEqual(4, count($queries), 'Rule checks must not add per-rule SQL queries.');
        $this->assertSame(1, count(array_filter($queries, fn (array $query) => str_contains($query['query'], 'member_promotion_grants'))));
        $this->assertSame(1, count(array_filter($queries, fn (array $query) => str_contains($query['query'], 'member_promotion_blocks'))));
    }

    public function test_dry_run_can_preview_while_automatic_promotion_is_disabled(): void
    {
        $this->setting('dunea-member-promotion.enabled', '0');
        $report = $this->app()->getContainer()->make(Evaluator::class)->evaluate(2, true);

        $this->assertSame('would_promote', $report['rules'][0]['status']);
        $this->assertNull($report['reason']);
    }

    public function test_missing_prerequisite_skips_dependent_rule(): void
    {
        $db = $this->app()->getContainer()->make('db');
        $db->table('member_promotion_rules')->where('id', 1)->update(['enabled' => 0]);
        $report = $this->app()->getContainer()->make(Evaluator::class)->evaluate(2);

        $this->assertContains('prerequisite_missing', $report['rules'][0]['reasons']);
        $this->assertFalse($db->table('group_user')->where('user_id', 2)->where('group_id', 6)->exists());
    }

    public function test_manual_target_membership_satisfies_next_stage_without_fake_grant(): void
    {
        $db = $this->app()->getContainer()->make('db');
        $db->table('group_user')->insert(['user_id' => 2, 'group_id' => 5]);
        $this->app()->getContainer()->make(Evaluator::class)->evaluate(2);

        $this->assertFalse($db->table('member_promotion_grants')->where('rule_id', 1)->exists());
        $this->assertTrue($db->table('group_user')->where('user_id', 2)->where('group_id', 6)->exists());
    }

    public function test_missing_target_group_is_a_failure_reason_not_a_crash(): void
    {
        $db = $this->app()->getContainer()->make('db');
        $db->table('groups')->where('id', 6)->delete();
        $report = $this->app()->getContainer()->make(Evaluator::class)->evaluate(2);

        $this->assertContains('target_group_missing', $report['rules'][1]['reasons']);
    }

    public function test_global_disable_and_user_pause_gate_promotions(): void
    {
        $this->setting('dunea-member-promotion.enabled', '0');
        $evaluator = $this->app()->getContainer()->make(Evaluator::class);
        $db = $this->app()->getContainer()->make('db');
        $report = $evaluator->evaluate(2);
        $this->assertSame('global_disabled', $report['reason']);
        $this->assertSame(0, $db->table('group_user')->where('user_id', 2)->count());
    }

    public function test_paused_excluded_and_moderator_users_are_gated(): void
    {
        $this->setting('dunea-member-promotion.excluded_group_ids', '[7]');
        $db = $this->app()->getContainer()->make('db');
        $evaluator = $this->app()->getContainer()->make(Evaluator::class);

        $db->table('member_promotion_user_states')->insert(['user_id' => 2, 'paused' => 1, 'updated_at' => Carbon::now()]);
        $this->assertSame('user_paused', $evaluator->evaluate(2)['reason']);
        $db->table('member_promotion_user_states')->where('user_id', 2)->delete();

        $db->table('group_user')->insert(['user_id' => 2, 'group_id' => 7]);
        $this->assertSame('excluded_group', $evaluator->evaluate(2)['reason']);
        $db->table('group_user')->where('user_id', 2)->where('group_id', 7)->delete();

        $db->table('group_user')->insert(['user_id' => 2, 'group_id' => 4]);
        $this->assertSame('moderator', $evaluator->evaluate(2)['reason']);
    }

    public function test_missing_membership_after_grant_creates_block_and_is_not_restored(): void
    {
        $db = $this->app()->getContainer()->make('db');
        $evaluator = $this->app()->getContainer()->make(Evaluator::class);
        $evaluator->evaluate(2);
        $db->table('group_user')->where('user_id', 2)->where('group_id', 6)->delete();

        $report = $evaluator->evaluate(2);
        $this->assertContains('manual_revoke_detected', $report['rules'][1]['reasons']);
        $this->assertTrue($db->table('member_promotion_blocks')->where('user_id', 2)->where('rule_id', 2)->whereNull('cleared_at')->exists());
        $this->assertFalse($db->table('group_user')->where('user_id', 2)->where('group_id', 6)->exists());
    }

    public function test_structural_target_change_does_not_migrate_historical_member(): void
    {
        $db = $this->app()->getContainer()->make('db');
        $evaluator = $this->app()->getContainer()->make(Evaluator::class);
        $evaluator->evaluate(2);
        $db->table('member_promotion_rules')->where('id', 2)->update(['target_group_id' => 7, 'version' => 2]);

        $report = $evaluator->evaluate(2);
        $this->assertContains('historical_structure_retained', $report['rules'][1]['reasons']);
        $this->assertFalse($db->table('group_user')->where('user_id', 2)->where('group_id', 7)->exists());
    }

    public function test_prerequisite_is_removed_only_when_plugin_source_is_proven(): void
    {
        $db = $this->app()->getContainer()->make('db');
        $db->table('member_promotion_rules')->where('id', 2)->update(['remove_prerequisite' => 1]);
        $db->table('group_user')->insert(['user_id' => 2, 'group_id' => 7]);
        $this->app()->getContainer()->make(Evaluator::class)->evaluate(2);

        $this->assertFalse($db->table('group_user')->where('user_id', 2)->where('group_id', 5)->exists());
        $this->assertTrue($db->table('group_user')->where('user_id', 2)->where('group_id', 6)->exists());
        $this->assertTrue($db->table('group_user')->where('user_id', 2)->where('group_id', 7)->exists());
        $this->assertSame('consumed', $db->table('member_promotion_grants')->where('rule_id', 1)->value('status'));
    }

    public function test_consumed_stage_is_not_regranted_after_rule_version_changes(): void
    {
        $db = $this->app()->getContainer()->make('db');
        $evaluator = $this->app()->getContainer()->make(Evaluator::class);
        $db->table('member_promotion_rules')->where('id', 2)->update(['remove_prerequisite' => 1]);
        $evaluator->evaluate(2);
        $this->assertFalse($db->table('group_user')->where('user_id', 2)->where('group_id', 5)->exists());

        $db->table('member_promotion_rules')->where('id', 1)->update(['version' => 2, 'replies_count' => 5]);
        $report = $evaluator->evaluate(2);

        $this->assertSame('granted_then_consumed_by_later_stage', $report['rules'][0]['reasons'][0]);
        $this->assertFalse($db->table('group_user')->where('user_id', 2)->where('group_id', 5)->exists());
        $this->assertSame(1, $db->table('member_promotion_grants')->where('rule_id', 1)->count());
    }

    public function test_removing_a_manually_granted_target_creates_a_block(): void
    {
        $container = $this->app()->getContainer();
        $db = $container->make('db');
        $db->table('group_user')->insert(['user_id' => 2, 'group_id' => 5]);
        $group = Group::findOrFail(5);
        $user = User::findOrFail(2);
        $db->table('group_user')->where('user_id', 2)->where('group_id', 5)->delete();

        $container->make(ManualGroupChangeListener::class)(new GroupsChanged($user, [$group], User::findOrFail(1)));
        $container->make(Evaluator::class)->evaluate(2);

        $this->assertTrue($db->table('member_promotion_blocks')->where('user_id', 2)->where('rule_id', 1)->whereNull('cleared_at')->exists());
        $this->assertFalse($db->table('group_user')->where('user_id', 2)->where('group_id', 5)->exists());
    }

    public function test_rule_update_applies_admin_camel_case_fields_over_stored_values(): void
    {
        $container = $this->app()->getContainer();
        $result = $container->make(RuleService::class)->update(2, [
            'name' => '更新后的规则',
            'priority' => 50,
            'prerequisiteGroupId' => '',
            'targetGroupId' => 7,
            'removePrerequisite' => true,
            'ageEnabled' => true,
            'ageValue' => 48,
            'ageUnit' => 'hours',
            'ageOperator' => '>',
            'repliesEnabled' => true,
            'repliesCount' => 25,
            'repliesOperator' => '>',
        ], 1, true);

        $rule = $result['rule'];
        $this->assertSame('更新后的规则', $rule->name);
        $this->assertNull($rule->prerequisite_group_id);
        $this->assertSame(7, (int) $rule->target_group_id);
        $this->assertSame(2880, (int) $rule->age_minutes);
        $this->assertSame(25, (int) $rule->replies_count);
        $this->assertSame('>', $rule->age_operator);
        $this->assertSame('>', $rule->replies_operator);
        $this->assertSame(2, (int) $rule->version);
    }

    private function rule(int $id, ?int $prerequisite, int $target, int $priority): array
    {
        return [
            'id' => $id, 'name' => 'R'.$id, 'enabled' => 1, 'archived' => 0, 'priority' => $priority,
            'prerequisite_group_id' => $prerequisite, 'target_group_id' => $target, 'remove_prerequisite' => 0,
            'age_enabled' => 0, 'age_minutes' => null, 'age_operator' => '>=',
            'replies_enabled' => 1, 'replies_count' => 10, 'replies_operator' => '>=',
            'version' => 1, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ];
    }
}
