<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\logic\FieldUtil;
use think\facade\Cache;
use think\facade\Db;

/**
 * 公开填写接口
 */
class FillApi extends BaseController
{
    /**
     * 表单信息（不含敏感设置）
     */
    public function read(string $slug)
    {
        $form = self::loadActiveForm($slug);
        if (is_string($form)) {
            return $this->fail($form, 4004);
        }
        $decoded = json_decode((string)$form['settings_json'], true);
        $settings = FieldUtil::normalizeSettings(is_array($decoded) ? $decoded : []);

        // 客服配置：二维码只下发"是否有"，图片地址本身是公开资源可直出
        $service = is_array($settings['service'] ?? null) ? $settings['service'] : [];
        return $this->ok([
            'id'             => (int)$form['id'],
            'title'          => $form['title'],
            'description'    => (string)$form['description'],
            'fields'         => json_decode((string)$form['fields_json'], true) ?: [],
            'formOption'     => is_array($settings['formOption']) ? $settings['formOption'] : [],
            'submitText'     => $settings['submitText'],
            'theme'          => $settings['theme'],
            'themeStyle'     => $settings['themeStyle'] ?? 'plain',
            'needPassword'   => $settings['accessPassword'] !== '',
            'limitOnce'      => (bool)$settings['limitOnce'],
            'captcha'        => in_array($settings['captcha'] ?? 'none', ['image', 'sms', 'geetest'], true) ? $settings['captcha'] : 'none',
            'smsPhoneField'  => (string)($settings['smsPhoneField'] ?? ''),
            'geetestCaptchaId' => ($settings['captcha'] ?? '') === 'geetest' ? \app\logic\Setting::get('geetest_captcha_id') : '',
            'service'        => [
                'type'   => in_array($service['type'] ?? 'none', ['link', 'qrcode'], true) ? $service['type'] : 'none',
                'link'   => (string)($service['link'] ?? ''),
                'qrcode' => (string)($service['qrcode'] ?? ''),
            ],
            // 表单内关联的页面/链接入口
            'links'         => self::normalizeLinks($settings['links'] ?? []),
            // 提交成功后的跳转配置（page 类型下发 slug，由前端拼成 /p/{slug}）
            'afterSubmit'   => [
                'type'   => in_array($settings['afterSubmit']['type'] ?? 'none', ['page', 'url'], true)
                                   ? $settings['afterSubmit']['type'] : 'none',
                'target' => (string)($settings['afterSubmit']['target'] ?? ''),
                'delay'  => (int)($settings['afterSubmit']['delay'] ?? 3),
            ],
        ]);
    }

    /**
     * 归一化关联链接
     *
     * page 类型把 slug 转成可直接使用的完整地址，前端无需再拼接；
     * url 类型因已经过 http(s) 白名单校验，原样下发。
     *
     * @access private
     */
    private static function normalizeLinks(mixed $links): array
    {
        if (!is_array($links)) {
            return [];
        }
        $out = [];
        foreach (array_slice($links, 0, 10) as $l) {
            if (!is_array($l) || empty($l['target'])) {
                continue;
            }
            $type = ($l['type'] ?? '') === 'page' ? 'page' : 'url';
            $out[] = [
                'type'  => $type,
                'title' => (string)($l['title'] ?? '查看详情'),
                'url'   => $type === 'page'
                    ? '/p/' . $l['target']
                    : (string)$l['target'],
            ];
        }
        return $out;
    }

    /**
     * 图像验证码
     */
    public function captcha()
    {
        $res = \app\logic\Captcha::make();
        if (isset($res['error'])) {
            return $this->fail($res['error'], 429);
        }
        return $this->ok($res);
    }

    /**
     * 发送短信验证码（表单开启短信验证时）
     */
    public function smsCode(string $slug)
    {
        $form = self::loadActiveForm($slug);
        if (is_string($form)) {
            return $this->fail($form, 4004);
        }
        $settings = json_decode((string)$form['settings_json'], true) ?: [];
        if (($settings['captcha'] ?? '') !== 'sms') {
            return $this->fail('该表单未开启短信验证');
        }
        // 按 IP 限频：每 IP 每小时最多 20 条，防止换号烧短信费
        $ipKey = 'sms_ip_' . md5($this->clientIp());
        $sent = (int)Cache::store('file')->get($ipKey, 0);
        if ($sent >= 20) {
            return $this->fail('发送过于频繁，请稍后再试');
        }

        $data  = $this->input();
        $phone = trim((string)($data['phone'] ?? ''));
        try {
            $res = \app\logic\Sms::sendCode($phone);
        } catch (\Throwable $e) {
            // 短信服务商网络异常等：返回友好文案而非通用 500
            \think\facade\Log::write('[sms] 发送异常: ' . $e->getMessage(), 'notice');
            return $this->fail('短信服务暂时不可用，请稍后再试');
        }
        if (!$res['ok']) {
            return $this->fail($res['msg']);
        }
        Cache::store('file')->set($ipKey, $sent + 1, 3600);
        return $this->ok(['wait' => $res['wait']], $res['msg']);
    }

