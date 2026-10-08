<?php
declare(strict_types=1);

namespace app\logic;

use think\facade\Db;

/**
 * 页面工具：设置归一化、slug 生成、占位符解析
 */
class PageUtil
{
    /** 分享码字符集：去掉易混淆的 i/l/o/0/1 */
    private const SLUG_CHARS = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** 分享码长度 */
    private const SLUG_LEN = 10;

    /**
     * 填充默认页面设置
     *
     * @access public
     */
    public static function normalizeSettings(array $settings): array
    {
        $defaults = [
            'theme'      => '#409eff',
            // 访客端展示模板：clean 纯净平铺 / card 卡片 / elegant 优雅排版
            'template'   => 'clean',
            'cover'      => '',
            'footerText' => '',
            'allowIndex' => true,
            'accessPassword' => '',
            'seo'        => ['title' => '', 'description' => '', 'keywords' => ''],
        ];

        $settings = array_intersect_key($settings, $defaults) + $defaults;

        // 主题色：只接受 #RGB / #RRGGBB，避免把任意字符串塞进 CSS 变量
        $theme = (string)$settings['theme'];
        if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $theme)) {
            $theme = '#409eff';
        }
        $settings['theme'] = $theme;

        $settings['template'] = in_array($settings['template'], ['clean', 'card', 'elegant'], true)
            ? $settings['template'] : 'clean';

        $settings['cover'] = (string)$settings['cover'];
        $settings['footerText'] = mb_substr((string)$settings['footerText'], 0, 200);
        $settings['allowIndex'] = (bool)$settings['allowIndex'];
        $settings['accessPassword'] = (string)$settings['accessPassword'];

        $seo = is_array($settings['seo']) ? $settings['seo'] : [];
        $settings['seo'] = [
            'title'       => mb_substr(trim((string)($seo['title'] ?? '')), 0, 100),
            'description' => mb_substr(trim((string)($seo['description'] ?? '')), 0, 200),
            'keywords'    => mb_substr(trim((string)($seo['keywords'] ?? '')), 0, 200),
        ];

        return $settings;
    }

    /**
     * 生成唯一分享码
     *
     * @access public
     */
    public static function genSlug(): string
    {
        $max = strlen(self::SLUG_CHARS) - 1;
        do {
            $slug = '';
            for ($i = 0; $i < self::SLUG_LEN; $i++) {
                $slug .= self::SLUG_CHARS[random_int(0, $max)];
            }
        } while (Db::name('pages')->where('slug', $slug)->count() > 0);
        return $slug;
    }

    /**
     * 校验分享码格式
     *
     * @access public
     */
    public static function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z2-9]{10}$/', $slug) === 1;
    }

    /**
     * 渲染内容：Markdown → HTML（或直接用 HTML），统一消毒
     *
     * @access public
     */
    public static function renderContent(string $source, int $contentType): string
    {
        $source = (string)$source;
        if ($source === '') {
            return '';
        }
        $html = $contentType === self::CONTENT_MARKDOWN
            ? HtmlSanitizer::markdown($source)
            : $source;
        return HtmlSanitizer::clean($html);
    }

    /** 内容类型：富文本（HTML） */
    public const CONTENT_HTML = 1;
    /** 内容类型：Markdown */
    public const CONTENT_MARKDOWN = 2;

    /**
     * 提取内容中的嵌入占位符
     *
     * 返回顺序与内容中出现顺序一致，前端据此把渲染好的组件插回正文流。
     *
     * 支持：
     *   {{form:slug}}     内嵌表单
     *   {{page:slug}}     内嵌另一页面
     *   {{url:https://x}} 外链按钮
     *
     * @access public
     */
    public static function extractEmbeds(string $html): array
    {
        $embeds = [];
        $seen   = [];

        if (preg_match_all('/\{\{\s*(form|page|url)\s*:\s*([^}\s]+)\s*\}\}/i', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $type = strtolower($hit[1]);
                $raw  = trim($hit[2]);

                if ($type === 'url') {
                    if (!self::isSafeExternalUrl($raw)) {
                        continue;
                    }
                } else {
                    /*
                     * form / page 的 target 不做字符集白名单校验：
                     * 系统/历史数据的真实分享码无论什么字符集都应可嵌入，
                     * 是否存在由 PageViewApi::resolveEmbeds() 查库判定，
                     * 查不到的锚点渲染为「暂不可用」占位提示而非静默消失。
                     * 这里只限制长度并剔除危险字符 ——
                     * target 不会拼进任何 HTML 属性，仅用于查库与生成序号。
                     */
                    if (mb_strlen($raw) > 64 || preg_match('/[<>"\'&;]/', $raw)) {
                        continue;
                    }
                }

                $key = $type . ':' . $raw;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $embeds[] = ['type' => $type, 'target' => $raw];
            }
        }

        return $embeds;
    }

    /**
     * 把占位符替换为定位锚点
     *
     * 输出形如 <div class="of-embed" data-of-embed="0"></div>，
     * 前端按 data-of-embed 的序号取 embeds[i] 渲染真实组件。
     * 用户原文不会被拼进属性值，因此不存在属性注入。
     *
     * 分两步替换：
     *   1. 「仅含占位符的行内包装」整体替换 —— 富文本编辑器常把占位符
     *      包在 <span>/<b>/<a> 等行内标签里，若只替换占位符本身，
     *      会产出 <span><div class="of-embed">…</div></span> 这种
     *      行内标签包裹块级锚点的无效结构，浏览器解析时会强行重排，
     *      导致锚点位置偏移甚至被挤出父容器（表现为内嵌表单位置错乱）。
     *   2. 裸占位符替换 —— Markdown 独立行或未加包装的场景。
     *
     * @access public
     */
    public static function replaceEmbeds(string $html, array $embeds): string
    {
        $placeholderRe = '/\{\{\s*(form|page|url)\s*:\s*[^}\s]+\s*\}\}/i';

        if (!$embeds) {
            // 清理残留的占位符文本（连同其行内包装一起移除，避免留下空壳标签）
            $html = (string)preg_replace(
                '#<(\w+)[^>]*>\s*' . substr($placeholderRe, 1, -1) . '\s*</\1>#i',
                '',
                $html
            );
            return (string)preg_replace($placeholderRe, '', $html);
        }

        $map = [];
        foreach ($embeds as $i => $e) {
            $map[$e['type'] . ':' . $e['target']] = $i;
        }

        // 第零步：剥除属性值内的占位符（如 title="{{form:x}}"）——
        // 否则锚点会被替换进属性值，产出破损 DOM 且锚点丢失
        $html = (string)preg_replace(
            '/\s+[a-zA-Z-]+\s*=\s*("[^"]*\{\{[^}]*\}\}[^"]*"|\'[^\']*\{\{[^}]*\}\}[^\']*\')/i',
            '',
            $html
        );

        $anchorOf = function ($m) use ($map) {
            // $m['key'] 在两步替换中统一由命名捕获提供
            if (!isset($map[$m['key']])) {
                return '';
            }
            return '<div class="of-embed" data-of-embed="' . $map[$m['key']] . '"></div>';
        };

        // 第一步：整只替换「仅含一个占位符、无其他可见内容」的行内包装
        // 例如 <span style="font-size:14px">{{form:abc}}</span> → 锚点
        // 注意：标签名单独成捕获组（用作 </...> 反向引用），
        // 属性部分绝不能并进该组，否则 </span> 无法与
        // </span style="..."> 匹配，整个规则永不命中。
        $inlineWrapRe =
            '#<((?:span|em|strong|b|i|u|s|del|ins|mark|small|sub|sup|code)|a)\b[^>]*>'
            . '\s*\{\{\s*(?<type>form|page|url)\s*:\s*(?<target>[^}\s]+)\s*\}\}'
            . '\s*</\1>#i';
        $html = (string)preg_replace_callback($inlineWrapRe, function ($m) use ($map, $anchorOf) {
            $key = strtolower($m['type']) . ':' . trim($m['target']);
            return $anchorOf(['key' => $key]);
        }, $html);

        // 第二步：替换剩余的裸占位符（Markdown 独立行、无包装场景）
        $html = (string)preg_replace_callback(
            '/\{\{\s*(?<type>form|page|url)\s*:\s*(?<target>[^}\s]+)\s*\}\}/i',
            function ($m) use ($map, $anchorOf) {
                $key = strtolower($m['type']) . ':' . trim($m['target']);
                return $anchorOf(['key' => $key]);
            },
            $html
        );

        // 第三步：拆分「锚点混在 p / 标题内部」的块级容器
        // 例如 <p>前文<div class="of-embed"></div><div…></div>后文</p>
        // p 与 h1-h6 不允许包含块级子元素，浏览器会强行重排导致锚点错位。
        // 处理成：锚点独占一层，前后文字各占一个同标签段落。
        // li / blockquote 允许包含 div（flow content），无需处理。
        $html = (string)preg_replace_callback(
            '#<(p|h[1-6])([^>]*)>([\s\S]*?)</\1>#i',
            function ($m) {
                $inner = $m[3];
                if (strpos($inner, 'data-of-embed') === false) {
                    return $m[0];
                }
                // 按锚点把内容切成 [文本, 锚点, 文本, 锚点, …] 序列
                $parts = preg_split(
                    '/(<div class="of-embed" data-of-embed="\d+"><\/div>)/i',
                    $inner,
                    -1,
                    PREG_SPLIT_DELIM_CAPTURE
                );
                $out = [];
                foreach ($parts as $part) {
                    if (preg_match('/^<div class="of-embed"/i', trim($part))) {
                        $out[] = trim($part);
                        continue;
                    }
                    if (trim($part) !== '') {
                        $out[] = '<' . $m[1] . $m[2] . '>' . trim($part) . '</' . $m[1] . '>';
                    }
                }
                return implode("\n", $out);
            },
            $html
        );

        return $html;
    }

    /**
     * 外部链接是否安全（仅 http/https）
     *
     * @access private
     */
    private static function isSafeExternalUrl(string $url): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
        if ($url === '' || strlen($url) > 2000) {
            return false;
        }
        // 去掉空白与控制字符后再判协议，阻断 "java\tscript:" 之类绕过
        $probe = strtolower(preg_replace('/[\s\x00-\x20]/', '', $url) ?? '');
        if (!str_starts_with($probe, 'http://') && !str_starts_with($probe, 'https://')) {
            return false;
        }
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * 页面公开访问地址
     *
     * @access public
     */
    public static function shareUrl(string $slug): string
    {
        return request()->domain() . '/p/' . $slug;
    }
}