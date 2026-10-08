<?php
declare(strict_types=1);

namespace app\logic;

/**
 * 页面内容消毒
 *
 * 为什么必须在后端做：自定义页面与后台同域，共享同一个会话 Cookie
 * （config/cookie.php 的 path 为 /）。若允许成员角色在页面里注入脚本，
 * 就能窃取在同一域名下访问后台的管理员会话，形成 member → admin 的提权路径。
 * 因此消毒只放在后端做，入库前与输出前各一次；前端预览的消毒仅为第二层。
 *
 * 消毒策略（三步）：
 *   1. strip_tags 白名单：不在白名单的标签整体移除（含其内容）
 *   2. 逐标签剔除 on* 事件属性、style 中的 url()/expression()
 *   3. 全局清除 javascript: / vbscript: / data: 等危险协议
 */
class HtmlSanitizer
{
    /** 允许的标签白名单（排版与媒体，不含 script/iframe/style/form 等可执行或可嵌入文档的标签） */
    private const ALLOWED_TAGS = [
        // 结构
        'p', 'div', 'span', 'br', 'hr',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'pre', 'code',
        // 列表
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        // 表格
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        'caption', 'colgroup', 'col',
        // 行内
        'strong', 'b', 'em', 'i', 'u', 's', 'del', 'ins', 'mark', 'small', 'sub', 'sup',
        'a',
        // 图片
        'img',
        // 其他
        'figure', 'figcaption', 'section', 'article', 'aside', 'header', 'footer', 'main',
    ];

    /** 允许的属性白名单（不含任何 on* 事件） */
    private const ALLOWED_ATTRS = [
        'href', 'src', 'alt', 'title', 'width', 'height',
        'colspan', 'rowspan', 'align', 'valign',
        'class', 'id', 'target', 'rel',
    ];

