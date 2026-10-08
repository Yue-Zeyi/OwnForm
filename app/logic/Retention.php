<?php
declare(strict_types=1);

namespace app\logic;

use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

/**
 * 数据保留策略 + 孤儿附件清理 + 每日汇总邮件
 *
 * 触发：每日首个「数据概览」请求（DataApi::dashboard）自动调一次 run()（缓存节流），
 * 推荐在宝塔挂计划任务 `php think cleanup:run` 每日执行（无人登录也照常运行）。
 */
class Retention
{
    /** 提交数据保留天数（0=永久），0.0.3 起由系统设置 retention_days 控制 */
    public static function retentionDays(): int
    {
        return max(0, (int)Setting::get('retention_days', '0'));
    }

    /**
     * 每日一次的清理 + 汇总（缓存节流，跨请求幂等）
     */
    public static function run(bool $force = false): array
    {
        $today = date('Y-m-d');
        $flag = 'retention_ran_' . $today;
        if (!$force && Cache::get($flag)) {
            return ['skipped' => true];
        }
        Cache::set($flag, 1, 86400);

        $out = self::cleanup();
        $out['digest'] = false;

        // 每日汇总邮件（开启且配置了收件箱时；手动清理不发送）
        Digest::maybeSend();

        return $out;
    }

    /**
     * 执行一轮清理：过期提交 + 孤儿附件（定时任务与后台手动触发共用）
     * 不做节流、不发汇总邮件，返回删除统计
     */
    public static function cleanup(): array
    {
        $retention = self::retentionDays();
        $out = [
            'submissions' => 0,
            'uploads'     => 0,
        ];

        // 1) 过期提交清理（永久删除，回收站一并清；保留期优先于回收站）
        if ($retention > 0) {
            $line = date('Y-m-d H:i:s', strtotime('-' . $retention . ' days'));
            $out['submissions'] = Db::name('form_submissions')
                ->where('created_at', '<', $line)
                ->delete();
            if ($out['submissions']) {
                Log::write('[retention] 清理过期提交 ' . $out['submissions'] . ' 条（保留 ' . $retention . ' 天）', 'notice');
            }
        }

        // 2) 孤儿附件清理：所属表单已被彻底删除、且附件超过 30 天 → 一并清理
        $out['uploads'] = self::cleanOrphanUploads(30);

        return $out;
    }

    /**
     * 手动清理：按范围与各自天数执行（后台「立即执行清理」用）
     * 范围：expired=未删除但已过期的提交 / recycle=回收站内容 / orphan=孤儿附件
     * 每项天数独立控制（1-3650），全部永久删除、不可恢复
     */
    public static function manualClean(array $scopes, array $days): array
    {
        $out = ['expired' => 0, 'recycle' => 0, 'uploads' => 0];

        if (in_array('expired', $scopes, true)) {
            $d = self::clampDays($days['expired'] ?? 90);
            $line = date('Y-m-d H:i:s', strtotime('-' . $d . ' days'));
            $out['expired'] = Db::name('form_submissions')
                ->where('created_at', '<', $line)
                ->whereNull('deleted_at')
                ->delete();
        }

        if (in_array('recycle', $scopes, true)) {
            $d = self::clampDays($days['recycle'] ?? 30);
            $line = date('Y-m-d H:i:s', strtotime('-' . $d . ' days'));
            $out['recycle'] = Db::name('form_submissions')
                ->whereNotNull('deleted_at')
                ->where('deleted_at', '<', $line)
                ->delete();
        }

        if (in_array('orphan', $scopes, true)) {
            $d = self::clampDays($days['orphan'] ?? 30);
            $out['uploads'] = self::cleanOrphanUploads($d);
        }

        if ($out['expired'] || $out['recycle'] || $out['uploads']) {
            Log::write('[retention] 手动清理：过期提交 ' . $out['expired']
                . ' 条，回收站 ' . $out['recycle'] . ' 条，孤儿附件 ' . $out['uploads'] . ' 个', 'notice');
        }
        return $out;
    }

    /** 天数收敛到 1-3650 */
    private static function clampDays(mixed $v): int
    {
        return max(1, min(3650, (int)$v));
    }

    /**
     * 孤儿附件：所属表单已被彻底删除（或不存在）且附件超过 $days 天 → 删记录+删存储文件
     */
    public static function cleanOrphanUploads(int $days = 30): int
    {
        $line = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        $rows = Db::name('uploads')->alias('u')
            ->leftJoin('forms f', 'f.id = u.form_id')
            ->where('u.form_id', '>', 0)
            ->whereNull('f.id')
            ->where('u.created_at', '<', $line)
            ->field('u.id, u.path')
            ->select()
            ->toArray();
        $n = 0;
        foreach ($rows as $r) {
            try {
                Storage::deleteObject((string)$r['path']);
            } catch (\Throwable $e) {
                Log::write('[retention] 附件文件删除失败 ' . $r['path'] . ': ' . $e->getMessage(), 'notice');
            }
            Db::name('uploads')->where('id', $r['id'])->delete();
            $n++;
        }
        if ($n) {
            Log::write('[retention] 清理孤儿附件 ' . $n . ' 个', 'notice');
        }
        return $n;
    }
}
