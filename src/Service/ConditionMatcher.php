<?php

namespace Dunea\MemberPromotion\Service;

use Carbon\Carbon;

final class ConditionMatcher
{
    /**
     * @return array{passed: bool, reasons: string[], nextCheckAt: ?Carbon}
     */
    public function match(object $rule, Carbon $joined, Carbon $now, int $validReplies): array
    {
        $reasons = [];
        $nextCheckAt = null;
        if ($rule->age_enabled) {
            $threshold = $joined->copy()->addMinutes((int) $rule->age_minutes);
            $agePassed = $rule->age_operator === '>'
                ? $now->greaterThan($threshold)
                : $now->greaterThanOrEqualTo($threshold);
            if (! $agePassed) {
                $reasons[] = 'age_not_met';
                $nextCheckAt = $rule->age_operator === '>' ? $threshold->copy()->addSecond() : $threshold;
            }
        }
        if ($rule->replies_enabled) {
            $replyPassed = $rule->replies_operator === '>'
                ? $validReplies > (int) $rule->replies_count
                : $validReplies >= (int) $rule->replies_count;
            if (! $replyPassed) {
                $reasons[] = 'replies_not_met';
            }
        }

        return ['passed' => $reasons === [], 'reasons' => $reasons, 'nextCheckAt' => $nextCheckAt];
    }
}