    /**
     * 提交前校验（按表单配置的验证方式），通过返回 true，失败返回错误文案
     */
    private static function verifyCaptcha(array $settings, array $raw): ?string
    {
        $type = in_array($settings['captcha'] ?? 'none', ['image', 'sms', 'geetest'], true) ? $settings['captcha'] : 'none';
        if ($type === 'none') {
            return null;
        }
        if ($type === 'image') {
            $token = (string)($raw['__captcha_token'] ?? '');
            $ok = \app\logic\Captcha::check($token, (string)($raw['__captcha_code'] ?? ''));
            if (!$ok) {
                \app\logic\Captcha::recordFailure($token);
                return '验证码不正确或已过期，请刷新后重试';
            }
            return null;
        }
        if ($type === 'sms') {
            $phoneField = (string)($settings['smsPhoneField'] ?? '');
            if ($phoneField === '') {
                return '表单未配置接收手机号的字段';
            }
            $form = isset($raw['form']) && is_array($raw['form']) ? $raw['form'] : $raw;
            $phone = trim((string)($form[$phoneField] ?? ''));
            $ok = \app\logic\Sms::checkCode($phone, (string)($raw['__sms_code'] ?? ''));
            return $ok ? null : '短信验证码不正确或已过期';
        }
        // geetest
        $ok = \app\logic\Sms::geetestCheck((array)($raw['__geetest'] ?? []));
        return $ok ? null : '人机验证未通过，请重新验证';
    }

