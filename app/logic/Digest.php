<?php
declare(strict_types=1);

namespace app\logic;

use think\facade\Cache;

/**
 * 每日数据汇总邮件：每日首次进后台时发送一次（缓存节流），
 * 内容为「昨日提交数 / 今日待审核 / 累计总量」摘要。
 */
class Digest
{
    public static function maybeSend(): void
    {
        if (Setting::get('digest_enabled', '0') !== '1') {
            return;
        }
        $to = trim(Setting::get('digest_email', ''));
        if ($to === '') {
            return;
        }
        $today = date('Y-m-d');
        $flag = 'digest_sent_' . $today;
        if (Cache::get($flag)) {
            return;
        }
        Cache::set($flag, 1, 86400);

        try {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $total = Db::name('form_submissions')->whereNull('deleted_at')->count();
            $yCount = Db::name('form_submissions')
                ->whereNull('deleted_at')
                ->whereTime('created_at', '>=', $yesterday)
                ->count();
            $pending = Db::name('form_submissions')
                ->whereNull('deleted_at')->where('status', 0)->count();

            $sysName = Setting::get('sys_name', 'OwnForm') ?: 'OwnForm';
            $html = '<div style="font-family:-apple-system,PingFang SC,sans-serif;max-width:560px">'
                . '<h2 style="font-size:16px">' . htmlspecialchars($sysName) . ' · 每日数据汇总</h2>'
                . '<table cellpadding="8" style="border-collapse:collapse;font-size:14px">'
                . '<tr><td style="color:#909399">昨日提交</td><td><b>' . $yCount . '</b> 条</td></tr>'
                . '<tr><td style="color:#909399">累计提交</td><td><b>' . $total . '</b> 条</td></tr>'
                . '<tr><td style="color:#909399">待审核</td><td><b>' . $pending . '</b> 条</td></tr>'
                . '</table>'
                . '<p style="color:#c0c4cc;font-size:12px">发送时间：' . date('Y-m-d H:i') . '</p>'
                . '</div>';

            $res = Mail::send($to, $sysName . ' 每日数据汇总', $html);
            if (!empty($res['ok'])) {
                Log::write('[digest] 每日汇总已发送至 ' . $to, 'notice');
            } else {
                Log::write('[digest] 每日汇总发送失败：' . ($res['msg'] ?? ''), 'notice');
                Cache::delete($flag); // 允许下个请求重试
            }
        } catch (\Throwable $e) {
            Log::write('[digest] 每日汇总异常：' . $e->getMessage(), 'notice');
            Cache::delete($flag);
        }
    }
}
