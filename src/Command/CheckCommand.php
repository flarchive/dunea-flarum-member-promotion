<?php

namespace Dunea\MemberPromotion\Command;

use Dunea\MemberPromotion\Service\Evaluator;
use Dunea\MemberPromotion\Service\JobService;
use Dunea\MemberPromotion\Service\Worker;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

final class CheckCommand extends AbstractCommand
{
    public function __construct(
        private Evaluator $evaluator,
        private JobService $jobs,
        private Worker $worker
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('member-promotion:check')
            ->setDescription('检查指定用户，或将受预算限制的批量检查排入后台。')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, '用户 ID')
            ->addOption('rule', null, InputOption::VALUE_REQUIRED, '仅检查规则 ID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, '仅预览，不写入业务状态')
            ->addOption('all', null, InputOption::VALUE_NONE, '将全量用户扫描排入后台')
            ->addOption('foreground', null, InputOption::VALUE_NONE, '立即执行一个受批大小和时间预算限制的批次');
    }

    protected function fire()
    {
        $userId = $this->input->getOption('user');
        $ruleId = $this->input->getOption('rule');
        $dryRun = (bool) $this->input->getOption('dry-run');

        if ($userId !== null) {
            $report = $this->evaluator->evaluate((int) $userId, $dryRun, $ruleId !== null ? (int) $ruleId : null);
            $this->output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 0;
        }
        if ($dryRun) {
            $this->error('--dry-run 批量预览必须同时指定 --user，避免无意中前台扫描全站。');

            return 2;
        }

        $jobId = $this->jobs->enqueue('scan_users', $ruleId !== null ? (int) $ruleId : null);
        $this->info("已排入后台任务 #{$jobId}。不会在当前命令中全量扫描。");
        if ($this->input->getOption('foreground')) {
            $this->output->writeln(json_encode($this->worker->run(), JSON_UNESCAPED_UNICODE));
        }

        return 0;
    }
}