    /**
     * 消毒 HTML
     *
     * @access public
     */
    public static function clean(string $html): string
    {
        if ($html === '') {
            return '';
        }
        // 预先截断超长内容：下面的属性解析正则在超长畸形输入上会触发
        // PCRE 回溯上限，preg_replace 返回 null 被 (string) 强转成空串，
        // 已发布页面会静默变白屏且无从排查。截断远好于静默清空。
        if (strlen($html) > 600000) {
            $html = substr($html, 0, 600000);
        }

        // 1. 先把注释、CDATA、style/script 整块内容抹掉：
        //    strip_tags 只处理标签，注释里的内容会被原样保留并可能被后续步骤漏过
        $html = (string)preg_replace('/<!--.*?-->/s', '', $html);
        $html = (string)preg_replace('/<!\[CDATA\[.*?\]\]>/s', '', $html);
        $html = (string)preg_replace('#<\s*(script|style|noscript|template|svg|math|iframe|object|embed|applet|form|input|button|select|textarea|base|link|meta)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
        // 未闭合的自闭合危险标签（<script src=x> 后无闭合标签）
        $html = (string)preg_replace('#<\s*(script|style|iframe|object|embed|svg|math|form|base|link|meta)\b[^>]*>#i', '', $html);

        // 2. 标签白名单
        $html = strip_tags($html, '<' . implode('><', self::ALLOWED_TAGS) . '>');

        // 3. 逐标签清洗属性
        $replaced = preg_replace_callback('/<([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>/', function ($m) {
            $tag   = strtolower($m[1]);
            $attrs = self::cleanAttrs($tag, $m[2]);
            return '<' . $tag . ($attrs !== '' ? ' ' . $attrs : '') . '>';
        }, $html);
        if ($replaced === null) {
            // 命中 PCRE 回溯/栈上限：宁可让保存失败，也不能静默产出空页面
            throw new \RuntimeException('页面内容过于复杂，无法完成安全过滤，请简化内容后重试');
        }
        $html = (string)$replaced;

        // 4. 收尾：清除危险协议与残留的事件属性（覆盖畸形标签如 <img/src=x onerror=y>）
        $html = self::stripDangerousProtocol($html);
        $html = (string)preg_replace('/\son[a-zA-Z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $html);

        return $html;
    }

    /**
     * 清洗单个标签的属性串
     */
    private static function cleanAttrs(string $tag, string $attrStr): string
    {
        $out = [];
        // 支持 a="1" b='2' c=3 三种写法
        if (!preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>`]+)/', $attrStr, $m, PREG_SET_ORDER)) {
            return '';
        }
        foreach ($m as $one) {
            $name  = strtolower($one[1]);
            $value = trim($one[2], "\"'");

            // 事件属性一律拒绝（纵深防御：上面的正则已删过一次，这里再挡一层）
            if (str_starts_with($name, 'on')) {
                continue;
            }
            // style 能承载 url(javascript:...) 与 expression()，整体拒绝
            if ($name === 'style') {
                continue;
            }
            if (!in_array($name, self::ALLOWED_ATTRS, true)) {
                continue;
            }

            if ($name === 'href' || $name === 'src') {
                if (!self::isSafeUrl($value, $tag)) {
                    continue;
                }
                $out[] = $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
                continue;
            }
            if ($name === 'target') {
                // 只允许安全的窗口打开方式
                $v = strtolower($value);
                if (!in_array($v, ['_blank', '_self', '_parent', '_top'], true)) {
                    continue;
                }
                // _blank 必须补 rel，否则新窗口可通过 window.opener 反向操纵原页
                $out[] = 'target="' . $v . '"';
                $out[] = 'rel="noopener noreferrer"';
                continue;
            }
            if ($name === 'id') {
                // id 可能与宿主页面的全局变量同名（DOM clobbering），
                // 页面内容与后台同域时尤其危险，这里直接不放行
                continue;
            }

            $out[] = $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
        }

        // a 标签默认去掉 target 时补 rel，避免 tabnabbing
        if ($tag === 'a' && !in_array('rel="noopener noreferrer"', $out, true)) {
            $hasTarget = false;
            foreach ($out as $o) {
                if (str_starts_with($o, 'target=')) {
                    $hasTarget = true;
                    break;
                }
            }
            if ($hasTarget) {
                $out[] = 'rel="noopener noreferrer"';
            }
        }

        return implode(' ', $out);
    }

    /**
     * URL 是否安全
     *
     * @access private
     */
    private static function isSafeUrl(string $url, string $tag): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
        if ($url === '') {
            return false;
        }

        // 提取并检查协议部分。攻击手法：
        //   javascript:alert(1)
        //   JaVaScRiPt:alert(1)
        //   &#106;avascript:alert(1)     （HTML 实体）
        //   java\tscript:alert(1)        （制表符分隔）
        //   javascript&colon;alert(1)
        $probe = strtolower(preg_replace('/[\s\x00-\x20]/', '', $url) ?? '');
        $probe = str_replace(['&colon;', '&#58;', '&#x3a;'], ':', $probe);

        foreach (['javascript:', 'vbscript:', 'data:', 'file:', 'about:'] as $bad) {
            if (str_starts_with($probe, $bad)) {
                return false;
            }
        }

        // 相对地址与 http(s) 视为安全；mailto 只对 a 标签开放
        if (str_starts_with($probe, 'mailto:')) {
            return $tag === 'a';
        }
        if (str_starts_with($probe, 'http:') || str_starts_with($probe, 'https:')) {
            return true;
        }
        if (str_starts_with($probe, '//') || str_starts_with($probe, '/') || str_starts_with($probe, '#')) {
            return true;
        }

        // 无协议的相对路径（images/a.png、a.html）
        return !str_contains($probe, ':');
    }

    /**
     * 清除危险协议（覆盖属性拼接阶段的遗漏）
     */
    private static function stripDangerousProtocol(string $html): string
    {
        // href/src 属性值中的 javascript: 等
        $html = (string)preg_replace_callback(
            '/\b(href|src)\s*=\s*"([^"]*)"/i',
            function ($m) {
                return self::isSafeUrl($m[2], 'a')
                    ? $m[1] . '="' . $m[2] . '"'
                    : $m[1] . '=""';
            },
            $html
        );
        // 无引号属性值（正则里的单引号与反引号用 \x27 \x60 转义）
        $html = (string)preg_replace_callback(
            '/\b(href|src)\s*=\s*([^\s>"\x27\x60]+)/i',
            function ($m) {
                return self::isSafeUrl($m[2], 'a')
                    ? $m[1] . '="' . $m[2] . '"'
                    : $m[1] . '=""';
            },
            $html
        );
        return $html;
    }

    /**
     * 极简 Markdown 渲染（标题/列表/代码/链接/强调/分隔线）
     *
     * 只渲染有限的语法子集，不追求完整 CommonMark：
     * 访客端无需引入 marked 库，避免 vendor 体积膨胀与版本漂移。
     * 渲染结果仍会经过 clean() 消毒。
     *
     * @access public
     */
    public static function markdown(string $md): string
    {
        if ($md === '') {
            return '';
        }

        // 先抽出代码块，避免块内文本被后续规则误伤
        $blocks = [];
        $md = (string)preg_replace_callback('/```(\w*)\n(.*?)```/s', function ($m) use (&$blocks) {
            $lang = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
            $code = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
            $key  = "\x00CODE" . count($blocks) . "\x00";
            $blocks[$key] = '<pre><code' . ($lang !== '' ? ' class="lang-' . $lang . '"' : '') . '>' . $code . '</code></pre>';
            return $key;
        }, $md);

        // 整行 HTML 转义（Markdown 正文按纯文本处理，HTML 由 content_type=3 单独承载）
        $lines = explode("\n", $md);
        $out   = [];
        $inList = null;

        foreach ($lines as $line) {
            $trim = trim($line);

            // 列表收尾
            if ($inList !== null && !preg_match('/^\s*([-*+]|\d+\.)\s+/', $line)) {
                $out[] = $inList === 'ul' ? '</ul>' : '</ol>';
                $inList = null;
            }

            if (trim($trim) === '') {
                if ($inList === null) {
                    $out[] = '';
                }
                continue;
            }

            // 占位符（表单/页面/链接）原样保留为占位 DOM，后面由前端填充
            if (preg_match('/^\{\{(form|page|url):.*\}\}$/', $trim)) {
                $out[] = $trim;
                continue;
            }

            // 代码块占位
            if (str_starts_with($trim, "\x00CODE")) {
                $out[] = '<div class="md-block">' . $trim . '</div>';
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*)$/', $trim, $m)) {
                $level = strlen($m[1]);
                $out[] = "<h{$level}>" . self::inline($m[2]) . "</h{$level}>";
                continue;
            }
            if (preg_match('/^>\s?(.*)$/', $trim, $m)) {
                $out[] = '<blockquote>' . self::inline($m[1]) . '</blockquote>';
                continue;
            }
            if (preg_match('/^([-*_])\1{2,}$/', $trim)) {
                $out[] = '<hr>';
                continue;
            }
            if (preg_match('/^[-*+]\s+(.*)$/', $trim, $m)) {
                if ($inList !== 'ul') {
                    if ($inList !== null) {
                        $out[] = '</ul>';
                    }
                    $out[] = '<ul>';
                    $inList = 'ul';
                }
                $out[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }
            if (preg_match('/^\d+\.\s+(.*)$/', $trim, $m)) {
                if ($inList !== 'ol') {
                    if ($inList !== null) {
                        $out[] = '</ol>';
                    }
                    $out[] = '<ol>';
                    $inList = 'ol';
                }
                $out[] = '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }

            $out[] = '<p>' . self::inline($trim) . '</p>';
        }

        if ($inList !== null) {
            $out[] = $inList === 'ul' ? '</ul>' : '</ol>';
        }

        $html = implode("\n", $out);

        // 回填代码块
        if ($blocks) {
            $html = strtr($html, $blocks);
        }

        return $html;
    }

    /**
     * 行内语法：代码、链接、粗体、斜体
     */
    private static function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');

        // 行内代码优先占位，避免内部内容被后续规则改写
        $codes = [];
        $text = (string)preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
            $key = "\x00ICODE" . count($codes) . "\x00";
            $codes[$key] = '<code>' . $m[1] . '</code>';
            return $key;
        }, $text);

        // [文字](链接)
        $text = (string)preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)\)/',
            function ($m) {
                return self::isSafeUrl(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'), 'a')
                    ? '<a href="' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>'
                    : $m[1];
            },
            $text
        );

        // ![alt](src)
        $text = (string)preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)\)/',
            function ($m) {
                return self::isSafeUrl(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'), 'img')
                    ? '<img src="' . htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8') . '" alt="'
                      . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '">'
                    : '';
            },
            $text
        );

        $text = (string)preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = (string)preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $text);

        if ($codes) {
            $text = strtr($text, $codes);
        }

        return $text;
    }
}