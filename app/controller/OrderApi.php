<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\logic\OpLog;
use app\logic\Pay;
use think\facade\Db;

/**
 * 订单管理（管理端）
 *
 *   GET    api/orders                 流水列表（表单/状态/关键词/日期筛选）
 *   GET    api/orders/stats           收入汇总（列表头部卡片）
 *   GET    api/orders/:id             订单详情
 *   POST   api/orders/:id/verify      转账核销（表单创建者/管理员/all 授权者）
 *   POST   api/orders/:id/refund      原路退款（同核销权限）
 *   POST   api/orders/:id/cancel      取消订单（同核销权限）
 */
class OrderApi extends BaseController
{
    /** 订单状态中文 */
    public const STATUS = [
        0 => '待支付', 1 => '已支付', 2 => '已取消', 3 => '已退款', 4 => '待核销',
    ];

    /**
     * 订单操作权限：管理员 / 表单创建者 / 数据范围 all 的授权成员
     */
    private function canOperate(array $order): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        $form = Db::name('forms')->where('id', $order['form_id'])->field('id,user_id')->find();
        if (!$form) {
            return false;
        }
        if ((int)$form['user_id'] === $this->uid()) {
            return true;
        }
        $b = $this->authForms()[(int)$form['id']] ?? null;
        return $b !== null && $b['data_scope'] === 'all';
    }

    public function index()
    {
        $page    = max(1, (int)input('page', 1));
        $size    = min(100, max(1, (int)input('size', 20)));
        $formId  = (int)input('formId', 0);
        $status  = input('status', '');
        $keyword = trim((string)input('keyword', ''));
        $start   = trim((string)input('start', ''));
        $end     = trim((string)input('end', ''));

        // 可见范围：管理员全部；成员 = 自己创建的表单 + all 范围授权表单的订单
        if ($this->isAdmin()) {
            $formIds = null;
        } else {
            $formIds = array_map('intval', Db::name('forms')
                ->where('user_id', $this->uid())->whereNull('deleted_at')->column('id'));
            foreach ($this->authForms() as $fid => $b) {
                if ($b['data_scope'] === 'all') {
                    $formIds[] = (int)$fid;
                }
            }
            $formIds = $formIds ?: [0];
        }

        $q = Db::name('form_orders')->alias('o')
            ->leftJoin('forms f', 'f.id = o.form_id')
            ->field('o.*, f.title as form_title, f.user_id');
        if ($formIds !== null) {
            $q->whereIn('o.form_id', $formIds);
        }
        if ($formId > 0) {
            $q->where('o.form_id', $formId);
        }
        if ($status !== '' && $status !== null) {
            $q->where('o.status', (int)$status);
        }
        if ($keyword !== '') {
            $safe = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword);
            $q->whereLike('o.order_no', '%' . $safe . '%');
        }
        if ($start !== '') {
            $q->where('o.created_at', '>=', $start . ' 00:00:00');
        }
        if ($end !== '') {
            $q->where('o.created_at', '<=', $end . ' 23:59:59');
        }
        $total = (clone $q)->count();
        $list = $q->order('o.id', 'desc')->page($page, $size)->select()->toArray();
        foreach ($list as &$r) {
            $r['status_text'] = self::STATUS[(int)$r['status']] ?? (string)$r['status'];
            $r['channel_name'] = Pay::CHANNEL_NAMES[$r['pay_type']] ?? $r['pay_type'];
        }
        return $this->ok(['list' => $list, 'total' => $total, 'page' => $page, 'size' => $size]);
    }

    /**
     * 收入汇总：已支付总额 / 今日已支付 / 待核销笔数 / 退款总额
     */
    public function stats()
    {
        if ($this->isAdmin()) {
            $formIds = null;
        } else {
            $formIds = array_map('intval', Db::name('forms')
                ->where('user_id', $this->uid())->whereNull('deleted_at')->column('id'));
            foreach ($this->authForms() as $fid => $b) {
                if ($b['data_scope'] === 'all') {
                    $formIds[] = (int)$fid;
                }
            }
            $formIds = $formIds ?: [0];
        }
        $base = Db::name('form_orders');
        if ($formIds !== null) {
            $base = $base->whereIn('form_id', $formIds);
        }
        $paid = (clone $base)->where('status', 1);
        return $this->ok([
            'paid_amount'   => (float)(clone $paid)->sum('amount'),
            'paid_count'    => (clone $paid)->count(),
            'today_amount'  => (float)(clone $base)->where('status', 1)->whereTime('paid_at', 'today')->sum('amount'),
            'today_count'   => (clone $base)->where('status', 1)->whereTime('paid_at', 'today')->count(),
            'wait_verify'   => (clone $base)->where('status', 4)->count(),
            'refund_amount' => (float)(clone $base)->where('status', 3)->sum('refund_amount'),
        ]);
    }

    public function read(int $id)
    {
        $order = Db::name('form_orders')->alias('o')
            ->leftJoin('forms f', 'f.id = o.form_id')
            ->field('o.*, f.title as form_title')
            ->where('o.id', $id)->find();
        if (!$order || !$this->canOperate($order)) {
            return $this->fail('订单不存在', 404);
        }
        $order['status_text'] = self::STATUS[(int)$order['status']] ?? '';
        $order['channel_name'] = Pay::CHANNEL_NAMES[$order['pay_type']] ?? $order['pay_type'];
        if ($order['submission_id']) {
            $sub = Db::name('form_submissions')
                ->where('id', $order['submission_id'])
                ->field('id, data_json, ip, device, created_at')
                ->find();
            $order['submission'] = $sub;
        }
        return $this->ok($order);
    }

    /**
     * 转账核销：确认到账，订单转已支付
     */
    public function verify(int $id)
    {
        $order = Db::name('form_orders')->where('id', $id)->find();
        if (!$order || !$this->canOperate($order)) {
            return $this->fail('订单不存在', 404);
        }
        if ((int)$order['status'] !== 4) {
            return $this->fail('仅「待核销」订单可以核销', 422);
        }
        if (OrderUtil::markPaid($order['order_no'], '', null, 'transfer')) {
            Db::name('form_orders')->where('id', $id)->update([
                'verify_by' => $this->uid(), 'verify_at' => date('Y-m-d H:i:s'),
            ]);
            OpLog::write('order', '转账核销', '订单 ' . $order['order_no'] . ' ¥' . $order['amount']);
            return $this->ok(['msg' => '已核销 ¥' . $order['amount']]);
        }
        return $this->fail('核销失败，订单状态已变化', 422);
    }

    /**
     * 原路退款
     */
    public function refund(int $id)
    {
        $order = Db::name('form_orders')->where('id', $id)->find();
        if (!$order || !$this->canOperate($order)) {
            return $this->fail('订单不存在', 404);
        }
        if ((int)$order['status'] !== 1) {
            return $this->fail('仅「已支付」订单可以退款', 422);
        }
        $reason = trim((string)input('reason', ''));
        if ($reason === '') {
            return $this->fail('请填写退款原因');
        }
        try {
            $res = Pay::refund($order, $reason);
        } catch (\Throwable $e) {
            \think\facade\Log::write('[pay] 退款失败 ' . $order['order_no'] . ': ' . $e->getMessage(), 'notice');
            return $this->fail('退款失败：' . $e->getMessage(), 502);
        }
        if (!$res['ok']) {
            return $this->fail($res['msg'], 422);
        }
        $now = date('Y-m-d H:i:s');
        Db::name('form_orders')->where('id', $id)->update([
            'status'        => 3,
            'refund_no'     => $res['refund_no'],
            'refund_amount' => $order['amount'],
            'refunded_at'   => $now,
            'remark'        => mb_substr($reason, 0, 255),
            'updated_at'    => $now,
        ]);
        if ($order['submission_id']) {
            Db::name('form_submissions')->where('id', $order['submission_id'])->update([
                'pay_status' => 4, 'updated_at' => $now,
            ]);
        }
        OpLog::write('order', '订单退款', '订单 ' . $order['order_no'] . ' ¥' . $order['amount'] . '：' . $reason);
        return $this->ok(['msg' => $res['msg']]);
    }

    /**
     * 取消待支付订单（后台手动）
     */
    public function cancel(int $id)
    {
        $order = Db::name('form_orders')->where('id', $id)->find();
        if (!$order || !$this->canOperate($order)) {
            return $this->fail('订单不存在', 404);
        }
        if ((int)$order['status'] !== 0) {
            return $this->fail('仅「待支付」订单可以取消', 422);
        }
        $now = date('Y-m-d H:i:s');
        Db::name('form_orders')->where('id', $id)->update([
            'status' => 2, 'updated_at' => $now, 'remark' => '后台手动取消',
        ]);
        if ($order['submission_id']) {
            Db::name('form_submissions')->where('id', $order['submission_id'])->update([
                'deleted_at' => $now,
            ]);
        }
        OpLog::write('order', '取消订单', '订单 ' . $order['order_no']);
        return $this->ok(['msg' => '已取消']);
    }
}
