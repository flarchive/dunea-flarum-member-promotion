<?php

namespace Dunea\MemberPromotion\Service;

use Carbon\Carbon;
use Flarum\Post\Post;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

final class CounterService
{
    private ?bool $hasApproval = null;

    public function __construct(private ConnectionInterface $db, private PendingService $pending)
    {
    }

    public function isInitialized(int $userId): bool
    {
        return (bool) $this->db->table('member_promotion_counters')
            ->where('user_id', $userId)->where('initialized', true)->exists();
    }

    public function value(int $userId): ?int
    {
        $row = $this->db->table('member_promotion_counters')->where('user_id', $userId)->first();

        return $row && $row->initialized ? (int) $row->valid_replies : null;
    }

    public function countUser(int $userId): int
    {
        return (int) $this->eligibleQuery()->where('posts.user_id', $userId)->count();
    }

    public function rebuildUser(int $userId): int
    {
        $count = $this->countUser($userId);
        $now = Carbon::now('UTC');
        $this->db->transaction(function () use ($userId, $count, $now) {
            $this->db->table('member_promotion_counters')->updateOrInsert(
                ['user_id' => $userId],
                ['valid_replies' => $count, 'initialized' => true, 'updated_at' => $now]
            );
            $this->syncDiscussionUsers($userId);
        });

        return $count;
    }

    public function invalidate(int $userId, string $reason): void
    {
        $this->db->table('member_promotion_counters')->updateOrInsert(
            ['user_id' => $userId],
            ['initialized' => false, 'updated_at' => Carbon::now('UTC')]
        );
        $this->pending->enqueue($userId, $reason);
    }

    public function adjust(Post $post, int $delta, string $reason): void
    {
        $userId = (int) $post->user_id;
        if ($userId <= 0) {
            return;
        }
        $updated = $this->db->table('member_promotion_counters')
            ->where('user_id', $userId)->where('initialized', true)
            ->update([
                'valid_replies' => $delta >= 0
                    ? $this->db->raw('valid_replies + '.(int) $delta)
                    : $this->db->raw('GREATEST(valid_replies - '.abs($delta).', 0)'),
                'updated_at' => Carbon::now('UTC'),
            ]);
        if (! $updated) {
            $this->invalidate($userId, 'counter_uninitialized');
        } else {
            $this->pending->enqueue($userId, $reason);
        }
    }

    public function registerDiscussionUser(Post $post): void
    {
        if ($post->type === 'comment' && $post->user_id && $post->discussion_id) {
            $this->db->table('member_promotion_discussion_users')->insertOrIgnore([
                'discussion_id' => (int) $post->discussion_id,
                'user_id' => (int) $post->user_id,
            ]);
        }
    }

    public function eligible(Post $post, bool $ignoreOwnHiddenState = false): bool
    {
        if ($post->type !== 'comment' || (int) $post->number <= 1 || ! $post->user_id) {
            return false;
        }
        if (! $ignoreOwnHiddenState && $post->hidden_at !== null) {
            return false;
        }
        $discussion = $post->relationLoaded('discussion') ? $post->discussion : $post->discussion()->first();
        if (! $discussion || $discussion->hidden_at !== null || (bool) $discussion->is_private) {
            return false;
        }
        if ((bool) $post->is_private) {
            return false;
        }
        if ($this->approvalEnabled() && isset($post->is_approved) && ! (bool) $post->is_approved) {
            return false;
        }

        return true;
    }

    private function eligibleQuery(): Builder
    {
        $query = $this->db->table('posts')
            ->join('discussions', 'discussions.id', '=', 'posts.discussion_id')
            ->where('posts.type', 'comment')
            ->where('posts.number', '>', 1)
            ->whereNull('posts.hidden_at')
            ->whereNull('discussions.hidden_at')
            ->where('posts.is_private', false)
            ->where('discussions.is_private', false);
        if ($this->approvalEnabled()) {
            $query->where('posts.is_approved', true)->where('discussions.is_approved', true);
        }

        return $query;
    }

    private function approvalEnabled(): bool
    {
        if ($this->hasApproval === null) {
            $schema = $this->db->getSchemaBuilder();
            $this->hasApproval = $schema->hasColumn('posts', 'is_approved') && $schema->hasColumn('discussions', 'is_approved');
        }

        return $this->hasApproval;
    }

    private function syncDiscussionUsers(int $userId): void
    {
        $this->db->table('member_promotion_discussion_users')->where('user_id', $userId)->delete();
        $cursor = 0;
        do {
            $posts = $this->db->table('posts')->where('user_id', $userId)->where('type', 'comment')
                ->where('id', '>', $cursor)->orderBy('id')->limit(1000)->get(['id', 'discussion_id']);
            $discussionRows = $posts->pluck('discussion_id')->unique()->map(fn ($discussionId) => [
                    'discussion_id' => (int) $discussionId, 'user_id' => $userId,
                ])->values()->all();
            if ($discussionRows) {
                $this->db->table('member_promotion_discussion_users')->insertOrIgnore($discussionRows);
            }
            if ($posts->isNotEmpty()) {
                $cursor = (int) $posts->last()->id;
            }
        } while ($posts->count() === 1000);
    }
}
