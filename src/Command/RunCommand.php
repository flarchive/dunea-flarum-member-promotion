<?php

namespace Dunea\MemberPromotion\Command;

use Dunea\MemberPromotion\Service\Worker;
use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputOption;

final class RunCommand extends AbstractCommand
{
    public function __construct(private Worker $worker, private ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('member-promotion:run')
            ->setDescription('按固定的 100 人批大小和 20 秒时间预算处理会员晋级后台任务。')
            ->addOption('retry-failed', null, InputOption::VALUE_NONE, '重置已耗尽重试次数的任务和用户，再执行一批');
    }

    protected function fire()
    {
        if ($this->input->getOption('retry-failed')) {
            $this->db->table('member_promotion_jobs')->where('status', 'failed')->update([
                'status' => 'pending', 'attempts' => 0, 'last_error' => null, 'claim_token' => null, 'claimed_at' => null,
            ]);
            $this->db->table('member_promotion_pending')->where('attempts', '>=', 3)->update([
                'attempts' => 0, 'last_error' => null, 'claim_token' => null, 'claimed_at' => null,
            ]);
            $this->info('已重置失败任务；建议先确认最近错误的根因已经消除。');
        }
        $stats = $this->worker->run();
        $this->output->writeln(json_encode($stats, JSON_UNESCAPED_UNICODE));

        return 0;
    }
}
