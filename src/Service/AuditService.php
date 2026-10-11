<?php

namespace Dunea\MemberPromotion\Service;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;

final class AuditService
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    /** @param array<string, mixed> $details */
    public function write(?int $userId, ?object $rule, string $status, string $reason, array $details = [], ?int $added = null, ?int $removed = null): void
    {
        $this->db->table('member_promotion_logs')->insert([
            'user_id' => $userId,
            'rule_id' => $rule ? (int) $rule->id : null,
            'rule_version' => $rule ? (int) $rule->version : null,
            'status' => $status,
            'reason' => $reason,
            'added_group_id' => $added,
            'removed_group_id' => $removed,
            'details_json' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => Carbon::now('UTC'),
        ]);
    }
}
