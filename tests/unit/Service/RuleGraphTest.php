<?php

namespace Dunea\MemberPromotion\Tests\unit\Service;

use Dunea\MemberPromotion\Exception\RuleValidationException;
use Dunea\MemberPromotion\Service\RuleGraph;
use PHPUnit\Framework\TestCase;

class RuleGraphTest extends TestCase
{
    public function test_dependencies_precede_priority_and_stable_id_order(): void
    {
        $rules = [
            $this->rule(3, 6, 7, 100),
            $this->rule(2, 5, 6, -100),
            $this->rule(1, null, 5, -1000),
            $this->rule(4, null, 8, 50),
        ];

        $ordered = (new RuleGraph())->ordered($rules);

        $this->assertSame([4, 1, 2, 3], array_map(fn ($rule) => $rule->id, $ordered));
    }

    public function test_indirect_cycle_is_rejected(): void
    {
        $this->expectException(RuleValidationException::class);
        (new RuleGraph())->ordered([
            $this->rule(1, 7, 5), $this->rule(2, 5, 6), $this->rule(3, 6, 7),
        ]);
    }

    public function test_duplicate_target_is_rejected_as_ambiguous_conflict(): void
    {
        $this->expectException(RuleValidationException::class);
        (new RuleGraph())->ordered([
            $this->rule(1, null, 5), $this->rule(2, 6, 5),
        ]);
    }

    private function rule(int $id, ?int $prerequisite, int $target, int $priority = 0): object
    {
        return (object) ['id' => $id, 'prerequisite_group_id' => $prerequisite, 'target_group_id' => $target, 'priority' => $priority];
    }
}
