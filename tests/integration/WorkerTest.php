<?php

namespace Dunea\MemberPromotion\Tests\integration;

use Carbon\Carbon;
use Dunea\MemberPromotion\Service\JobService;
use Dunea\MemberPromotion\Service\PendingService;
use Dunea\MemberPromotion\Service\Worker;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;

class WorkerTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('dunea-member-promotion');
        $this->setting('dunea-member-promotion.enabled', '1');
        $this->prepareDatabase([
            'users' => [
                array_merge($this->normalUser(), ['joined_at' => Carbon::now()->subDay()]),
                array_merge($this->normalUser(), [
                    'id' => 3,
                    'username' => 'second-user',
                    'email' => 'second@example.com',
                    'joined_at' => Carbon::now()->subDay(),
                ]),
            ],
            'member_promotion_counters' => [
                ['user_id' => 2, 'valid_replies' => 0, 'initialized' => 1, 'updated_at' => Carbon::now()],
                ['user_id' => 3, 'valid_replies' => 0, 'initialized' => 1, 'updated_at' => Carbon::now()],
            ],
        ]);
    }

    public function test_scan_job_advances_with_stable_cursor_and_resumes(): void
    {
        $container = $this->app()->getContainer();
        $db = $container->make('db');
        $id = $container->make(JobService::class)->enqueue('scan_users');

        $container->make(Worker::class)->run(1, 10);
        $first = $db->table('member_promotion_jobs')->find($id);
        $this->assertSame(1, (int) $first->cursor);
        $this->assertSame('pending', $first->status);

        $container->make(Worker::class)->run(1, 10);
        $second = $db->table('member_promotion_jobs')->find($id);
        $this->assertSame(2, (int) $second->cursor);
        $this->assertSame(2, (int) $second->processed);

        $container->make(Worker::class)->run(1, 10);
        $third = $db->table('member_promotion_jobs')->find($id);
        $this->assertSame(3, (int) $third->cursor);
        $this->assertSame(3, (int) $third->processed);
    }

    public function test_poison_job_stops_after_three_attempts(): void
    {
        $container = $this->app()->getContainer();
        $db = $container->make('db');
        $id = $container->make(JobService::class)->enqueue('unknown_poison');
        $container->make(Worker::class)->run(1, 10);

        $job = $db->table('member_promotion_jobs')->find($id);
        $this->assertSame('failed', $job->status);
        $this->assertSame(3, (int) $job->attempts);
        $this->assertNotEmpty($job->last_error);
    }

    public function test_deleted_discussion_uses_durable_participant_index_then_cleans_it(): void
    {
        $container = $this->app()->getContainer();
        $db = $container->make('db');
        $db->table('member_promotion_discussion_users')->insert(['discussion_id' => 99, 'user_id' => 2]);
        $id = $container->make(JobService::class)->enqueue('discussion_deleted', null, 99);

        $container->make(Worker::class)->run(1, 10);
        $this->assertSame('pending', $db->table('member_promotion_jobs')->find($id)->status);
        $container->make(Worker::class)->run(1, 10);

        $this->assertSame('completed', $db->table('member_promotion_jobs')->find($id)->status);
        $this->assertFalse($db->table('member_promotion_discussion_users')->where('discussion_id', 99)->exists());
    }

    public function test_global_disable_holds_scan_job_for_later(): void
    {
        $this->setting('dunea-member-promotion.enabled', '0');
        $container = $this->app()->getContainer();
        $db = $container->make('db');
        $id = $container->make(JobService::class)->enqueue('scan_users');

        $stats = $container->make(Worker::class)->run(10, 2);
        $job = $db->table('member_promotion_jobs')->find($id);

        $this->assertSame(0, $stats['jobs']);
        $this->assertSame('pending', $job->status);
        $this->assertSame(0, (int) $job->cursor);
    }

    public function test_production_run_uses_fixed_limits_instead_of_legacy_settings(): void
    {
        $this->setting('dunea-member-promotion.batch_size', '1');
        $this->setting('dunea-member-promotion.time_budget_seconds', '1');
        $container = $this->app()->getContainer();
        $db = $container->make('db');
        $id = $container->make(JobService::class)->enqueue('scan_users');

        $container->make(Worker::class)->run();
        $job = $db->table('member_promotion_jobs')->find($id);

        $this->assertSame(100, Worker::BATCH_SIZE);
        $this->assertSame(20, Worker::TIME_BUDGET_SECONDS);
        $this->assertSame('completed', $job->status);
        $this->assertSame(3, (int) $job->processed);
    }

    public function test_bulk_enqueue_resets_failed_pending_rows_without_duplicates(): void
    {
        $container = $this->app()->getContainer();
        $db = $container->make('db');
        $db->table('member_promotion_pending')->insert([
            'user_id' => 2,
            'available_at' => Carbon::now()->addHour(),
            'reason' => 'old_reason',
            'attempts' => 3,
            'last_error' => 'old failure',
            'updated_at' => Carbon::now(),
        ]);

        $container->make(PendingService::class)->enqueueMany([2, 2, 3], 'scan_users');

        $this->assertSame(2, $db->table('member_promotion_pending')->count());
        $row = $db->table('member_promotion_pending')->where('user_id', 2)->first();
        $this->assertSame(0, (int) $row->attempts);
        $this->assertNull($row->last_error);
        $this->assertSame('changed', $row->reason);
    }

    public function test_age_due_check_is_rescheduled_instead_of_immediately_reclaimed(): void
    {
        $container = $this->app()->getContainer();
        $db = $container->make('db');
        $db->table('groups')->insert([
            'id' => 5, 'name_singular' => '未来会员', 'name_plural' => '未来会员', 'is_hidden' => 0,
        ]);
        $db->table('member_promotion_rules')->insert([
            'id' => 1, 'name' => '等待注册时长', 'enabled' => 1, 'archived' => 0, 'priority' => 1,
            'prerequisite_group_id' => null, 'target_group_id' => 5, 'remove_prerequisite' => 0,
            'age_enabled' => 1, 'age_minutes' => 14400, 'age_operator' => '>=',
            'replies_enabled' => 0, 'replies_count' => null, 'replies_operator' => '>=',
            'version' => 1, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
        $container->make(PendingService::class)->enqueue(2, 'scan_users');

        $first = $container->make(Worker::class)->run(10, 10);
        $pending = $db->table('member_promotion_pending')->where('user_id', 2)->first();
        $this->assertSame(1, $first['users']);
        $this->assertNotNull($pending);
        $this->assertSame('age_due', $pending->reason);
        $this->assertTrue(Carbon::parse($pending->available_at)->isFuture());
        $this->assertNull($pending->claim_token);

        $second = $container->make(Worker::class)->run(10, 2);
        $this->assertSame(0, $second['users']);
    }
}
