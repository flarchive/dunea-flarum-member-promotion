<?php

namespace Dunea\MemberPromotion\Service;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;

final class PendingService
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function enqueue(int $userId, string $reason = 'changed', ?Carbon $availableAt = null): void
    {
        if ($userId <= 0) {
            return;
        }
        $at = $availableAt ?: Carbon::now('UTC');
        $now = Carbon::now('UTC');
        $this->db->transaction(function () use ($userId, $reason, $at, $now) {
            $this->enqueueOne($userId, $reason, $at, $now);
        });
    }

    /** @param int[] $userIds */
    public function enqueueMany(array $userIds, string $reason = 'changed'): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn (int $id) => $id > 0)));
        if (! $userIds) {
            return;
        }

        $now = Carbon::now('UTC');
        $this->db->transaction(function () use ($userIds, $reason, $now) {
            $existing = $this->db->table('member_promotion_pending')
                ->whereIn('user_id', $userIds)->lockForUpdate()->get()->keyBy('user_id');
            $newRows = [];
            $sameReasonIds = [];
            $changedReasonIds = [];
            foreach ($userIds as $userId) {
                if ($existing->has($userId)) {
                    if ($existing->get($userId)->reason === $reason) {
                        $sameReasonIds[] = $userId;
                    } else {
                        $changedReasonIds[] = $userId;
                    }
                } else {
                    $newRows[] = [
                        'user_id' => $userId, 'available_at' => $now, 'reason' => $reason,
                        'attempts' => 0, 'updated_at' => $now,
                    ];
                }
            }
            if ($newRows) {
                $this->db->table('member_promotion_pending')->insertOrIgnore($newRows);
            }
            $this->bulkUpdateExisting($sameReasonIds, $reason, $now);
            $this->bulkUpdateExisting($changedReasonIds, 'changed', $now);
        });
    }

    public function completeClaim(int $userId, string $claimToken, ?Carbon $nextCheckAt = null): void
    {
        $query = $this->db->table('member_promotion_pending')
            ->where('user_id', $userId)->where('claim_token', $claimToken);
        if ($nextCheckAt === null) {
            $query->delete();

            return;
        }

        $query->update([
            'available_at' => $nextCheckAt,
            'reason' => 'age_due',
            'attempts' => 0,
            'claim_token' => null,
            'claimed_at' => null,
            'last_error' => null,
            'updated_at' => Carbon::now('UTC'),
        ]);
    }

    private function enqueueOne(int $userId, string $reason, Carbon $at, Carbon $now): void
    {
        $inserted = $this->db->table('member_promotion_pending')->insertOrIgnore([
            'user_id' => $userId, 'available_at' => $at, 'reason' => $reason,
            'attempts' => 0, 'updated_at' => $now,
        ]);
        if ($inserted) {
            return;
        }

        $existing = $this->db->table('member_promotion_pending')->where('user_id', $userId)->lockForUpdate()->first();
        if ($existing) {
            $this->updateExisting($existing, $reason, $at, $now);
        }
    }

    private function updateExisting(object $existing, string $reason, Carbon $at, Carbon $now): void
    {
        $earlier = Carbon::parse($existing->available_at, 'UTC')->lessThanOrEqualTo($at)
            ? $existing->available_at
            : $at;
        $this->db->table('member_promotion_pending')->where('user_id', $existing->user_id)->update([
            'available_at' => $earlier,
            'reason' => $existing->reason === $reason ? $reason : 'changed',
            'attempts' => 0,
            'claim_token' => null,
            'claimed_at' => null,
            'last_error' => null,
            'updated_at' => $now,
        ]);
    }

    /** @param int[] $userIds */
    private function bulkUpdateExisting(array $userIds, string $reason, Carbon $now): void
    {
        if (! $userIds) {
            return;
        }
        $this->db->table('member_promotion_pending')->whereIn('user_id', $userIds)->update([
            'available_at' => $now,
            'reason' => $reason,
            'attempts' => 0,
            'claim_token' => null,
            'claimed_at' => null,
            'last_error' => null,
            'updated_at' => $now,
        ]);
    }
}
