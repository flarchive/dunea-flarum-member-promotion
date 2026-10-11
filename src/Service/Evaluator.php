<?php

namespace Dunea\MemberPromotion\Service;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;

final class Evaluator
{
    /** @var array<int, bool> */
    private array $groupExistence = [];
    private ?bool $hasSuspendedUntil = null;
    /** @var int[]|null */
    private ?array $excludedGroupIds = null;

    public function __construct(
        private ConnectionInterface $db,
        private SettingsRepositoryInterface $settings,
        private RuleService $rules,
        private ConditionMatcher $matcher,
        private CounterService $counters,
        private PendingService $pending,
        private AuditService $audit
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluate(
        int $userId,
        bool $dryRun = false,
        ?int $onlyRuleId = null,
        ?array $activeRules = null,
        bool $scheduleNextCheck = true
    ): array
    {
        $columns = ['users.id', 'users.username', 'users.joined_at', 'member_promotion_user_states.paused as promotion_paused',
            'member_promotion_counters.valid_replies as promotion_valid_replies',
            'member_promotion_counters.initialized as promotion_counter_initialized'];
        if ($this->supportsSuspension()) {
            $columns[] = 'users.suspended_until';
        }
        $user = $this->db->table('users')
            ->leftJoin('member_promotion_user_states', 'member_promotion_user_states.user_id', '=', 'users.id')
            ->leftJoin('member_promotion_counters', 'member_promotion_counters.user_id', '=', 'users.id')
            ->where('users.id', $userId)->first($columns);
        if (! $user) {
            return ['userId' => $userId, 'eligible' => false, 'reason' => 'user_missing', 'rules' => []];
        }

        $counter = $user->promotion_counter_initialized ? (int) $user->promotion_valid_replies : null;
        if ($counter === null) {
            $counter = $dryRun ? $this->counters->countUser($userId) : $this->counters->rebuildUser($userId);
        }
        $groupIds = $this->groupIds($userId);
        $gating = $this->gatingReason($user, $groupIds, $dryRun);
        $now = Carbon::now('UTC');
        $joined = $user->joined_at ? Carbon::parse($user->joined_at, 'UTC') : $now;
        $rules = $activeRules ?? $this->rules->activeOrdered();
        if ($onlyRuleId !== null) {
            $rules = array_values(array_filter($rules, fn (object $rule) => (int) $rule->id === $onlyRuleId));
        }
        $ruleIds = array_map(fn (object $rule) => (int) $rule->id, $rules);
        $blockedRuleIds = $ruleIds ? array_fill_keys(array_map('intval', $this->db->table('member_promotion_blocks')
            ->where('user_id', $userId)->whereNull('cleared_at')->whereIn('rule_id', $ruleIds)->pluck('rule_id')->all()), true) : [];
        $historicalByRule = [];
        if ($ruleIds) {
            $historical = $this->db->table('member_promotion_grants')->where('user_id', $userId)
                ->whereIn('rule_id', $ruleIds)->orderByDesc('id')->get(['id', 'rule_id', 'group_id', 'status']);
            foreach ($historical as $grant) {
                $historicalByRule[(int) $grant->rule_id] ??= $grant;
            }
            $this->primeGroupExistence(array_map(fn (object $rule) => (int) $rule->target_group_id, $rules));
        }
        $report = [
            'userId' => $userId,
            'username' => $user->username,
            'eligible' => $gating === null,
            'reason' => $gating,
            'validReplies' => $counter,
            'ageMinutes' => max(0, $joined->diffInMinutes($now, false)),
            'groups' => $groupIds,
            'rules' => [],
            'dryRun' => $dryRun,
        ];
        $nextCheckAt = null;

        foreach ($rules as $rule) {
            $result = $this->evaluateRule(
                $userId, $rule, $joined, $now, $counter, $groupIds, $gating, $dryRun,
                isset($blockedRuleIds[(int) $rule->id]), $historicalByRule[(int) $rule->id] ?? null, $nextCheckAt
            );
            $report['rules'][] = $result;
            $groupIds = $result['resultingGroups'];
        }
        $report['groups'] = $groupIds;
        $report['nextCheckAt'] = $nextCheckAt?->toIso8601String();
        if (! $dryRun && $scheduleNextCheck && $nextCheckAt !== null) {
            $this->pending->enqueue($userId, 'age_due', $nextCheckAt);
        }

        return $report;
    }

    /** @param int[] $groupIds @return array<string, mixed> */
    private function evaluateRule(
        int $userId,
        object $rule,
        Carbon $joined,
        Carbon $now,
        int $counter,
        array $groupIds,
        ?string $gating,
        bool $dryRun,
        bool $blocked,
        ?object $historical,
        ?Carbon &$nextCheckAt
    ): array
    {
        $reasons = [];
        if ($gating !== null) {
            $reasons[] = $gating;
        }
        if ($rule->prerequisite_group_id !== null && ! in_array((int) $rule->prerequisite_group_id, $groupIds, true)) {
            $reasons[] = 'prerequisite_missing';
        }
        $conditions = $this->matcher->match($rule, $joined, $now, $counter);
        $reasons = array_merge($reasons, $conditions['reasons']);
        if ($conditions['nextCheckAt'] && ($nextCheckAt === null || $conditions['nextCheckAt']->lessThan($nextCheckAt))) {
            $nextCheckAt = $conditions['nextCheckAt'];
        }
        if (! $this->groupExists((int) $rule->target_group_id)) {
            $reasons[] = 'target_group_missing';
        }
        if ($blocked) {
            $reasons[] = 'manual_revoke_blocked';
        }

        $blockCreated = false;
        if ($historical && (int) $historical->group_id !== (int) $rule->target_group_id) {
            $reasons[] = 'historical_structure_retained';
        } elseif ($historical && $historical->status === 'active' && ! in_array((int) $historical->group_id, $groupIds, true)) {
            $reasons[] = 'manual_revoke_detected';
            if (! $dryRun && $this->groupExists((int) $historical->group_id)) {
                $blockCreated = $this->recordConservativeBlock($userId, $rule, (int) $historical->group_id);
            }
        }

        $status = $reasons ? 'skipped' : 'qualified';
        $reason = $reasons ? implode(',', array_values(array_unique($reasons))) : 'conditions_met';
        $added = null;
        $removed = null;

        if ($historical && $historical->status === 'consumed' && (int) $historical->group_id === (int) $rule->target_group_id) {
            $status = 'completed';
            $reason = 'granted_then_consumed_by_later_stage';
        } elseif (! $reasons && in_array((int) $rule->target_group_id, $groupIds, true)) {
            $status = 'completed';
            $reason = $historical ? 'already_granted' : 'already_member_manual_or_external';
        } elseif (! $reasons && $dryRun) {
            $status = 'would_promote';
            $groupIds[] = (int) $rule->target_group_id;
            $added = (int) $rule->target_group_id;
            if ($rule->remove_prerequisite && $rule->prerequisite_group_id !== null && $this->mayRemoveSource($userId, (int) $rule->prerequisite_group_id)) {
                $groupIds = array_values(array_diff($groupIds, [(int) $rule->prerequisite_group_id]));
                $removed = (int) $rule->prerequisite_group_id;
            }
        } elseif (! $reasons) {
            [$added, $removed, $status, $reason] = $this->promote($userId, $rule);
            if ($added !== null && ! in_array($added, $groupIds, true)) {
                $groupIds[] = $added;
            }
            if ($removed !== null) {
                $groupIds = array_values(array_diff($groupIds, [$removed]));
            }
        }

        // The audit table records state changes, not every no-op evaluation. A full scan can
        // touch millions of users, so logging completed/skipped checks would grow without bound.
        if (! $dryRun && ($status === 'promoted' || $blockCreated)) {
            $this->audit->write($userId, $rule, $status === 'promoted' ? 'success' : $status, $reason, [
                'validReplies' => $counter,
                'ageMinutes' => max(0, $joined->diffInMinutes($now, false)),
            ], $added, $removed);
        }

        return [
            'ruleId' => (int) $rule->id, 'ruleVersion' => (int) $rule->version, 'name' => $rule->name,
            'status' => $status, 'reasons' => $reasons ?: [$reason], 'addedGroupId' => $added,
            'removedGroupId' => $removed, 'resultingGroups' => array_values(array_unique($groupIds)),
        ];
    }

    /** @return array{0:?int,1:?int,2:string,3:string} */
    private function promote(int $userId, object $rule): array
    {
        return $this->db->transaction(function () use ($userId, $rule) {
            $this->db->table('users')->where('id', $userId)->lockForUpdate()->first();
            if ($this->db->table('group_user')->where('user_id', $userId)->where('group_id', $rule->target_group_id)->exists()) {
                return [null, null, 'completed', 'already_member_concurrent'];
            }
            if ($this->db->table('member_promotion_blocks')->where('user_id', $userId)->where('rule_id', $rule->id)->whereNull('cleared_at')->exists()) {
                return [null, null, 'skipped', 'manual_revoke_blocked'];
            }

            $now = Carbon::now('UTC');
            $inserted = $this->db->table('member_promotion_grants')->insertOrIgnore([
                'user_id' => $userId, 'rule_id' => $rule->id, 'rule_version' => $rule->version,
                'group_id' => $rule->target_group_id, 'status' => 'active', 'granted_at' => $now,
            ]);
            if (! $inserted) {
                return [null, null, 'completed', 'idempotent_duplicate'];
            }
            $this->db->table('group_user')->insertOrIgnore(['user_id' => $userId, 'group_id' => $rule->target_group_id]);
            $removed = null;
            if ($rule->remove_prerequisite && $rule->prerequisite_group_id !== null && $this->mayRemoveSource($userId, (int) $rule->prerequisite_group_id)) {
                $removed = (int) $rule->prerequisite_group_id;
                $this->db->table('group_user')->where('user_id', $userId)->where('group_id', $removed)->delete();
                $this->db->table('member_promotion_grants')->where('user_id', $userId)->where('group_id', $removed)
                    ->where('status', 'active')->update(['status' => 'consumed', 'ended_at' => $now]);
            }

            return [(int) $rule->target_group_id, $removed, 'promoted', 'conditions_met'];
        });
    }

    private function mayRemoveSource(int $userId, int $groupId): bool
    {
        return $this->db->table('member_promotion_grants')
            ->where('user_id', $userId)->where('group_id', $groupId)->where('status', 'active')->count() === 1;
    }

    private function recordConservativeBlock(int $userId, object $rule, int $groupId): bool
    {
        $now = Carbon::now('UTC');
        return $this->db->transaction(function () use ($userId, $rule, $groupId, $now) {
            $created = false;
            if (! $this->db->table('member_promotion_blocks')->where('user_id', $userId)->where('rule_id', $rule->id)->whereNull('cleared_at')->exists()) {
                $this->db->table('member_promotion_blocks')->insert([
                    'user_id' => $userId, 'rule_id' => $rule->id, 'group_id' => $groupId,
                    'reason' => 'membership_missing_detected', 'created_at' => $now,
                ]);
                $created = true;
            }
            $this->db->table('member_promotion_grants')->where('user_id', $userId)->where('rule_id', $rule->id)
                ->where('status', 'active')->update(['status' => 'revoked_manual', 'ended_at' => $now]);

            return $created;
        });
    }

    /** @param int[] $groups */
    private function gatingReason(object $user, array $groups, bool $dryRun): ?string
    {
        if (! $dryRun && $this->settings->get('dunea-member-promotion.enabled', '0') !== '1') {
            return 'global_disabled';
        }
        if (in_array(Group::ADMINISTRATOR_ID, $groups, true)) {
            return 'administrator';
        }
        if (in_array(Group::MODERATOR_ID, $groups, true)) {
            return 'moderator';
        }
        if ($this->excludedGroupIds === null) {
            $excluded = json_decode($this->settings->get('dunea-member-promotion.excluded_group_ids', '[]'), true) ?: [];
            $this->excludedGroupIds = array_map('intval', $excluded);
        }
        if (array_intersect($this->excludedGroupIds, $groups)) {
            return 'excluded_group';
        }
        if ((bool) $user->promotion_paused) {
            return 'user_paused';
        }
        if (isset($user->suspended_until) && Carbon::parse($user->suspended_until, 'UTC')->isFuture()) {
            return 'suspended';
        }

        return null;
    }

    /** @return int[] */
    private function groupIds(int $userId): array
    {
        return array_map('intval', $this->db->table('group_user')->where('user_id', $userId)->pluck('group_id')->all());
    }

    private function supportsSuspension(): bool
    {
        return $this->hasSuspendedUntil ??= $this->db->getSchemaBuilder()->hasColumn('users', 'suspended_until');
    }

    /** @param int[] $groupIds */
    private function primeGroupExistence(array $groupIds): void
    {
        $unknown = array_values(array_unique(array_filter($groupIds, fn (int $id) => ! array_key_exists($id, $this->groupExistence))));
        if (! $unknown) {
            return;
        }
        $existing = array_map('intval', $this->db->table('groups')->whereIn('id', $unknown)->pluck('id')->all());
        foreach ($unknown as $groupId) {
            $this->groupExistence[$groupId] = in_array($groupId, $existing, true);
        }
    }

    private function groupExists(int $groupId): bool
    {
        $this->primeGroupExistence([$groupId]);

        return $this->groupExistence[$groupId];
    }
}
