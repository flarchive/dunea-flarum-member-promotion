<?php

namespace Dunea\MemberPromotion\Service;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;

final class JobService
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function enqueue(string $type, ?int $ruleId = null, ?int $discussionId = null): int
    {
        $existing = $this->db->table('member_promotion_jobs')
            ->where('type', $type)->where('status', 'pending')
            ->where('rule_id', $ruleId)->where('discussion_id', $discussionId)->first();
        if ($existing) {
            return (int) $existing->id;
        }
        $now = Carbon::now('UTC');

        return (int) $this->db->table('member_promotion_jobs')->insertGetId([
            'type' => $type, 'status' => 'pending', 'rule_id' => $ruleId,
            'discussion_id' => $discussionId, 'cursor' => 0, 'processed' => 0,
            'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
