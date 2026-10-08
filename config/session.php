<?php
// +----------------------------------------------------------------------
// | 会话设置
// +----------------------------------------------------------------------

return [
    // session name（改用应用专名，避免暴露默认会话指纹）
    'name'           => 'OFSESSID',
    // SESSION_ID的提交变量,解决flash上传跨域
    'var_session_id' => '',
    // 驱动方式 支持file cache
    'type'           => 'file',
    // 存储连接标识 当type使用cache的时候有效
    'store'          => null,
    // 过期时间
    'expire'         => 7200,
    // 前缀
    'prefix'         => 'of_',
    // 禁止 JavaScript 读取会话 Cookie（防 XSS 窃取会话）
    'httponly'       => true,
];
