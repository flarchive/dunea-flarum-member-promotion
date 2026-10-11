<?php

namespace Dunea\MemberPromotion\Tests\integration;

use Carbon\Carbon;
use Dunea\MemberPromotion\Service\CounterService;
use Dunea\MemberPromotion\Listener\ContentChangedListener;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;

class EffectiveReplyCountTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('flarum-flags', 'flarum-approval', 'dunea-member-promotion');
        $now = Carbon::now();
        $this->prepareDatabase([
            'users' => [$this->normalUser()],
            'discussions' => [
                ['id' => 10, 'title' => 'visible', 'created_at' => $now, 'user_id' => 2, 'first_post_id' => 100, 'comment_count' => 5, 'is_private' => 0, 'is_approved' => 1],
                ['id' => 11, 'title' => 'hidden', 'created_at' => $now, 'user_id' => 2, 'first_post_id' => 110, 'comment_count' => 1, 'hidden_at' => $now, 'is_private' => 0, 'is_approved' => 1],
            ],
            'posts' => [
                ['id' => 100, 'discussion_id' => 10, 'user_id' => 2, 'type' => 'comment', 'number' => 1, 'content' => '<t/>', 'is_private' => 0, 'is_approved' => 1],
                ['id' => 101, 'discussion_id' => 10, 'user_id' => 2, 'type' => 'comment', 'number' => 2, 'content' => '<t/>', 'is_private' => 0, 'is_approved' => 1],
                ['id' => 102, 'discussion_id' => 10, 'user_id' => 2, 'type' => 'comment', 'number' => 3, 'content' => '<t/>', 'hidden_at' => $now, 'is_private' => 0, 'is_approved' => 1],
                ['id' => 103, 'discussion_id' => 10, 'user_id' => 2, 'type' => 'comment', 'number' => 4, 'content' => '<t/>', 'is_private' => 1, 'is_approved' => 0],
                ['id' => 104, 'discussion_id' => 10, 'user_id' => 2, 'type' => 'discussionRenamed', 'number' => 5, 'content' => 'x', 'is_private' => 0, 'is_approved' => 1],
                ['id' => 110, 'discussion_id' => 11, 'user_id' => 2, 'type' => 'comment', 'number' => 2, 'content' => '<t/>', 'is_private' => 0, 'is_approved' => 1],
            ],
        ]);
    }

    public function test_only_visible_approved_body_replies_are_counted(): void
    {
        $counter = $this->app()->getContainer()->make(CounterService::class);
        $this->assertSame(1, $counter->countUser(2));
    }

    public function test_hide_restore_delete_and_approval_events_adjust_initialized_counter(): void
    {
        $container = $this->app()->getContainer();
        $counter = $container->make(CounterService::class);
        $listener = $container->make(ContentChangedListener::class);
        $this->assertSame(1, $counter->rebuildUser(2));

        $post = Post::findOrFail(101);
        $post->hidden_at = Carbon::now();
        $listener->postHidden((object) ['post' => $post]);
        $this->assertSame(0, $counter->value(2));

        $post->hidden_at = null;
        $listener->postRestored((object) ['post' => $post]);
        $this->assertSame(1, $counter->value(2));

        $listener->postDeleted((object) ['post' => $post]);
        $this->assertSame(0, $counter->value(2));

        $approved = Post::findOrFail(103);
        $approved->is_private = false;
        $approved->is_approved = true;
        $listener->postApproved((object) ['post' => $approved]);
        $this->assertSame(1, $counter->value(2));
    }
}
