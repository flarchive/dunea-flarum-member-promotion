<?php

namespace Dunea\MemberPromotion\Tests\unit\Service;

use Carbon\Carbon;
use Dunea\MemberPromotion\Service\ConditionMatcher;
use PHPUnit\Framework\TestCase;

class ConditionMatcherTest extends TestCase
{
    private ConditionMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new ConditionMatcher();
    }

    /** @dataProvider boundaries */
    public function test_age_and_reply_boundaries(string $operator, bool $expected): void
    {
        $rule = $this->rule($operator, $operator);
        $joined = Carbon::parse('2026-01-01 00:00:00', 'UTC');
        $now = Carbon::parse('2026-01-01 01:00:00', 'UTC');
        $result = $this->matcher->match($rule, $joined, $now, 10);

        $this->assertSame($expected, $result['passed']);
    }

    public function boundaries(): array
    {
        return [['>=', true], ['>', false]];
    }

    public function test_enabled_conditions_are_combined_with_and(): void
    {
        $rule = $this->rule('>=', '>=');
        $result = $this->matcher->match(
            $rule,
            Carbon::parse('2026-01-01 00:00:00', 'UTC'),
            Carbon::parse('2026-01-01 02:00:00', 'UTC'),
            9
        );

        $this->assertFalse($result['passed']);
        $this->assertSame(['replies_not_met'], $result['reasons']);
    }

    public function test_strict_age_schedules_one_second_after_boundary(): void
    {
        $rule = $this->rule('>', '>=');
        $result = $this->matcher->match(
            $rule,
            Carbon::parse('2026-01-01 00:00:00', 'UTC'),
            Carbon::parse('2026-01-01 00:30:00', 'UTC'),
            10
        );

        $this->assertSame('2026-01-01 01:00:01', $result['nextCheckAt']->format('Y-m-d H:i:s'));
    }

    private function rule(string $ageOperator, string $replyOperator): object
    {
        return (object) [
            'age_enabled' => true, 'age_minutes' => 60, 'age_operator' => $ageOperator,
            'replies_enabled' => true, 'replies_count' => 10, 'replies_operator' => $replyOperator,
        ];
    }
}
