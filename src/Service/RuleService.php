<?php

namespace Dunea\MemberPromotion\Service;

use Carbon\Carbon;
use Dunea\MemberPromotion\Exception\RuleValidationException;
use Flarum\Group\Group;
use Illuminate\Database\ConnectionInterface;

final class RuleService
{
    public function __construct(
        private ConnectionInterface $db,
        private RuleGraph $graph,
        private JobService $jobs
    ) {
    }

    /** @return array<int, object> */
    public function activeOrdered(): array
    {
        $rules = $this->db->table('member_promotion_rules')
            ->where('enabled', true)->where('archived', false)->get()->all();

        return $this->graph->ordered($rules);
    }

    /** @param array<string, mixed> $input */
    public function create(array $input, int $actorId): object
    {
        $data = $this->normalizeAndValidate($input);
        $now = Carbon::now('UTC');
        $data += ['version' => 1, 'created_by' => $actorId, 'updated_by' => $actorId, 'created_at' => $now, 'updated_at' => $now];

        return $this->db->transaction(function () use ($data, $actorId, $now) {
            $this->assertGraphWith($data + ['id' => 0], null);
            $id = (int) $this->db->table('member_promotion_rules')->insertGetId($data);
            $data['id'] = $id;
            $this->snapshot($data, $actorId, $now);
            if ($data['enabled']) {
                $this->jobs->enqueue('scan_users', $id);
            }

            return $this->db->table('member_promotion_rules')->find($id);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array{rule?: object, preview?: array<string, mixed>}
     */
    public function update(int $id, array $input, int $actorId, bool $confirmed): array
    {
        $existing = $this->db->table('member_promotion_rules')->where('archived', false)->find($id);
        if (! $existing) {
            throw new RuleValidationException(['id' => '规则不存在。']);
        }

        $merged = array_merge((array) $existing, $input);
        $data = $this->normalizeAndValidate($merged);
        $structural = ['target_group_id', 'prerequisite_group_id', 'remove_prerequisite'];
        $changes = [];
        foreach ($structural as $field) {
            if ((string) $existing->{$field} !== (string) $data[$field]) {
                $changes[$field] = ['from' => $existing->{$field}, 'to' => $data[$field]];
            }
        }

        if ($changes && ! $confirmed) {
            return ['preview' => [
                'requiresConfirmation' => true,
                'changes' => $changes,
                'activeGrantCount' => $this->db->table('member_promotion_grants')->where('rule_id', $id)->where('status', 'active')->count(),
                'message' => '结构性修改不会迁移或撤销历史成员；确认后仅影响后续评估。',
            ]];
        }

        $version = (int) $existing->version + 1;
        $data += ['updated_by' => $actorId, 'updated_at' => Carbon::now('UTC')];
        $data['version'] = $version;
        $shouldScan = (bool) $data['enabled'] && $this->evaluationChanged($existing, $data);

        $rule = $this->db->transaction(function () use ($id, $data, $actorId, $shouldScan) {
            $this->assertGraphWith($data + ['id' => $id], $id);
            $this->db->table('member_promotion_rules')->where('id', $id)->update($data);
            $snapshot = (array) $this->db->table('member_promotion_rules')->find($id);
            $this->snapshot($snapshot, $actorId, Carbon::now('UTC'));
            if ($shouldScan) {
                $this->jobs->enqueue('scan_users', $id);
            }

            return (object) $snapshot;
        });

        return ['rule' => $rule];
    }

    public function copy(int $id, int $actorId): object
    {
        $source = $this->db->table('member_promotion_rules')->where('archived', false)->find($id);
        if (! $source) {
            throw new RuleValidationException(['id' => '规则不存在。']);
        }
        $data = (array) $source;
        unset($data['id'], $data['version'], $data['created_at'], $data['updated_at'], $data['created_by'], $data['updated_by']);
        $data['name'] .= '（副本）';
        $data['enabled'] = false;

        return $this->create($data, $actorId);
    }

    public function delete(int $id, int $actorId): void
    {
        $updated = $this->db->table('member_promotion_rules')->where('id', $id)->where('archived', false)->update([
            'enabled' => false, 'archived' => true, 'updated_by' => $actorId, 'updated_at' => Carbon::now('UTC'),
        ]);
        if ($updated !== 1) {
            throw new RuleValidationException(['id' => '规则不存在或已删除。']);
        }
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function normalizeAndValidate(array $input): array
    {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            $errors['name'] = '规则名称必填，且不能超过 150 个字符。';
        }
        $ageEnabled = filter_var($input['ageEnabled'] ?? $input['age_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $repliesEnabled = filter_var($input['repliesEnabled'] ?? $input['replies_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        if (! $ageEnabled && ! $repliesEnabled) {
            $errors['conditions'] = '至少启用注册时长或有效回复数中的一个条件。';
        }

        $ageOperator = (string) ($input['ageOperator'] ?? $input['age_operator'] ?? '>=');
        $replyOperator = (string) ($input['repliesOperator'] ?? $input['replies_operator'] ?? '>=');
        if (! in_array($ageOperator, ['>', '>='], true)) {
            $errors['ageOperator'] = '注册时长比较符只能是 > 或 >=。';
        }
        if (! in_array($replyOperator, ['>', '>='], true)) {
            $errors['repliesOperator'] = '回复数比较符只能是 > 或 >=。';
        }

        $ageMinutes = $input['age_minutes'] ?? null;
        if (array_key_exists('ageValue', $input)) {
            $unit = $input['ageUnit'] ?? 'days';
            if (! in_array($unit, ['hours', 'days'], true)) {
                $errors['ageUnit'] = '注册时长单位只能是小时或天。';
            }
            $ageValue = filter_var($input['ageValue'], FILTER_VALIDATE_INT);
            if ($ageValue === false || $ageValue < 0) {
                $errors['ageValue'] = '注册时长必须是非负整数。';
            } else {
                $ageMinutes = $ageValue * ($unit === 'hours' ? 60 : 1440);
            }
        }
        if ($ageEnabled && (! is_numeric($ageMinutes) || (int) $ageMinutes < 0)) {
            $errors['ageValue'] = '注册时长必须是非负整数。';
        }

        $replies = array_key_exists('repliesCount', $input) ? $input['repliesCount'] : ($input['replies_count'] ?? null);
        if ($repliesEnabled && (! is_numeric($replies) || (int) $replies < 0 || (string) (int) $replies !== (string) $replies)) {
            $errors['repliesCount'] = '有效回复数必须是非负整数。';
        }

        $prerequisite = array_key_exists('prerequisiteGroupId', $input) ? $input['prerequisiteGroupId'] : ($input['prerequisite_group_id'] ?? null);
        $target = array_key_exists('targetGroupId', $input) ? $input['targetGroupId'] : ($input['target_group_id'] ?? null);
        $prerequisite = $prerequisite === '' || $prerequisite === null ? null : (int) $prerequisite;
        $target = (int) $target;
        if ($target <= Group::MODERATOR_ID || ! $this->db->table('groups')->where('id', $target)->exists()) {
            $errors['targetGroupId'] = '目标组必须是存在的自定义用户组，不能使用管理员、游客、会员或版主内置组。';
        }
        if ($prerequisite !== null && ($prerequisite <= Group::MODERATOR_ID || ! $this->db->table('groups')->where('id', $prerequisite)->exists())) {
            $errors['prerequisiteGroupId'] = '前置组必须是存在的自定义用户组。';
        }
        if ($prerequisite !== null && $prerequisite === $target) {
            $errors['prerequisiteGroupId'] = '前置组与目标组不能相同。';
        }
        if ($errors) {
            throw new RuleValidationException($errors);
        }

        return [
            'name' => $name,
            'description' => trim((string) ($input['description'] ?? '')) ?: null,
            'enabled' => filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'archived' => filter_var($input['archived'] ?? false, FILTER_VALIDATE_BOOL),
            'priority' => (int) ($input['priority'] ?? 0),
            'prerequisite_group_id' => $prerequisite,
            'target_group_id' => $target,
            'remove_prerequisite' => filter_var($input['removePrerequisite'] ?? $input['remove_prerequisite'] ?? false, FILTER_VALIDATE_BOOL),
            'age_enabled' => $ageEnabled,
            'age_minutes' => $ageEnabled ? (int) $ageMinutes : null,
            'age_operator' => $ageOperator,
            'replies_enabled' => $repliesEnabled,
            'replies_count' => $repliesEnabled ? (int) $replies : null,
            'replies_operator' => $replyOperator,
        ];
    }

    /** @param array<string, mixed> $candidate */
    private function assertGraphWith(array $candidate, ?int $replacingId): void
    {
        if (! $candidate['enabled'] || $candidate['archived']) {
            return;
        }
        $query = $this->db->table('member_promotion_rules')->where('enabled', true)->where('archived', false);
        if ($replacingId !== null) {
            $query->where('id', '<>', $replacingId);
        }
        $this->graph->ordered(array_merge($query->get()->all(), [(object) $candidate]));
    }

    /** @param array<string, mixed> $data */
    private function snapshot(array $data, int $actorId, Carbon $now): void
    {
        $this->db->table('member_promotion_rule_versions')->insert([
            'rule_id' => $data['id'], 'version' => $data['version'],
            'snapshot_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_by' => $actorId, 'created_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function evaluationChanged(object $existing, array $data): bool
    {
        foreach (['enabled', 'remove_prerequisite', 'age_enabled', 'replies_enabled'] as $field) {
            if ((bool) $existing->{$field} !== (bool) $data[$field]) {
                return true;
            }
        }
        foreach (['priority', 'target_group_id'] as $field) {
            if ((int) $existing->{$field} !== (int) $data[$field]) {
                return true;
            }
        }
        foreach (['prerequisite_group_id', 'age_minutes', 'replies_count'] as $field) {
            $old = $existing->{$field} === null ? null : (int) $existing->{$field};
            $new = $data[$field] === null ? null : (int) $data[$field];
            if ($old !== $new) {
                return true;
            }
        }
        foreach (['age_operator', 'replies_operator'] as $field) {
            if ((string) $existing->{$field} !== (string) $data[$field]) {
                return true;
            }
        }

        return false;
    }
}
