<?php

namespace Dunea\MemberPromotion\Command;

use Dunea\MemberPromotion\Service\CounterService;
use Dunea\MemberPromotion\Service\JobService;
use Dunea\MemberPromotion\Service\Worker;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

final class RebuildCountsCommand extends AbstractCommand
{
    public function __construct(private CounterService $counters, private JobService $jobs, private Worker $worker)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('member-promotion:rebuild-counts')
            ->setDescription('初始化或重建有效回复计数。默认排入可恢复后台任务。')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, '仅重建用户 ID')
            ->addOption('foreground', null, InputOption::VALUE_NONE, '排入后立即执行一个受预算限制的批次');
    }

    protected function fire()
    {
        if (($userId = $this->input->getOption('user')) !== null) {
            $count = $this->counters->rebuildUser((int) $userId);
            $this->info("用户 {$userId} 的有效回复数已重建为 {$count}。");

            return 0;
        }
        $jobId = $this->jobs->enqueue('rebuild_counts');
        $this->info("计数重建已排入后台任务 #{$jobId}。");
        if ($this->input->getOption('foreground')) {
            $this->output->writeln(json_encode($this->worker->run(), JSON_UNESCAPED_UNICODE));
        }

        return 0;
    }
}
