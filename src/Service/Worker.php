<?php

namespace Dunea\MemberPromotion\Service;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;
use Throwable;

final class Worker
{
    public const BATCH_SIZE = 100;
    public const TIME_BUDGET_SECONDS = 20;

    public function __construct(
        private ConnectionInterface $db,
        private SettingsRepositoryInterface $settings,
        private JobService $jobs,
        private PendingService $pending,
        private CounterService $counters,
        private Evaluator $evaluator,
        private AuditService $audit,
        private RuleService $rules
    ) {
    }

    /**
     * Optional limits are reserved for deterministic tests. Production entry
     * points call this method without overrides and always use the constants.
     *
     * @return array<string, int|float>
     */
    public function run(?int $batchSize = null, ?int $budgetSeconds = null): array
    {
        $batch = max(1, min(self::BATCH_SIZE, $batchSize ?? self::BATCH_SIZE));
        $budget = max(1, min(self::TIME_BUDGET_SECONDS, $budgetSeconds ?? self::TIME_BUDGET_SECONDS));
        $started = microtime(true);
        $automaticEnabled = $this->settings->get('dunea-member-promotion.enabled', '0') === '1';
        $activeRules = $automaticEnabled ? $this->rules->activeOrdered() : [];
        $stats = ['jobs' => 0, 'users' => 0, 'failed' => 0, 'elapsedSeconds' => 0.0];
        $this->runtime('last_started_at', Carbon::now('UTC')->toIso8601String());

        while (microtime(true) - $started < $budget) {
            $worked = false;
            if ($job = $this->claimJob($automaticEnabled)) {
                $worked = true;
                try {
                    $this->processJob($job, $batch);
                    // Refresh once per job page so an admin rule change is picked up without
                    // returning to per-user rule queries.
                    if ($automaticEnabled) {
                        $activeRules = $this->rules->activeOrdered();
                    }
                    ++$stats['jobs'];
                } catch (Throwable $e) {
                    ++$stats['failed'];
                    $this->failJob((int) $job->id, $e);
                }
            }

            if (microtime(true) - $started >= $budget) {
                break;
            }
            $remaining = max(1, min($batch, $batch - (int) $stats['users']));
            $claimed = $automaticEnabled ? $this->claimPending($remaining) : [];
            foreach ($claimed as $item) {
                $worked = true;
                try {
                    $onlyRuleId = preg_match('/^rule:(\d+)$/', (string) $item->reason, $match) ? (int) $match[1] : null;
                    $report = $this->evaluator->evaluate((int) $item->user_id, false, $onlyRuleId, $activeRules, false);
                    $nextCheckAt = isset($report['nextCheckAt']) && $report['nextCheckAt'] !== null
                        ? Carbon::parse($report['nextCheckAt'], 'UTC')
                        : null;
                    $this->pending->completeClaim((int) $item->user_id, (string) $item->claim_token, $nextCheckAt);
                    ++$stats['users'];
                } catch (Throwable $e) {
                    ++$stats['failed'];
                    $this->retryPending($item, $e);
                }
                if (microtime(true) - $started >= $budget || $stats['users'] >= $batch) {
                    break 2;
                }
            }
            if (! $worked || $stats['users'] >= $batch) {
                break;
            }
        }

        $this->cleanupLogs();
        $stats['elapsedSeconds'] = round(microtime(true) - $started, 4);
        $this->runtime('last_completed_at', Carbon::now('UTC')->toIso8601String());
        $this->runtime('last_stats', json_encode($stats));

        return $stats;
    }

    private function claimJob(bool $includeScans): ?object
    {
        return $this->db->transaction(function () use ($includeScans) {
            $stale = Carbon::now('UTC')->subMinutes(5);
            $query = $this->db->table('member_promotion_jobs')
                ->where(function ($query) use ($stale) {
                    $query->where('status', 'pending')->orWhere(function ($nested) use ($stale) {
                        $nested->where('status', 'processing')->where('claimed_at', '<', $stale);
                    });
                })
                ->where('attempts', '<', 3);
            if (! $includeScans) {
                $query->where('type', '<>', 'scan_users');
            }
            $job = $query->orderBy('id')->lockForUpdate()->first();
            if (! $job) {
                return null;
            }
            $token = bin2hex(random_bytes(16));
            $this->db->table('member_promotion_jobs')->where('id', $job->id)->update([
                'status' => 'processing', 'claim_token' => $token, 'claimed_at' => Carbon::now('UTC'),
                'attempts' => (int) $job->attempts + 1, 'updated_at' => Carbon::now('UTC'),
            ]);
            $job->claim_token = $token;

            return $job;
        });
    }

