<?php

namespace Dunea\MemberPromotion\Listener;

use Carbon\Carbon;
use Dunea\MemberPromotion\Service\AuditService;
use Dunea\MemberPromotion\Service\PendingService;
use Flarum\User\Event\GroupsChanged;
use Illuminate\Database\ConnectionInterface;

final class ManualGroupChangeListener
{
    public function __construct(
        private ConnectionInterface $db,
        private PendingService $pending,
        private AuditService $audit
    ) {
    }

    public function __invoke(GroupsChanged $event): void
    {
        $userId = (int) $event->user->id;
        $old = array_map(fn ($group) => (int) $group->id, $event->oldGroups);
        $current = array_map('intval', $this->db->table('group_user')->where('user_id', $userId)->pluck('group_id')->all());
        $removed = array_diff($old, $current);
        $now = Carbon::now('UTC');

        foreach ($removed as $groupId) {
            $grants = $this->db->table('member_promotion_grants')
                ->where('user_id', $userId)->where('group_id', $groupId)->where('status', 'active')->get();
            $handledRuleIds = [];
            foreach ($grants as $grant) {
                $handledRuleIds[] = (int) $grant->rule_id;
                if (! $this->db->table('member_promotion_blocks')->where('user_id', $userId)->where('rule_id', $grant->rule_id)->whereNull('cleared_at')->exists()) {
                    $this->db->table('member_promotion_blocks')->insert([
                        'user_id' => $userId, 'rule_id' => $grant->rule_id, 'group_id' => $groupId,
                        'reason' => 'manual_revoke_event', 'created_by' => $event->actor?->id, 'created_at' => $now,
                    ]);
                }
                $this->db->table('member_promotion_grants')->where('id', $grant->id)
                    ->update(['status' => 'revoked_manual', 'ended_at' => $now]);
                $rule = $this->db->table('member_promotion_rules')->find($grant->rule_id);
                $this->audit->write($userId, $rule, 'skipped', 'manual_revoke_blocked', [], null, (int) $groupId);
            }

            $rules = $this->db->table('member_promotion_rules')->where('target_group_id', $groupId)
                ->where('archived', false)->whereNotIn('id', $handledRuleIds ?: [0])->get();
            foreach ($rules as $rule) {
                if (! $this->db->table('member_promotion_blocks')->where('user_id', $userId)->where('rule_id', $rule->id)->whereNull('cleared_at')->exists()) {
                    $this->db->table('member_promotion_blocks')->insert([
                        'user_id' => $userId, 'rule_id' => $rule->id, 'group_id' => $groupId,
                        'reason' => 'manual_revoke_event', 'created_by' => $event->actor?->id, 'created_at' => $now,
                    ]);
                }
                $this->audit->write($userId, $rule, 'skipped', 'manual_revoke_blocked', [], null, (int) $groupId);
            }
        }
        $this->pending->enqueue($userId, 'groups_changed');
    }
}
