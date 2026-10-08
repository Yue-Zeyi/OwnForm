<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\logic\Setting;
use think\Response;

/**
 * 设计器 AI 助理代理：
 * 接收 form-create 设计器的私有请求格式，翻译为 OpenAI 兼容
 * chat/completions 调用（DeepSeek / GLM / 通义等），SSE 流式透传。
 */
class AiChat extends BaseController
{
    /** 会话中保留的最大历史条数 */
    private const MAX_HISTORY = 10;

    public function chat()
    {
        // 需要后台登录（成员也可用设计器）
        if (!session('admin_id')) {
            return $this->fail('请先登录', 401);
        }
        $baseUrl = trim((string)Setting::get('ai_base_url'));
        $model   = trim((string)Setting::get('ai_model'));
        $key     = trim((string)Setting::get('ai_key'));
        if (!Setting::get('ai_key') || !$baseUrl || !$model) {
            return $this->fail('AI 助理未配置，请在系统设置-AI 助理中完成配置', 4003);
        }

        // 携带 Authorization 头的请求绝不能指向内网：
        // 否则管理员（或被劫持的管理员会话）可把 AI 密钥导向自己控制的服务器，
        // 或借本接口探测内网服务与云元数据
        if (!self::isSafeUpstream($baseUrl)) {
            return $this->fail('AI 接口地址配置不合法：仅支持 https 公网地址', 4003);
        }

        $body    = $this->input();
        $form    = is_array($body['form'] ?? null) ? $body['form'] : [];
        $rule    = isset($form['rule']) ? json_encode($form['rule'], JSON_UNESCAPED_UNICODE) : '[]';
        $option  = isset($form['option']) ? json_encode($form['option'], JSON_UNESCAPED_UNICODE) : '{}';
        $history = is_array($body['messages'] ?? null) ? $body['messages'] : [];

        $messages = [['role' => 'system', 'content' => $this->systemPrompt($rule, $option)]];
        foreach (array_slice($history, -self::MAX_HISTORY) as $m) {
            $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string)($m['content'] ?? ''));
            if ($content !== '') {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'stream'      => true,
            'temperature' => min(2, max(0, (float)Setting::get('ai_temperature', '0.7'))),
        ];

        return $this->streamChat(rtrim($baseUrl, '/') . '/chat/completions', $key, $payload);
    }

    /**
     * form-create 表单规则系统提示词
     */
    private function systemPrompt(string $rule, string $option): string
    {
        return <<<PROMPT
你是 OwnForm 表单设计器的 AI 助理，精通 form-create（Vue3 + Element Plus）表单规则 JSON。用户会用自然语言描述想要的表单或修改，你输出修改后的完整规则。

【当前表单规则 JSON】
{$rule}

【当前表单选项 JSON】
{$option}

【规则格式】
规则是对象数组，每个元素描述一个字段：
- type：组件类型；field：字段名（英文、同表单内唯一）；title：字段标签
- props：组件属性对象；options：选项数组 [{label, value}]；effect: {required: true} 表示必填
- validate：校验数组，如 [{pattern: "^1[3-9]\\\\d{9}\$", message: "手机号格式不正确"}]
- 支持的 type：input、textarea（多行文本）、inputNumber、radio、checkbox、select、datePicker、timePicker、rate、slider、switch、upload、cascader、colorPicker、tree、row/col（布局，子字段放 children）
- 常用 props：input 的 placeholder；inputNumber 的 {min, max}；datePicker 的 {type: "date", valueFormat: "YYYY-MM-DD"}；textarea 的 {rows: 3}；upload 的 {action: "/api/upload", listType: "picture-card", limit: 3, accept: "image/*"}

【输出格式（严格遵守）】
1. 先用 1-3 句话简要说明本次改动
2. 然后输出一个 ```fcRule 代码块，内容为修改后的【完整】规则 JSON 数组（用户点击导入按钮后整体应用）
3. 未被用户提及的字段必须原样保留，不得删除或改名
4. 除说明文字和 fcRule 代码块外，不要输出任何其他内容
PROMPT;
    }

    /**
     * 上游地址安全校验：仅允许 https 公网地址
     *
     * 与 SysApi::isSafeUrl 同源策略，此处独立实现以便 AiChat 免去依赖控制器。
     */
    public static function isSafeUpstream(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }
        $host = trim((string)parse_url($url, PHP_URL_HOST), '[]');
        if ($host === '') {
            return false;
        }
        // 主机名为 IP 字面量时直接判定网段；域名需在此处拒绝明显的内嵌写法
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !\app\logic\Ip::isPrivateAddress($host);
        }
        // 拒绝 localhost 与常见的内网主机名
        return !in_array(strtolower($host), ['localhost', 'localhost.localdomain', 'ip6-localhost'], true)
            && !str_ends_with(strtolower($host), '.local')
            && !str_ends_with(strtolower($host), '.internal');
    }

    /**
     * 流式调用 OpenAI 兼容接口并透传 SSE
     */
    private function streamChat(string $url, string $key, array $payload): Response
    {
        @set_time_limit(180);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
            'Accept: text/event-stream',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION  => function ($ch, $data) {
                $len = strlen($data);
                echo $data;
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
                return $len;
            },
        ]);
        curl_exec($ch);
        if (curl_errno($ch)) {
            $msg = json_encode([
                'error' => ['message' => 'AI 接口请求失败：' . curl_error($ch)],
            ]);
            echo "data: {$msg}\n\n";
            flush();
        }
        curl_close($ch);
        exit;
    }
}