    private function processJob(object $job, int $batch): void
    {
        if ($job->type === 'scan_users' || $job->type === 'rebuild_counts') {
            $users = $this->db->table('users')->where('id', '>', $job->cursor)->orderBy('id')->limit($batch)->pluck('id')->all();
            if ($job->type === 'rebuild_counts') {
                foreach ($users as $userId) {
                    $this->counters->rebuildUser((int) $userId);
                }
            }
            $this->pending->enqueueMany($users, $job->rule_id ? 'rule:'.(int) $job->rule_id : $job->type);
            $cursor = $users ? max($users) : $job->cursor;
            $this->advanceJob($job, (int) $cursor, count($users), count($users) < $batch);
            return;
        }

        if ($job->type === 'discussion_changed' || $job->type === 'discussion_deleted') {
            $users = $this->db->table('member_promotion_discussion_users')->where('discussion_id', $job->discussion_id)
                ->where('user_id', '>', $job->cursor)->orderBy('user_id')->limit($batch)->pluck('user_id');
            foreach ($users as $userId) {
                $this->counters->invalidate((int) $userId, 'discussion_visibility_changed');
            }
            $cursor = $users->isNotEmpty() ? (int) $users->last() : (int) $job->cursor;
            $completed = $users->count() < $batch;
            $this->advanceJob($job, $cursor, $users->count(), $completed);
            if ($completed && $job->type === 'discussion_deleted') {
                $this->db->table('member_promotion_discussion_users')->where('discussion_id', $job->discussion_id)->delete();
            }
            return;
        }

        throw new \RuntimeException('Unknown member promotion job type: '.$job->type);
    }

    private function advanceJob(object $job, int $cursor, int $processed, bool $completed): void
    {
        $data = [
            'cursor' => $cursor, 'processed' => (int) $job->processed + $processed,
            'status' => $completed ? 'completed' : 'pending', 'claim_token' => null,
            'claimed_at' => null, 'attempts' => 0, 'updated_at' => Carbon::now('UTC'),
        ];
        if ($completed) {
            $data['completed_at'] = Carbon::now('UTC');
        }
        $this->db->table('member_promotion_jobs')->where('id', $job->id)->where('claim_token', $job->claim_token)->update($data);
    }

    /** @return object[] */
    private function claimPending(int $limit): array
    {
        $now = Carbon::now('UTC');
        $stale = $now->copy()->subMinutes(5);
        $candidates = $this->db->table('member_promotion_pending')
            ->where('available_at', '<=', $now)->where('attempts', '<', 3)
            ->where(function ($query) use ($stale) {
                $query->whereNull('claimed_at')->orWhere('claimed_at', '<', $stale);
            })->orderBy('available_at')->orderBy('user_id')->limit($limit * 2)->get();
        $claimed = [];
        foreach ($candidates as $candidate) {
            $token = bin2hex(random_bytes(16));
            $updated = $this->db->table('member_promotion_pending')->where('user_id', $candidate->user_id)
                ->where(function ($query) use ($stale) {
                    $query->whereNull('claimed_at')->orWhere('claimed_at', '<', $stale);
                })->update(['claim_token' => $token, 'claimed_at' => $now, 'updated_at' => $now]);
            if ($updated) {
                $candidate->claim_token = $token;
                $claimed[] = $candidate;
                if (count($claimed) >= $limit) {
                    break;
                }
            }
        }

        return $claimed;
    }

    private function retryPending(object $item, Throwable $error): void
    {
        $attempts = (int) $item->attempts + 1;
        $this->db->table('member_promotion_pending')->where('user_id', $item->user_id)
            ->where('claim_token', $item->claim_token)->update([
                'attempts' => $attempts, 'claim_token' => null, 'claimed_at' => null,
                'available_at' => Carbon::now('UTC')->addMinutes(min(60, 2 ** $attempts)),
                'last_error' => mb_substr($error->getMessage(), 0, 1000), 'updated_at' => Carbon::now('UTC'),
            ]);
        if ($attempts >= 3) {
            $this->audit->write((int) $item->user_id, null, 'failed', 'retry_exhausted', ['error' => mb_substr($error->getMessage(), 0, 500)]);
            $this->runtime('last_error', $error->getMessage());
        }
    }

    private function failJob(int $id, Throwable $error): void
    {
        $job = $this->db->table('member_promotion_jobs')->find($id);
        $attempts = (int) $job->attempts;
        $this->db->table('member_promotion_jobs')->where('id', $id)->update([
            'status' => $attempts >= 3 ? 'failed' : 'pending', 'claim_token' => null, 'claimed_at' => null,
            'last_error' => mb_substr($error->getMessage(), 0, 1000), 'updated_at' => Carbon::now('UTC'),
        ]);
        $this->runtime('last_error', $error->getMessage());
    }

    private function cleanupLogs(): void
    {
        $days = max(1, (int) $this->settings->get('dunea-member-promotion.log_retention_days', '90'));
        $cutoff = Carbon::now('UTC')->subDays($days);
        $logIds = $this->db->table('member_promotion_logs')->where('created_at', '<', $cutoff)
            ->orderBy('id')->limit(5000)->pluck('id')->all();
        if ($logIds) {
            $this->db->table('member_promotion_logs')->whereIn('id', $logIds)->delete();
        }

        $jobIds = $this->db->table('member_promotion_jobs')->where('status', 'completed')
            ->where('completed_at', '<', $cutoff)->orderBy('id')->limit(1000)->pluck('id')->all();
        if ($jobIds) {
            $this->db->table('member_promotion_jobs')->whereIn('id', $jobIds)->delete();
        }
    }

    private function runtime(string $key, ?string $value): void
    {
        $this->db->table('member_promotion_runtime')->updateOrInsert(
            ['key' => $key], ['value' => $value, 'updated_at' => Carbon::now('UTC')]
        );
    }
}
