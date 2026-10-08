<?php
// 全局中间件定义文件
return [
    // 全局请求缓存
    // \think\middleware\CheckRequestCache::class,
    // 多语言加载
    // \think\middleware\LoadLangPack::class,
    // Session初始化
    \think\middleware\SessionInit::class,
    // API 报文日志（系统设置 log_debug 开启时记录）
    \app\middleware\ApiLog::class,
    // CSRF 防护：Session Cookie 认证下的跨站请求拦截
    \app\middleware\Csrf::class,
];
