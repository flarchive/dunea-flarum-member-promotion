<?php

namespace Dunea\MemberPromotion\Api;

use Carbon\Carbon;
use Dunea\MemberPromotion\Service\AuditService;
use Dunea\MemberPromotion\Service\Evaluator;
use Dunea\MemberPromotion\Service\PendingService;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class UserActionController extends AbstractAdminController implements RequestHandlerInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private Evaluator $evaluator,
        private PendingService $pending,
        private AuditService $audit
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $userId = (int) ($request->getQueryParams()['id'] ?? 0);
        $data = $this->body($request);
        $action = (string) ($data['action'] ?? '');
        $now = Carbon::now('UTC');

        if ($action === 'pause') {
            $this->db->table('member_promotion_user_states')->updateOrInsert(['user_id' => $userId], [
                'paused' => true, 'paused_by' => $actor->id, 'pause_reason' => (string) ($data['reason'] ?? ''), 'updated_at' => $now,
            ]);
        } elseif ($action === 'resume') {
            $this->db->table('member_promotion_user_states')->updateOrInsert(['user_id' => $userId], [
                'paused' => false, 'paused_by' => null, 'pause_reason' => null, 'updated_at' => $now,
            ]);
            $this->pending->enqueue($userId, 'resumed');
        } elseif ($action === 'check') {
            return new JsonResponse(['report' => $this->evaluator->evaluate($userId, false)]);
        } elseif ($action === 'revoke') {
            $rule = $this->db->table('member_promotion_rules')->find((int) ($data['ruleId'] ?? 0));
            if (! $rule) {
                return new JsonResponse(['error' => '规则不存在。'], 404);
            }
            $this->db->transaction(function () use ($userId, $rule, $actor, $now) {
                $this->db->table('group_user')->where('user_id', $userId)->where('group_id', $rule->target_group_id)->delete();
                $this->db->table('member_promotion_grants')->where('user_id', $userId)->where('rule_id', $rule->id)
                    ->where('status', 'active')->update(['status' => 'revoked_manual', 'ended_at' => $now]);
                if (! $this->db->table('member_promotion_blocks')->where('user_id', $userId)->where('rule_id', $rule->id)->whereNull('cleared_at')->exists()) {
                    $this->db->table('member_promotion_blocks')->insert([
                        'user_id' => $userId, 'rule_id' => $rule->id, 'group_id' => $rule->target_group_id,
                        'reason' => 'admin_revoke', 'created_by' => $actor->id, 'created_at' => $now,
                    ]);
                }
            });
            $this->audit->write($userId, $rule, 'skipped', 'admin_revoke_blocked', [], null, (int) $rule->target_group_id);
        } elseif ($action === 'unblock') {
            $ruleId = (int) ($data['ruleId'] ?? 0);
            $this->db->table('member_promotion_blocks')->where('user_id', $userId)->where('rule_id', $ruleId)
                ->whereNull('cleared_at')->update(['cleared_at' => $now, 'cleared_by' => $actor->id]);
            $this->pending->enqueue($userId, 'block_cleared');
        } else {
            return new JsonResponse(['error' => '不支持的操作。'], 422);
        }

        return new JsonResponse(['saved' => true]);
    }
}
