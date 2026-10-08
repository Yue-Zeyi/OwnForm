<?php

// 数据库配置：连接参数由安装向导写入 config/db_local.php
$dbLocal = [];
if (is_file(__DIR__ . '/db_local.php')) {
    $dbLocal = include __DIR__ . '/db_local.php';
}
if (!is_array($dbLocal)) {
    $dbLocal = [];
}

$mysql = array_merge([
    'type'              => 'mysql',
    'hostname'          => '127.0.0.1',
    'database'          => '',
    'username'          => '',
    'password'          => '',
    'hostport'          => '3306',
    'charset'           => 'utf8mb4',
    'prefix'            => 'of_',
    'deploy'            => 0,
    'dsn'               => '',
    'params'            => [],
    'builder'           => '',
    'trigger_sql'       => false,
    'break_reconnect'   => false,
], $dbLocal);

return [
    // 默认使用的数据库连接配置
    'default'         => 'mysql',

    // 自定义时间查询规则
    'time_query_rule' => [],

    // 自动写入时间戳（本系统在代码中手动写入 created_at/updated_at）
    'auto_timestamp'  => false,

    // 时间字段取出后的默认时间格式
    'datetime_format' => 'Y-m-d H:i:s',

    // 时间字段配置 配置格式：create_time,update_time
    'datetime_field'  => '',

    // 数据库连接配置信息
    'connections'     => [
        'mysql' => $mysql,
    ],
];
