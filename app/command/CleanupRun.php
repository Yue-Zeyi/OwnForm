<?php
declare(strict_types=1);

namespace app\command;

use app\logic\Retention;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 数据保留策略与孤儿附件清理：
 *   php think cleanup:run [--force]
 * 宝塔计划任务（每天一次）：
 *   php /www/wwwroot/站点目录/think cleanup:run
 */
class CleanupRun extends Command
{
    protected function configure()
    {
        $this->setName('cleanup:run')
            ->addOption('force', 'f', Option::VALUE_NONE, '忽略当日节流，强制执行')
            ->setDescription('数据保留策略清理（过期提交 / 孤儿附件）并发送每日汇总');
    }

    protected function execute(Input $input, Output $output)
    {
        $res = Retention::run((bool)$input->getOption('force'));
        if (!empty($res['skipped'])) {
            $output->writeln('今日已执行过，跳过（--force 可强制）');
            return;
        }
        $output->writeln('清理过期提交：' . ($res['submissions'] ?? 0) . ' 条');
        $output->writeln('清理孤儿附件：' . ($res['uploads'] ?? 0) . ' 个');
        $output->writeln('每日汇总邮件：' . (!empty($res['digest']) ? '已发送' : '未发送（未开启或未配置）'));
        $output->writeln('done');
    }
}
