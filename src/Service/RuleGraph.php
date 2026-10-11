<?php

namespace Dunea\MemberPromotion\Service;

use Dunea\MemberPromotion\Exception\RuleValidationException;

final class RuleGraph
{
    /**
     * @param array<int, array<string, mixed>|object> $rules
     * @return array<int, array<string, mixed>|object>
     */
    public function ordered(array $rules): array
    {
        $rows = array_values($rules);
        $targets = [];

        foreach ($rows as $index => $rule) {
            $target = (int) $this->value($rule, 'target_group_id');
            if (isset($targets[$target])) {
                throw new RuleValidationException([
                    'targetGroupId' => '启用规则不能重复授予同一目标组；这会使身份来源和执行顺序不明确。',
                ]);
            }
            $targets[$target] = $index;
        }

        $edges = array_fill(0, count($rows), []);
        $indegree = array_fill(0, count($rows), 0);
        foreach ($rows as $index => $rule) {
            $prerequisite = $this->value($rule, 'prerequisite_group_id');
            if ($prerequisite !== null && isset($targets[(int) $prerequisite])) {
                $parent = $targets[(int) $prerequisite];
                $edges[$parent][] = $index;
                ++$indegree[$index];
            }
        }

        $ready = [];
        foreach ($indegree as $index => $degree) {
            if ($degree === 0) {
                $ready[] = $index;
            }
        }

        $result = [];
        while ($ready) {
            usort($ready, function (int $left, int $right) use ($rows): int {
                $priority = (int) $this->value($rows[$right], 'priority') <=> (int) $this->value($rows[$left], 'priority');
                if ($priority !== 0) {
                    return $priority;
                }

                return (int) $this->value($rows[$left], 'id') <=> (int) $this->value($rows[$right], 'id');
            });
            $index = array_shift($ready);
            $result[] = $rows[$index];
            foreach ($edges[$index] as $child) {
                if (--$indegree[$child] === 0) {
                    $ready[] = $child;
                }
            }
        }

        if (count($result) !== count($rows)) {
            throw new RuleValidationException([
                'prerequisiteGroupId' => '规则依赖形成循环，包含间接循环，无法保存。',
            ]);
        }

        return $result;
    }

    /** @param array<string, mixed>|object $rule */
    private function value($rule, string $key)
    {
        return is_array($rule) ? ($rule[$key] ?? null) : ($rule->{$key} ?? null);
    }
}
