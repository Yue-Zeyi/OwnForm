<?php
declare(strict_types=1);

namespace app\logic;

use think\facade\Db;

/**
 * 操作日志：全系统动作留痕（登录/表单/数据/用户/设置/提交）
 */
class OpLog
{
    /**
     * 写一条日志
     * @param string $module 模块：auth/form/data/upload/user/sys/fill
     * @param string $action 动作（如 登录/创建表单/删除数据）
     * @param string $detail 详情
     * @param int    $status 1成功 0失败
     */
    public static function write(
        string $module,
        string $action,
        string $detail = '',
        int $status = 1,
        int $userId = 0,
        string $username = '',
        array $payload = []
    ): void {
        try {
            $request = request();
            $userId = $userId ?: (int)(session('admin_id') ?: 0);
            $username = $username ?: (string)(session('admin_name') ?: '');
            Db::name('logs')->insert([
                'user_id'    => $userId,
                'username'   => self::clean($username ?: ($userId ? '#' . $userId : '访客')),
                'module'     => mb_substr(self::clean($module), 0, 30),
                'action'     => mb_substr(self::clean($action), 0, 50),
                'detail'     => mb_substr(self::clean($detail), 0, 1000),
                'request'    => isset($payload['request']) ? mb_substr(self::clean((string)$payload['request']), 0, 6000) : null,
                'response'   => isset($payload['response']) ? mb_substr(self::clean((string)$payload['response']), 0, 6000) : null,
                'duration'   => (int)($payload['duration'] ?? 0),
                'status'     => $status,
                'ip'         => $request ? \app\logic\Ip::get($request) : '',
                'user_agent' => mb_substr(self::clean((string)($request ? $request->header('user-agent', '') : '')), 0, 250),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // 日志写失败不影响主流程
        }
    }

    /**
     * 清除控制字符，防日志伪造与终端转义注入
     *
     * detail / request / user_agent 都含用户可控内容。若不剔除 \r\n，
     * 攻击者可在 detail 里注入换行伪造「登录成功」记录以掩盖痕迹；
     * 若不剔除 ANSI 转义序列，管理员用 cat / tail 查看导出日志时
     * 可能触发终端转义（改标题、清屏，极端情况下内容泄露到剪贴板）。
     *
     * 保留 \t 与 \n 以便阅读，其余 C0 控制字符与 DEL 一律删除。
     *
     * @access public
     */
    public static function clean(string $value): string
    {
        // 去除 ANSI / VT100 转义序列
        $cleaned = preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $value);
        // 去除除 \t \n 外的 C0 控制字符与 DEL。
        // 注意不能用 /u：字符类全为 ASCII，字节模式即可；
        // 加 /u 遇到非法 UTF-8（如 GBK 的 UA）会返回 null → 日志被整体清空，
        // 等于给攻击者留了抹日志的后门。
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string)$cleaned);
        if ($cleaned === null) {
            $cleaned = '';
        }
        // 裸 CR 归一为 LF，避免用 \r 覆盖行首伪造日志行
        return str_replace("\r", "\n", (string)$cleaned);
    }

    /**
     * 脱敏：密码/验证码/密钥类字段值替换为 ***
     */
    public static function sanitize(string $json): string
    {
        $keys = ['password', '__password', 'oldPassword', 'newPassword', 'confirm',
                 '__captcha_code', '__sms_code', '__captcha_token', 'ai_key',
                 'access_key_secret', 'secret_key', 'cos_secret_key', 'qiniu_secret_key', 'mail_pass',
                 'sms_access_key_secret', 'geetest_captcha_key', 'accessPassword'];
        foreach ($keys as $k) {
            $json = preg_replace(
                '/("' . preg_quote($k, '/') . '"\s*:\s*)"[^"]*"/i',
                '$1"***"',
                $json
            );
            // form 表单嵌套场景（无引号键名）
            $json = preg_replace(
                '/(^|,|\{)\s*(' . preg_quote($k, '/') . ')\s*=\s*[^&\r\n]+/i',
                '$1$2=***',
                $json
            );
        }
        return (string)$json;
    }
}