    /**
     * 提交
     */
    public function submit(string $slug)
    {
        $form = self::loadActiveForm($slug, true);
        if (is_string($form)) {
            return $this->fail($form, 4004);
        }
        $formId   = (int)$form['id'];
        // 必须始终经过 normalizeSettings：仅在 json_decode 失败时兜底是不够的，
        // 历史数据的 settings_json 可能只存了部分键，直接取值会触发 undefined key
        $decoded = json_decode((string)$form['settings_json'], true);
        $settings = FieldUtil::normalizeSettings(is_array($decoded) ? $decoded : []);
        $ip       = $this->clientIp();
        $device   = $this->request->isMobile() ? 'mobile' : 'pc';

        // 提交限频：同 IP 5 秒内仅 1 次，1 小时最多 30 次
        // 计数在任何校验之前就推进——否则失败请求（验证码错误、密码错误）
        // 完全不消耗配额，攻击者可用它们无限次试探
        $rlKey  = 'submit_rl_' . md5($ip . '|' . $slug);
        $last   = (int)Cache::store('file')->get($rlKey . '_t', 0);
        $hourly = (int)Cache::store('file')->get($rlKey . '_h', 0);
        if (time() - $last < 5) {
            return $this->fail('提交太快了，请稍等几秒');
        }
        if ($hourly >= 30) {
            return $this->fail('该网络提交次数已达上限，请稍后再试');
        }
        Cache::store('file')->set($rlKey . '_t', time(), 60);
        Cache::store('file')->set($rlKey . '_h', $hourly + 1, 3600);

        $data = $this->input();
        // 蜜罐：隐藏字段有值视为机器人，静默丢弃
        if (trim((string)($data['__hp'] ?? '')) !== '') {
            return $this->ok([], '提交成功');
        }

        // 提交验证（图像验证码 / 短信验证码 / 极验，按表单配置）
        $captchaErr = self::verifyCaptcha($settings, $data);
        if ($captchaErr !== null) {
            \app\logic\OpLog::write('fill', '提交被拦截', '表单#' . $formId . ' ' . $captchaErr, 0, 0, '访客');
            return $this->fail($captchaErr, 4006);
        }

        // 访问密码
        if ($settings['accessPassword'] !== '') {
            $pwd = (string)($data['__password'] ?? '');
            if (!hash_equals($settings['accessPassword'], $pwd)) {
                return $this->fail('访问密码不正确');
            }
        }

        // 限一次
        if ($settings['limitOnce']) {
            $cookieKey = 'of_submitted_' . md5($slug);
            $exists = Db::name('form_submissions')
                ->where('form_id', $formId)
                ->where('ip', $ip)
                ->where('device', $device)
                ->count();
            if ($exists > 0 || $this->request->cookie($cookieKey)) {
                return $this->fail('您已提交过，请勿重复提交', 4005);
            }
        }

        // 字段校验与清洗
        $rule    = json_decode((string)$form['fields_json'], true) ?: [];
        $fields  = FieldUtil::extractFields($rule);
        $values  = FieldUtil::unwrapValues($data);
        $missing = [];
        foreach ($fields as $f) {
            if (!empty($f['required'])) {
                $v = $values[$f['field']] ?? '';
                if ($v === '' || $v === null || $v === []) {
                    $missing[] = $f['title'] ?: $f['field'];
                }
            }
        }
        if ($missing) {
            return $this->fail('请填写必填项：' . implode('、', $missing));
        }
        $clean = FieldUtil::sanitizeData($values, $fields);
        if (!$clean && $fields) {
            return $this->fail('提交内容为空');
        }

        // 上传字段：归一化为有效 URL 数组（兼容 limit=1 时前端提交的单字符串），
        // 剔除无效地址（前端上传失败时会残留 blob: 本地预览地址，该地址只在
        // 访客自己的浏览器里有效，入库后后台无法预览）；剔除后必填上传字段
        // 为空的，明确要求重新上传
        foreach ($fields as $f) {
            if (($f['type'] ?? '') !== 'upload' || !isset($clean[$f['field']])) {
                continue;
            }
            $v = $clean[$f['field']];
            $arr = is_array($v) ? $v : (is_string($v) && $v !== '' ? [$v] : []);
            $valid = array_values(array_filter($arr, function ($u) {
                return is_string($u) && $u !== ''
                    && (str_starts_with($u, '/storage/') || str_starts_with($u, 'http://') || str_starts_with($u, 'https://'));
            }));
            if ($valid !== $v) {
                $clean[$f['field']] = $valid;
                if (!empty($f['required']) && !$valid) {
                    return $this->fail('「' . ($f['title'] ?: $f['field']) . '」文件未上传成功，请重新上传后提交');
                }
            }
        }

        // 审核模式：入库为待审核
        $needReview = !empty($settings['needReview']);

        // 渠道归因：无效渠道码降级为直接访问（NULL），不拦截提交
        $channelCode = trim((string)($data['__channel'] ?? $this->request->param('ch', '')));
        $channel = $channelCode !== '' ? \app\logic\ChannelUtil::resolveActive($channelCode, $formId) : null;

        $now = $this->now();
        $submissionId = (int)Db::name('form_submissions')->insertGetId([
            'form_id'     => $formId,
            'channel_id'  => $channel ? (int)$channel['id'] : null,
            'data_json'   => json_encode($clean, JSON_UNESCAPED_UNICODE),
            'ip'          => $ip,
            'user_agent'  => mb_substr((string)$this->request->header('user-agent', ''), 0, 500),
            'device'      => $device,
            'status'      => $needReview ? 0 : 1,
            'created_at'  => $now,
        ]);
        Db::name('forms')->where('id', $formId)->inc('submit_count')->update();
        if ($channel) {
            Db::name('form_channels')->where('id', $channel['id'])->inc('submit_count')->update();
        }

        if ($settings['limitOnce']) {
            cookie('of_submitted_' . md5($slug), '1', 86400 * 365);
        }

        // 响应后异步发送 Webhook / 邮件通知（不阻塞提交）
        // 总开关 notify_on_submit：系统设置里关闭后不再推送（测试发送不受限）
        $notifyForm = ['id' => $formId, 'title' => $form['title'], 'notify_emails' => $settings['notify_emails'] ?? ''];
        $notifyOn = \app\logic\Setting::get('notify_on_submit', '0') === '1';
        $fireNotify = function () use ($notifyForm, $clean, $submissionId, $notifyOn) {
            if (!$notifyOn) {
                return;
            }
            \app\logic\Notify::fireFormSubmit($notifyForm, $clean, $submissionId);
            \app\logic\Mail::notifySubmit($notifyForm, $clean, $submissionId);
        };
        if (function_exists('fastcgi_finish_request')) {
            // FPM 下先结束响应再跑通知，访客不必等 Webhook/SMTP 超时
            register_shutdown_function(function () use ($fireNotify) {
                try {
                    fastcgi_finish_request();
                } catch (\Throwable $e) {
                    // 非 FPM-SAPI 下不可用则忽略，通知随请求结束照常执行
                }
                $fireNotify();
            });
        } else {
            $fireNotify();
        }

        \app\logic\OpLog::write('fill', '表单提交', '表单#' . $formId . '《' . $form['title'] . '》提交#' . $submissionId . ($needReview ? '（待审核）' : ''), 1, 0, '访客');

        $msg = $settings['successText'] ?: '提交成功';
        if ($needReview) {
            $msg .= '（您的提交已进入待审核状态）';
        }
        return $this->ok([], $msg);
    }

    /**
     * 载入处于可填写状态的表单；返回 string 时为不可填原因
     */
    public static function loadActiveForm(string $slug, bool $withLimits = false)
    {
        $form = Db::name('forms')->where('slug', $slug)->whereNull('deleted_at')->find();
        if (!$form || (int)$form['status'] !== 1) {
            return '表单不存在或已停止收集';
        }
        $decoded = json_decode((string)$form['settings_json'], true);
        $settings = FieldUtil::normalizeSettings(is_array($decoded) ? $decoded : []);
        if (!empty($settings['endTime']) && strtotime((string)$settings['endTime']) < time()) {
            return '表单已截止，感谢您的关注';
        }
        if ($withLimits && !empty($settings['maxCount']) && (int)$settings['maxCount'] > 0
            && (int)$form['submit_count'] >= (int)$settings['maxCount']) {
            return '表单已达收集上限，感谢您的关注';
        }
        return $form;
    }

    /**
     * 剥离 form-create 提交体的包装（form/f 混合）
     */
}
