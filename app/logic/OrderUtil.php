<?php
declare(strict_types=1);

namespace app\logic;

use app\logic\Pay;
use think\facade\Db;
use think\facade\Log;

/**
 * 表单付费订单业务：金额计算 / 下单（暂存提交）/ 支付成功转正 / 超时作废
 *
 * 金额一律由服务端按表单 pay_config 计算，前端数值不信任：
 *   fixed   → pay_config.amount
 *   options → 提交值在 pay_config.option_prices[field][value] 中查价
 */
class OrderUtil
{
    /** 待支付有效期（分钟） */
    public const EXPIRE_MINUTES = 15;

    /**
     * 读取表单收费配置（forms.settings_json.pay_config），未开启返回 null
     */
    public static function payConfig(array $form): ?array
    {
        $cfg = json_decode((string)($form['settings_json'] ?? '{}'), true);
        $pay = is_array($cfg) ? ($cfg['pay_config'] ?? null) : null;
        if (!is_array($pay) || empty($pay['enabled'])) {
            return null;
        }
        return $pay;
    }

    /**
     * 按表单配置计算应收金额
     * @param array $payConfig pay_config
     * @param array $values    访客提交的字段值 {field: value}
     * @return array{amount:float, label:string}
     * @throws \InvalidArgumentException 定价依据缺失/非法时
     */
    public static function calcAmount(array $payConfig, array $values): array
    {
        $mode = (string)($payConfig['mode'] ?? 'fixed');
        if ($mode === 'options') {
            $field = (string)($payConfig['option_field'] ?? '');
            $prices = (array)($payConfig['option_prices'] ?? []);
            $raw = $values[$field] ?? '';
            // 单选/下拉：可能是数组包裹的值
            if (is_array($raw)) {
                $raw = (string)($raw[0] ?? '');
            }
            $raw = trim((string)$raw);
            if ($raw === '' || !array_key_exists($raw, $prices)) {
                throw new \InvalidArgumentException('请先选择收费项目');
            }
            $amount = (float)$prices[$raw];
            if ($amount <= 0) {
                throw new \InvalidArgumentException('该选项金额配置有误，请联系管理员');
            }
            return ['amount' => $amount, 'label' => $raw];
        }
        $amount = round((float)($payConfig['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('表单金额未配置，请联系管理员');
        }
        return ['amount' => $amount, 'label' => (string)($payConfig['label'] ?? '表单提交')];
    }

    /**
     * 创建待支付订单 + 暂存提交（status=2 待支付，列表默认不显示）
     * @return array{order_no:string, amount:float}
     */
    public static function createPending(array $form, array $values, array $fieldData, array $ctx): array
    {
        $payConfig = self::payConfig($form);
        if (!$payConfig) {
            throw new \LogicException('该表单未开启收费');
        }
        $calc = self::calcAmount($payConfig, $values);
        $orderNo = Pay::genOrderNo();
        $now = date('Y-m-d H:i:s');

        $submissionId = Db::name('form_submissions')->insertGetId([
            'form_id'    => (int)$form['id'],
            'channel_id' => $ctx['channel_id'] ?? null,
            'data_json'  => json_encode($fieldData, JSON_UNESCAPED_UNICODE),
            'ip'         => (string)($ctx['ip'] ?? ''),
            'user_agent' => mb_substr((string)($ctx['ua'] ?? ''), 0, 500),
            'device'     => (string)($ctx['device'] ?? ''),
            'status'     => 2, // 待支付
            'pay_status' => 1,
            'order_no'   => $orderNo,
            'created_at' => $now,
        ]);

        Db::name('form_orders')->insert([
            'order_no'   => $orderNo,
            'form_id'    => (int)$form['id'],
            'submission_id' => $submissionId,
            'amount'     => $calc['amount'],
            'status'     => 0,
            'pay_type'   => (string)($ctx['pay_type'] ?? ''),
            'expire_at'  => date('Y-m-d H:i:s', strtotime('+' . self::EXPIRE_MINUTES . ' minutes')),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return ['order_no' => $orderNo, 'amount' => $calc['amount']];
    }

    /**
     * 为订单绑定支付方式
     */
    public static function setPayType(string $orderNo, string $payType): void
    {
        Db::name('form_orders')->where('order_no', $orderNo)->update([
            'pay_type' => $payType, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 支付成功：订单置已支付 + 提交转正式（进入审核或直接可见）+ 通知
     * 幂等：已支付直接返回 true
     */
    public static function markPaid(string $orderNo, string $tradeNo = '', ?string $notifyLog = null, string $buyer = ''): bool
    {
        $order = Db::name('form_orders')->where('order_no', $orderNo)->find();
        if (!$order) {
            return false;
        }
        if ((int)$order['status'] === 1) {
            return true;
        }
        if ((int)$order['status'] !== 0 && (int)$order['status'] !== 4) {
            return false; // 已取消/已退款不接受支付回调
        }
        $now = date('Y-m-d H:i:s');
        Db::name('form_orders')->where('id', $order['id'])->update([
            'status' => 1, 'trade_no' => $tradeNo, 'buyer_id' => $buyer,
            'paid_at' => $now, 'updated_at' => $now,
            'notify_log' => $notifyLog !== null ? mb_substr($notifyLog, 0, 60000) : $order['notify_log'],
        ]);
        if ($order['submission_id']) {
            $form = Db::name('forms')->where('id', $order['form_id'])->find();
            $needReview = $form ? (int)($form['need_review'] ?? 0) === 1 : false;
            Db::name('form_submissions')->where('id', $order['submission_id'])->update([
                'status' => $needReview ? 0 : 1,
                'pay_status' => 2,
            ]);
            if ($form) {
                try {
                    Notify::fireFormSubmit($form, ['order_no' => $orderNo], (int)$order['submission_id']);
                } catch (\Throwable $e) {
                    Log::write('[pay] 支付通知发送失败: ' . $e->getMessage(), 'notice');
                }
            }
        }
        Log::write('[pay] 订单已支付 ' . $orderNo . ' ¥' . $order['amount'] . ' trade=' . $tradeNo, 'notice');
        return true;
    }

    /**
     * 超时作废：把过期未支付订单取消，暂存提交进回收站（可查可不查）
     * 由每日清理任务调用；返回处理条数
     */
    public static function cancelExpired(): int
    {
        $now = date('Y-m-d H:i:s');
        $orders = Db::name('form_orders')
            ->where('status', 0)
            ->where('expire_at', '<', $now)
            ->field('id, submission_id')
            ->select()->toArray();
        if (!$orders) {
            return 0;
        }
        $ids = array_column($orders, 'id');
        $subIds = array_filter(array_column($orders, 'submission_id'));
        Db::name('form_orders')->whereIn('id', $ids)->update([
            'status' => 2, 'updated_at' => $now, 'remark' => '超时未支付自动取消',
        ]);
        if ($subIds) {
            Db::name('form_submissions')->whereIn('id', $subIds)->update([
                'status' => 2, // 保持待支付状态标记，deleted_at 置空但列表不展示（pay_status=1）
                'deleted_at' => $now, // 直接进回收站，避免污染数据视图
                'updated_at' => $now,
            ]);
        }
        return count($ids);
    }
}
