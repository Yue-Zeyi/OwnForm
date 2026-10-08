<?php
declare(strict_types=1);

namespace app\logic;

/**
 * 邮件发送（原生 SMTP，零依赖）
 * 支持 SSL(465) / STARTTLS(25/587) / 明文，AUTH LOGIN，UTF-8 HTML 邮件
 * 配置存于 settings：mail_host / mail_port / mail_secure / mail_user / mail_pass / mail_from_name
 */
class Mail
{
    /** 发送一封 HTML 邮件，返回 ['ok'=>bool,'msg'=>string] */
    public static function send(string $to, string $subject, string $html): array
    {
        $host = Setting::get('mail_host');
        $port = (int)Setting::get('mail_port', '465');
        $secure = Setting::get('mail_secure', 'ssl');
        $user = Setting::get('mail_user');
        $pass = Setting::get('mail_pass');
        $fromName = Setting::get('mail_from_name', 'OwnForm') ?: 'OwnForm';

        if (!$host || !$user || !$pass) {
            return ['ok' => false, 'msg' => '邮件服务未配置完整（主机/账号/授权码）'];
        }
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'msg' => '收件邮箱格式不正确：' . $to];
        }

        // SMTP 主机由管理员配置，但连接建立后即成为内网探测通道：
        // 拒绝指向内网/回环/元数据地址的主机
        if (!self::isSafeHost($host)) {
            return ['ok' => false, 'msg' => 'SMTP 主机地址不合法：不能指向内网或回环地址'];
        }

        $timeout = 10;
        $transport = $secure === 'ssl' ? 'ssl://' : '';
        $sock = @stream_socket_client("{$transport}{$host}:{$port}", $errno, $errstr, $timeout);
        if (!$sock) {
            return ['ok' => false, 'msg' => "连接 SMTP 失败：{$errstr}({$errno})"];
        }
        stream_set_timeout($sock, $timeout);

        try {
            $smtp = new SmtpSession($sock);
            $smtp->expect([220]);
            $localhost = 'localhost';
            $smtp->cmd('EHLO ' . $localhost, [250]);

            if ($secure === 'tls') {
                $smtp->cmd('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new SmtpException('STARTTLS 加密协商失败');
                }
                $smtp->cmd('EHLO ' . $localhost, [250]);
            }

            $smtp->cmd('AUTH LOGIN', [334]);
            $smtp->cmd(base64_encode($user), [334]);
            $smtp->cmd(base64_encode($pass), [235]);

            $smtp->cmd('MAIL FROM:<' . $user . '>', [250]);
            $smtp->cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $smtp->cmd('DATA', [354]);

            $msg = self::buildMessage($user, $fromName, $to, $subject, $html);
            // 点填充：行首 . 需双写
            $msg = preg_replace('/^\./m', '..', $msg);
            fwrite($sock, $msg . "\r\n.\r\n");
            $smtp->expect([250]);
            $smtp->cmd('QUIT', [221]);
            fclose($sock);

            return ['ok' => true, 'msg' => '已发送至 ' . $to];
        } catch (\Throwable $e) {
            @fclose($sock);
            return ['ok' => false, 'msg' => '发送失败：' . $e->getMessage()];
        }
    }

    /** 新提交通知：按表单配置的邮箱列表发送（每封独立，失败互不影响） */
    public static function notifySubmit(array $form, array $data, int $submissionId): void
    {
        $emails = array_filter(array_map('trim', explode(',', (string)($form['notify_emails'] ?? ''))));
        if (!$emails) {
            return;
        }
        $title = (string)($form['title'] ?? '表单');
        $subject = "【OwnForm】收到新提交「{$title}」#{$submissionId}";

        $rows = '';
        $i = 0;
        foreach (array_slice($data, 0, 20, true) as $k => $v) {
            if (str_starts_with((string)$k, '__')) {
                continue;
            }
            if (is_array($v)) {
                $v = implode('、', array_map(fn($x) => is_scalar($x) ? (string)$x : '', $v));
            }
            $bg = $i++ % 2 ? '#f5f7fa' : '#fff';
            $rows .= '<tr><td style="padding:8px 12px;background:' . $bg . ';color:#606266;width:120px">'
                . htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8')
                . '</td><td style="padding:8px 12px;background:' . $bg . '">'
                . nl2br(htmlspecialchars(mb_substr((string)$v, 0, 500), ENT_QUOTES, 'UTF-8'))
                . '</td></tr>';
        }
        $html = '<div style="max-width:640px;margin:0 auto;font-family:Arial,PingFang SC,sans-serif">'
            . '<h2 style="font-size:18px;color:#303133">新提交通知</h2>'
            . '<p style="color:#909399;font-size:13px">表单「' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '」收到一条新提交（#' . $submissionId . '），内容如下：</p>'
            . '<table style="border-collapse:collapse;width:100%;font-size:13px;color:#303133;border:1px solid #ebeef5">' . $rows . '</table>'
            . '<p style="color:#c0c4cc;font-size:12px;margin-top:14px">本邮件由 OwnForm 表单系统自动发送</p></div>';

        foreach ($emails as $to) {
            self::send($to, $subject, $html);
        }
    }

    private static function buildMessage(string $from, string $fromName, string $to, string $subject, string $html): string
    {
        $enc = function (string $s): string {
            return '=?UTF-8?B?' . base64_encode($s) . '?=';
        };
        $eol = "\r\n";
        return 'From: ' . $enc($fromName) . ' <' . $from . '>' . $eol
            . 'To: <' . $to . '>' . $eol
            . 'Subject: ' . $enc($subject) . $eol
            . 'Date: ' . date('r') . $eol
            . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@ownform>' . $eol
            . 'MIME-Version: 1.0' . $eol
            . 'Content-Type: text/html; charset=UTF-8' . $eol
            . 'Content-Transfer-Encoding: base64' . $eol
            . $eol
            . chunk_split(base64_encode($html));
    }

    /**
     * SMTP 主机白名单校验（防 SSRF / 内网探测）
     *
     * @access private
     */
    private static function isSafeHost(string $host): bool
    {
        $host = trim($host, '[]');
        if ($host === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $host)) {
            return false;
        }
        if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $host)) {
            return !\app\logic\Ip::isPrivateAddress($host);
        }
        return !in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)
            && !str_ends_with(strtolower($host), '.local')
            && !str_ends_with(strtolower($host), '.internal');
    }
}

/** SMTP 会话简易封装 */
class SmtpSession
{
    private $sock;

    public function __construct($sock)
    {
        $this->sock = $sock;
    }

    public function expect(array $codes): string
    {
        $data = '';
        while (($line = fgets($this->sock, 1024)) !== false) {
            $data .= $line;
            // 多行响应：第四位为 '-' 时继续读
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int)substr($data, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new SmtpException('SMTP 响应异常：' . trim($data) ?: '(空响应)');
        }
        return $data;
    }

    public function cmd(string $cmd, array $expect): string
    {
        fwrite($this->sock, $cmd . "\r\n");
        return $this->expect($expect);
    }
}

class SmtpException extends \RuntimeException
{
}
