<?php

namespace Dunea\MemberPromotion\Tests\integration;

use Carbon\Carbon;
use Dunea\MemberPromotion\Service\CounterService;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;

class EffectiveReplyCountWithoutApprovalTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('dunea-member-promotion');
        $now = Carbon::now();
        $this->prepareDatabase([
            'users' => [$this->normalUser()],
            'discussions' => [[
                'id' => 20, 'title' => 'without approval', 'created_at' => $now,
                'user_id' => 2, 'first_post_id' => 200, 'comment_count' => 2, 'is_private' => 0,
            ]],
            'posts' => [
                ['id' => 200, 'discussion_id' => 20, 'user_id' => 2, 'type' => 'comment', 'number' => 1, 'content' => '<t/>', 'is_private' => 0],
                ['id' => 201, 'discussion_id' => 20, 'user_id' => 2, 'type' => 'comment', 'number' => 2, 'content' => '<t/>', 'is_private' => 0],
            ],
        ]);
    }

    public function test_count_does_not_reference_optional_approval_columns(): void
    {
        $counter = $this->app()->getContainer()->make(CounterService::class);

        $this->assertSame(1, $counter->countUser(2));
    }
}
