<?php
// +----------------------------------------------------------------------
// | 应用设置
// +----------------------------------------------------------------------

return [
    // 应用的命名空间
    'app_namespace'    => '',
    // 是否启用路由
    'with_route'       => true,
    // 默认应用
    'default_app'      => 'index',
    // 默认时区
    'default_timezone' => 'Asia/Shanghai',

    // 应用映射（自动多应用模式有效）
    'app_map'          => [],
    // 域名绑定（自动多应用模式有效）
    'domain_bind'      => [],
    // 禁止URL访问的应用列表（自动多应用模式有效）
    'deny_app_list'    => [],

    // 异常页面的模板文件
    'exception_tmpl'   => app()->getThinkPath() . 'tpl/think_exception.tpl',

    // 应用调试模式：默认关闭。
    // 显式声明安全默认值，避免未创建 .env 时退回框架的宽松行为。
    'app_debug'        => (bool)env('app_debug', false),

    // 允许查看调试级异常详情的 IP 白名单（配合 app_debug 使用）。
    // 即使 APP_DEBUG=true，非白名单来源也只会看到通用错误文案。
    'debug_ip_allowlist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string)env('debug_ip_allowlist', '127.0.0.1'))
    ))),

    // 错误显示信息,非调试模式有效
    'error_message'    => '页面错误！请稍后再试～',
    // 显示错误信息
    'show_error_msg'   => false,
];
