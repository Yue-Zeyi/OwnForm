<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\logic\FieldUtil;
use app\logic\OrderUtil;
use app\logic\Pay;
use app\logic\Setting;
use app\middleware\Csrf;
use think\facade\Db;
use think\facade\Log;

/**
 * 支付接口（访客侧 + 渠道回调）
 *
 * 访客：
 *   POST api/fill/:slug/order            创建订单并获取支付要素（免登录）
 *   GET  api/fill/order/:orderNo/status  轮询订单状态（免登录）
 *   POST api/fill/order/:orderNo/voucher 转账凭证上传（免登录，按订单号定位）
 * 渠道回调：
 *   POST api/pay/notify/wechat | alipay
 */
class PayApi extends BaseController
{
    /** 表单收费时可用的渠道（全局勾选 ∩ 渠道实现） */
    public const CHANNELS = ['wxpay_native', 'wxpay_h5', 'alipay_page', 'alipay_fce', 'alipay_wap', 'transfer'];

    /**
     * POST api/fill/:slug/order
     * body: { data: {字段}, pay_type: 'wxpay_native', ...原有提交上下文 }
     * 返回: { code:0, data:{ payRequired:true, orderNo, amount, payType, qr?, redirect?, transfer? } }
     */
    public function order(string $slug)
    {
        $form = FillApi::loadActiveForm($slug);
        if (is_string($form)) {
            return $this->fail($form, 4004);
        }
        if (License::isLocked()) {
            return $this->fail('系统授权已到期，暂停收集，请联系系统提供方', 4009);
        }
        $payConfig = OrderUtil::payConfig($form);
        if (!$payConfig) {
            return $this->fail('该表单无需支付', 422);
        }
        $payType = (string)input('post.pay_type', '');
        $enabled = Pay::enabledChannels();
        if (!in_array($payType, $enabled, true)) {
            return $this->fail('该支付方式不可用', 422);
        }

        $decoded = json_decode((string)$form['settings_json'], true);
        $settings = FieldUtil::normalizeSettings(is_array($decoded) ? $decoded : []);
        $fill = new FillApi($this->app);
        $data = $this->input();

        // 与普通提交同一套前置校验（限频/蜜罐/验证码/密码/限一次）
        if (!$fill->preCheck($slug, $form, $settings, $data)) {
            return $this->fail($fill->lastError ?: '校验未通过', 4006);
        }
        // 字段校验与清洗（不落正式表，支付成功后才转正）
        [$fieldData, $values, $err] = $fill->validateAndClean($form, $data);
        if ($fieldData === null) {
            return $this->fail($err ?: '表单数据校验未通过', 422);
        }

        try {
            $order = OrderUtil::createPending($form, $values, $fieldData, [
                'channel_id' => $this->resolveChannelId($form['id'], $data),
                'ip'         => request()->ip(),
                'ua'         => (string)request()->header('user-agent'),
                'device'     => request()->isMobile() ? 'mobile' : 'pc',
                'pay_type'   => $payType,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::write('[pay] 下单失败: ' . $e->getMessage(), 'notice');
            return $this->fail('下单失败，请稍后重试', 500);
        }

        $out = [
            'payRequired' => true,
            'orderNo'     => $order['order_no'],
            'amount'      => $order['amount'],
            'payType'     => $payType,
            'expireMinutes' => OrderUtil::EXPIRE_MINUTES,
            'channelName' => Pay::CHANNEL_NAMES[$payType] ?? $payType,
        ];

        if ($payType === 'transfer') {
            $out['transfer'] = Pay::transferInfo();
            return $this->ok($out);
        }
        try {
            $created = Pay::createOnline(
                $payType,
                $order['order_no'],
                (float)$order['amount'],
                ($form['title'] ?: '表单提交') . ' - ' . ($out['channelName'])
            );
            $out['qr'] = $created['qr'];
            $out['redirect'] = $created['redirect'];
        } catch (\Throwable $e) {
            Log::write('[pay] 渠道下单失败 ' . $order['order_no'] . ': ' . $e->getMessage(), 'notice');
            return $this->fail('支付渠道暂时不可用，请换一种支付方式', 502);
        }
        return $this->ok($out);
    }

    /**
     * GET api/fill/order/:orderNo/status — 前端轮询
     * 订单号含随机段不可遍历；同时校验 UA/频率由框架层处理
     */
    public function status(string $orderNo)
    {
        $order = Db::name('form_orders')->where('order_no', $orderNo)->find();
        if (!$order) {
            return $this->fail('订单不存在', 404);
        }
        // 超时未支付：查询时顺带置取消（不依赖计划任务）
        if ((int)$order['status'] === 0 && $order['expire_at'] && $order['expire_at'] < date('Y-m-d H:i:s')) {
            Db::name('form_orders')->where('id', $order['id'])->update([
                'status' => 2, 'updated_at' => date('Y-m-d H:i:s'), 'remark' => '超时未支付自动取消',
            ]);
            if ($order['submission_id']) {
                Db::name('form_submissions')->where('id', $order['submission_id'])->update([
                    'deleted_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $order['status'] = 2;
        }
        return $this->ok([
            'orderNo' => $orderNo,
            'status'  => (int)$order['status'],
            'amount'  => (float)$order['amount'],
            'payType' => $order['pay_type'],
        ]);
    }

    /**
     * POST api/fill/order/:orderNo/voucher — 转账凭证上传（复用通用上传，仅存路径）
     */
    public function voucher(string $orderNo)
    {
        $order = Db::name('form_orders')->where('order_no', $orderNo)->find();
        if (!$order || (int)$order['status'] !== 0 || $order['pay_type'] !== 'transfer') {
            return $this->fail('订单状态不支持上传凭证', 422);
        }
        $file = request()->file('file');
        if (!$file || !$file->isValid()) {
            return $this->fail('请选择凭证图片', 422);
        }
        if ($file->getSize() > 10 * 1024 * 1024) {
            return $this->fail('凭证图片不能超过 10MB', 422);
        }
        $ext = strtolower(pathinfo($file->getOriginalName(), PATHINFO_EXTENSION));
        $realMime = (string)$file->getMime();
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)
            || !in_array($realMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return $this->fail('仅支持 jpg/png/webp 图片', 422);
        }
        $relDir = 'storage/uploads/' . date('Ym');
        $absDir = app()->getRootPath() . 'public/' . $relDir;
        if (!is_dir($absDir) && !@mkdir($absDir, 0755, true)) {
            return $this->fail('上传目录创建失败', 500);
        }
        $name = date('dHi') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $target = $absDir . '/' . $name;
        $tmpPath = $file->getPathname();
        if (!@move_uploaded_file($tmpPath, $target) && !@rename($tmpPath, $target) && !@copy($tmpPath, $target)) {
            return $this->fail('凭证保存失败', 500);
        }
        @chmod($target, 0644);
        $url = '/' . $relDir . '/' . $name;
        Db::name('form_orders')->where('id', $order['id'])->update([
            'voucher'    => $url,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->ok(['url' => $url]);
    }

    /**
     * POST api/fill/order/:orderNo/transfer-done — 访客确认已转账 → 待核销
     */
    public function transferDone(string $orderNo)
    {
        $order = Db::name('form_orders')->where('order_no', $orderNo)->find();
        if (!$order || (int)$order['status'] !== 0 || $order['pay_type'] !== 'transfer') {
            return $this->fail('订单状态不支持该操作', 422);
        }
        Db::name('form_orders')->where('id', $order['id'])->update([
            'status' => 4, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($order['submission_id']) {
            Db::name('form_submissions')->where('id', $order['submission_id'])->update([
                'pay_status' => 3,
            ]);
        }
        return $this->ok(['msg' => '已提交核对，确认到账后即完成']);
    }

    /**
     * POST api/pay/notify/wechat — 微信支付回调
     */
    public function notifyWechat()
    {
        try {
            Pay::boot();
            $result = \Yansongda\Pay\Pay::wechat()->callback();
            $arr = $result->all();
            // 解密后的资源报文
            $resource = $arr['resource'] ?? [];
            $orderNo = (string)($resource['out_trade_no'] ?? '');
            $tradeNo = (string)($resource['transaction_id'] ?? '');
            $tradeState = (string)($resource['trade_state'] ?? '');
            $amountTotal = (int)($resource['amount']['total'] ?? 0);
            $order = Db::name('form_orders')->where('order_no', $orderNo)->find();
            if (!$order) {
                Log::write('[pay] 微信回调订单不存在: ' . $orderNo, 'notice');
                return json(['code' => 'FAIL', 'message' => 'ORDER NOT FOUND'], 500);
            }
            // 金额比对（分）
            if ($amountTotal !== (int)round(((float)$order['amount']) * 100)) {
                Log::write('[pay] 微信回调金额不符 ' . $orderNo . ': ' . $amountTotal, 'notice');
                return json(['code' => 'FAIL', 'message' => 'AMOUNT MISMATCH'], 500);
            }
            if ($tradeState === 'SUCCESS') {
                OrderUtil::markPaid($orderNo, $tradeNo, json_encode($arr, JSON_UNESCAPED_UNICODE));
            }
            return json(['code' => 'SUCCESS', 'message' => 'OK']);
        } catch (\Throwable $e) {
            // 验签失败/解密失败都会走到这里
            Log::write('[pay] 微信回调处理异常: ' . $e->getMessage(), 'notice');
            return json(['code' => 'FAIL', 'message' => 'VERIFY FAILED'], 500);
        }
    }

    /**
     * POST api/pay/notify/alipay — 支付宝异步通知
     */
    public function notifyAlipay()
    {
        try {
            Pay::boot();
            $result = \Yansongda\Pay\Pay::alipay()->callback();
            $arr = $result->all();
            $orderNo = (string)($arr['out_trade_no'] ?? '');
            $tradeNo = (string)($arr['trade_no'] ?? '');
            $status = (string)($arr['trade_status'] ?? '');
            $amount = (float)($arr['total_amount'] ?? 0);
            $order = Db::name('form_orders')->where('order_no', $orderNo)->find();
            if (!$order) {
                return 'fail';
            }
            if (abs($amount - (float)$order['amount']) > 0.001) {
                Log::write('[pay] 支付宝回调金额不符 ' . $orderNo, 'notice');
                return 'fail';
            }
            if (in_array($status, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
                OrderUtil::markPaid($orderNo, $tradeNo, json_encode($arr, JSON_UNESCAPED_UNICODE), (string)($arr['buyer_logon_id'] ?? ''));
            }
            return 'success';
        } catch (\Throwable $e) {
            Log::write('[pay] 支付宝回调处理异常: ' . $e->getMessage(), 'notice');
            return 'fail';
        }
    }

    /**
     * GET /s/pay/return — 支付宝同步回跳落地页（自带轮询，展示最终支付结果）
     * 支付在独立窗口进行时，本页就是那个窗口的最终形态；原表单页同时也在轮询
     */
    public function payReturn()
    {
        $orderNo = htmlspecialchars((string)input('no', request()->param('out_trade_no', '')), ENT_QUOTES, 'UTF-8');
        $html = <<<'HTML'
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>支付结果</title>
<style>body{font-family:-apple-system,"PingFang SC",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5f8fc;color:#1f2d3d}
.card{background:#fff;border-radius:14px;padding:40px 34px;text-align:center;max-width:340px;box-shadow:0 10px 30px rgba(31,45,61,.08)}
.ico{font-size:44px}.t{font-size:18px;font-weight:700;margin:10px 0 6px}.s{color:#6b7a90;font-size:14px}</style></head>
<body><div class="card"><div class="ico" id="ico">⏳</div><div class="t" id="t">正在确认支付结果</div><div class="s" id="s">通常几秒内完成，请勿关闭页面</div></div>
<script>
var no = new URLSearchParams(location.search).get('no') || '';
var n = 0;
function poll() {
  if (!no || n++ > 30) { done('⏳', '确认超时', '如已扣款请勿重复支付，稍后自动到账'); return; }
  fetch('/api/fill/order/' + encodeURIComponent(no) + '/status').then(function (r) { return r.json(); }).then(function (d) {
    if (d.code !== 0) { done('❌', '查询失败', '请返回表单页重试'); return; }
    if (d.data.status === 1) { done('✅', '支付成功', '请关闭本页返回表单页查看结果'); }
    else if (d.data.status === 2) { done('❌', '订单已取消', '未完成支付'); }
    else if (d.data.status === 4) { done('🧾', '已收到凭证', '等待人工确认到账'); }
    else { setTimeout(poll, 2000); }
  }).catch(function () { setTimeout(poll, 2500); });
}
function done(i, t, s) { document.getElementById('ico').textContent = i; document.getElementById('t').textContent = t; document.getElementById('s').textContent = s; }
poll();
</script></body></html>
HTML;
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * 解析渠道归属（沿用渠道码归因）
     */
    private function resolveChannelId(int $formId, array $data): ?int
    {
        $code = trim((string)($data['__channel'] ?? ''));
        if ($code === '') {
            return null;
        }
        $ch = Db::name('form_channels')
            ->where('form_id', $formId)
            ->where('code', $code)
            ->whereNull('deleted_at')
            ->find();
        if (!$ch || (int)$ch['status'] !== 1) {
            return null;
        }
        if ($ch['online_from'] && $ch['online_from'] > date('Y-m-d H:i:s')) {
            return null;
        }
        if ($ch['online_until'] && $ch['online_until'] < date('Y-m-d H:i:s')) {
            return null;
        }
        return (int)$ch['id'];
    }
}
