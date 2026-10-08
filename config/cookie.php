<?php
// +----------------------------------------------------------------------
// | Cookie设置
// +----------------------------------------------------------------------
return [
    // cookie 保存时间
    'expire'    => 0,
    // cookie 保存路径
    'path'      => '/',
    // cookie 有效域名
    'domain'    => '',
    //  cookie 启用安全传输
    // 由 env COOKIE_SECURE 显式控制（生产走 HTTPS 时设为 true）。
    // 不自动嗅探 $_SERVER，避免伪造 Host/X-Forwarded-Proto 绕过。
    'secure'    => filter_var(env('cookie_secure', false), FILTER_VALIDATE_BOOLEAN),
    // httponly设置
    'httponly'  => true,
    // 是否使用 setcookie
    'setcookie' => true,
    // samesite 设置，支持 'strict' 'lax'
    'samesite'  => 'Lax',
];
