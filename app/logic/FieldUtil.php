<?php
declare(strict_types=1);

namespace app\logic;

/**
 * 设计器规则解析：从 form-create designer 规则 JSON 中提取可填写字段
 */
class FieldUtil
{
    /**
     * 合法字段名：字母、数字、下划线、短横线，1-64 位
     *
     * 该约束同时用于 SQL 的 JSON 路径拼接（DataApi::applyJsonFieldFilter），
     * 因此必须在字段定义入口就拒绝，而不能等到查询时才过滤。
     */
    public const FIELD_NAME_RE = '/^[A-Za-z0-9_-]{1,64}$/';

    /** 布局容器类型（无值，需递归 children） */
    public const LAYOUT_TYPES = ['col', 'row', 'card', 'collapseItem', 'collapse', 'table', 'grid', 'tabs', 'tabPane', 'el-row', 'el-col'];

    /**
     * 字段名是否合法
     */
    public static function isValidFieldName(string $field): bool
    {
        return $field !== '' && preg_match(self::FIELD_NAME_RE, $field) === 1;
    }

    /**
     * 提取叶子字段列表
     * @return array<int, array{field:string,title:string,type:string,options:array,required:bool,children:array}>
     */
    public static function extractFields(array $rule): array
    {
        $out = [];
        self::walk($rule, $out);
        return $out;
    }

    private static function walk(array $nodes, array &$out): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type  = (string)($node['type'] ?? '');
            $field = (string)($node['field'] ?? '');

