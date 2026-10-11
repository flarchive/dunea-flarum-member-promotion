<?php

namespace Dunea\MemberPromotion\Command;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;

final class StatusCommand extends AbstractCommand
{
    public function __construct(private ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('member-promotion:status')->setDescription('显示会员晋级任务、心跳与失败状态。');
    }

    protected function fire()
    {
        $runtime = $this->db->table('member_promotion_runtime')->pluck('value', 'key');
        $status = [
            'heartbeat' => $runtime['last_completed_at'] ?? null,
            'message' => isset($runtime['last_completed_at']) ? '已检测到调度运行' : '尚未检测到运行',
            'pendingUsers' => $this->db->table('member_promotion_pending')->count(),
            'pendingJobs' => $this->db->table('member_promotion_jobs')->whereIn('status', ['pending', 'processing'])->count(),
            'failedJobs' => $this->db->table('member_promotion_jobs')->where('status', 'failed')->count(),
            'lastError' => $runtime['last_error'] ?? null,
        ];
        $this->output->writeln(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return 0;
    }
}
