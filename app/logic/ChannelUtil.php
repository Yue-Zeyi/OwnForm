<?php
declare(strict_types=1);

namespace app\logic;

use think\facade\Db;

/**
 * 表单渠道工具：渠道码生成与归因解析
 */
class ChannelUtil
{
    /**
     * 生成全局唯一渠道码（去易混淆字符，与表单 slug 同风格但独立命名空间）
     */
    public static function genCode(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while (Db::name('form_channels')->where('code', $code)->count() > 0);
        return $code;
    }

    /**
     * 按渠道码解析可归因的渠道
     *
     * 停用渠道仍照常归因（链接可能还在外部流传，归因比丢失数据更真实），
     * 仅排除已删除与不属于该表单的渠道码；一律返回 null 时由调用方
     * 降级为"直接访问"，不拦截提交。
     */
    public static function resolveActive(string $code, int $formId): ?array
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 16) {
            return null;
        }
        $ch = Db::name('form_channels')
            ->where('code', $code)
            ->where('form_id', $formId)
            ->whereNull('deleted_at')
            ->find();
        if (!$ch) {
            return null;
        }
        // 定时上下线：不在窗口内按"未推广"处理（降级直接访问，不拦截提交）
        return self::isOnline($ch) ? $ch : null;
    }

    /**
     * 渠道是否处于可推广窗口：
     * status=1 且 online_from/online_until 窗口命中（NULL=不限，until 为含当日全天）
     */
    public static function isOnline(array $ch): bool
    {
        if ((int)($ch['status'] ?? 1) !== 1) {
            return false;
        }
        $now = time();
        $from = trim((string)($ch['online_from'] ?? ''));
        $until = trim((string)($ch['online_until'] ?? ''));
        if ($from !== '' && strtotime($from) > $now) {
            return false;
        }
        if ($until !== '' && strtotime($until . ' 23:59:59') < $now) {
            return false;
        }
        return true;
    }

    /**
     * 渠道分享链接：/s/{表单slug}/{渠道码}
     */
    public static function shareUrl(string $slug, string $code): string
    {
        return request()->domain() . '/s/' . $slug . '/' . $code;
    }
}