            if ($field !== '' && !in_array($type, self::LAYOUT_TYPES, true)) {
                // 非法字段名直接丢弃：它既不该进入提交数据，也不该出现在
                // 可筛选字段列表里，否则会成为注入链的一环
                if (self::isValidFieldName($field)) {
                    $out[] = self::normalizeField($node);
                }
                continue;
            }
            // 布局容器：递归子节点（children 或 props.rule）
            if (!empty($node['children']) && is_array($node['children'])) {
                self::walk($node['children'], $out);
            }
            if (isset($node['props']['rule']) && is_array($node['props']['rule'])) {
                self::walk($node['props']['rule'], $out);
            }
        }
    }

    private static function normalizeField(array $node): array
    {
        $options = [];
        if (isset($node['options']) && is_array($node['options'])) {
            foreach ($node['options'] as $opt) {
                if (is_array($opt)) {
                    $options[] = ['label' => (string)($opt['label'] ?? ''), 'value' => $opt['value'] ?? ''];
                } else {
                    $options[] = ['label' => (string)$opt, 'value' => $opt];
                }
            }
        }
        $title = $node['title'] ?? '';
        if (is_array($title)) {
            $title = (string)($title['title'] ?? '');
        }
        $required = false;
        $effect = $node['effect'] ?? [];
        if (isset($effect['required'])) {
            $required = (bool)$effect['required'];
        } elseif (isset($node['validate']) && is_array($node['validate'])) {
            foreach ($node['validate'] as $v) {
                if (($v['required'] ?? false) === true) {
                    $required = true;
                    break;
                }
            }
        }
        return [
            'field'    => (string)($node['field'] ?? ''),
            'title'    => trim(strip_tags((string)$title)),
            'type'     => (string)($node['type'] ?? ''),
            'options'  => $options,
            'required' => $required,
        ];
    }

    /**
     * 填充默认提交设置
     */
    public static function normalizeSettings(array $settings): array
    {
        $defaults = [
            'successText'    => '提交成功，感谢您的填写！',
            'submitText'     => '提 交',
            'limitOnce'      => false,   // 同一 IP+设备仅可提交一次
            'needReview'     => false,   // 提交需审核
            'accessPassword' => '',      // 访问密码，空为不需要
            'endTime'        => '',      // 截止时间 Y-m-d H:i
            'maxCount'       => 0,       // 提交上限，0 不限
            'theme'          => '#409eff',
            'themeStyle'     => 'plain',    // gradient 渐变 / solid 纯色 / plain 简洁（默认纯净）
            'formOption'     => [],      // 设计器表单选项（labelWidth 等）
            'captcha'        => 'none',  // 提交验证：none/image/sms/geetest
            'notify_emails'  => '',     // 新提交邮件通知邮箱（逗号分隔，空为不发）
            'smsPhoneField'  => '',      // 短信验证时接收手机号的字段
            'service'        => ['type' => 'none', 'link' => '', 'qrcode' => ''],  // 在线客服
            'afterSubmit'   => ['type' => 'none', 'target' => '', 'delay' => 3], // 提交后跳转
            'links'         => [],                                          // 表单内关联页面/链接入口
            'pay_config'    => ['enabled' => false],                        // 收费配置（开关/定价/选项价格/成功文案）
        ];
        $settings = array_intersect_key($settings, $defaults) + $defaults;
        $settings['limitOnce']      = (bool)$settings['limitOnce'];
        $settings['needReview']     = (bool)$settings['needReview'];
        $settings['accessPassword'] = (string)$settings['accessPassword'];
        $settings['endTime']        = (string)$settings['endTime'];
        $settings['maxCount']       = max(0, (int)$settings['maxCount']);
        $settings['theme']          = (string)$settings['theme'];
        $settings['themeStyle']     = in_array($settings['themeStyle'], ['gradient', 'solid', 'plain'], true) ? $settings['themeStyle'] : 'plain';
        $settings['formOption']     = is_array($settings['formOption']) ? $settings['formOption'] : [];
        $settings['captcha']        = in_array($settings['captcha'], ['none', 'image', 'sms', 'geetest'], true) ? $settings['captcha'] : 'none';
        $settings['notify_emails']  = mb_substr(trim((string)$settings['notify_emails']), 0, 500);
        $settings['smsPhoneField']  = (string)$settings['smsPhoneField'];
        $settings['service']        = is_array($settings['service']) ? array_intersect_key($settings['service'], ['type' => 1, 'link' => 1, 'qrcode' => 1]) + ['type' => 'none', 'link' => '', 'qrcode' => ''] : ['type' => 'none', 'link' => '', 'qrcode' => ''];
        // 收费配置规范化：只保留已知键，金额/选项价格强校验
        $pay = is_array($settings['pay_config'] ?? null) ? $settings['pay_config'] : [];
        $prices = [];
        foreach ((array)($pay['optionPrices'] ?? []) as $label => $price) {
            $label = mb_substr(trim((string)$label), 0, 50);
            $price = round((float)$price, 2);
            if ($label !== '' && $price > 0 && $price <= 99999) {
                $prices[$label] = $price;
            }
        }
        $payMode = in_array($pay['mode'] ?? 'fixed', ['fixed', 'options'], true)
            ? ($pay['mode'] ?? 'fixed') : 'fixed';
        $settings['pay_config'] = [
            'enabled'      => (bool)($pay['enabled'] ?? false),
            'mode'         => $payMode,
            'amount'       => min(99999, max(0.01, round((float)($pay['amount'] ?? 0), 2))),
            'optionField'  => mb_substr((string)($pay['optionField'] ?? ''), 0, 50),
            'optionPrices' => $prices,
            'successText'  => mb_substr((string)($pay['successText'] ?? ''), 0, 200),
        ];
        $settings['service']['type'] = in_array($settings['service']['type'], ['none', 'link', 'qrcode'], true) ? $settings['service']['type'] : 'none';
        $settings['service']['link']   = (string)$settings['service']['link'];
        $settings['service']['qrcode'] = (string)$settings['service']['qrcode'];

        // 提交后跳转：target 为页面 slug 或 http(s) 链接，非法值降级为不跳转
        $after = is_array($settings['afterSubmit']) ? $settings['afterSubmit'] : [];
        $afterType = ($after['type'] ?? 'none') === 'url' ? 'url' : (($after['type'] ?? '') === 'page' ? 'page' : 'none');
        $afterTarget = self::sanitizeTarget((string)($after['target'] ?? ''), $afterType);
        $settings['afterSubmit'] = [
            'type'   => $afterType,
            'target' => $afterTarget,
            'delay'  => min(60, max(0, (int)($after['delay'] ?? 3))),
        ];

        // 表单内关联入口：最多 10 条，每条独立校验
        $links = [];
        if (is_array($settings['links'])) {
            foreach (array_slice($settings['links'], 0, 10) as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $type = ($link['type'] ?? '') === 'url' ? 'url' : (($link['type'] ?? '') === 'page' ? 'page' : '');
                if ($type === '') {
                    continue;
                }
                $target = self::sanitizeTarget((string)($link['target'] ?? ''), $type);
                if ($target === '') {
                    continue;
                }
                $links[] = [
                    'type'   => $type,
                    'title'  => mb_substr(trim((string)($link['title'] ?? '')), 0, 50) ?: ($type === 'page' ? '查看详情' : '查看更多'),
                    'target' => $target,
                ];
            }
        }
        $settings['links'] = $links;

        return $settings;
    }

    /**
     * 校验跳转目标
     *
     * page 类型要求本系统的 10 位分享码；url 类型要求 http(s) 绝对地址。
     * 二者之外的输入一律视为非法并丢弃，避免把 javascript: 之类带进前端。
     *
     * @access private
     */
    private static function sanitizeTarget(string $target, string $type): string
    {
        $target = trim($target);
        if ($target === '') {
            return '';
        }
        if ($type === 'page') {
            return preg_match('/^[a-z2-9]{10}$/', $target) === 1 ? $target : '';
        }
        if ($type === 'url') {
            // 去掉空白与控制字符后再判协议，阻断 "java\tscript:" 绕过
            $probe = strtolower(preg_replace('/[\s\x00-\x20]/', '', html_entity_decode($target, ENT_QUOTES, 'UTF-8')) ?? '');
            if (!str_starts_with($probe, 'http://') && !str_starts_with($probe, 'https://')) {
                return '';
            }
            return filter_var($target, FILTER_VALIDATE_URL) !== false ? $target : '';
        }
        return '';
    }

    /**
     * 解包提交载荷：兼容 { form: {...} } 包装形态
     */
    public static function unwrapValues(array $data): array
    {
        if (isset($data['form']) && is_array($data['form'])) {
            $data = $data['form'];
        }
        return $data;
    }

    /**
     * 清洗提交值：仅保留已定义字段，去 tags，限制长度
     */
    public static function sanitizeData(array $input, array $fields): array
    {
        $known = [];
        foreach ($fields as $f) {
            $known[$f['field']] = $f;
        }
        $clean = [];
        foreach ($input as $key => $value) {
            if (!isset($known[$key]) || str_starts_with((string)$key, '__')) {
                continue;
            }
            $clean[$key] = self::sanitizeValue($value);
        }
        return $clean;
    }

    private static function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach (array_slice($value, 0, 50) as $v) {
                $out[] = self::sanitizeValue($v);
            }
            return $out;
        }
        if (is_string($value)) {
            $value = trim($value);
            $value = strip_tags($value);
            return mb_substr($value, 0, 10000);
        }
        if (is_numeric($value) || is_bool($value) || $value === null) {
            return $value;
        }
        return (string)$value;
    }
}
