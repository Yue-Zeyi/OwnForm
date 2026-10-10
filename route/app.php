<?php

use think\facade\Route;

// 首页跳转到后台
Route::get('/', 'Index/index');

// ---------- 安装向导 ----------
Route::get('install', 'Install/page');
Route::get('api/install/status', 'Install/status');
Route::post('api/install/run', 'Install/run');

// ---------- 页面 ----------
Route::get('s/:slug', 'Page/fill');
Route::get('s/:slug/:code', 'Page/fill');
Route::get('p/:slug', 'Page/show');

// ---------- 认证 ----------
Route::post('api/auth/login', 'Auth/login');
Route::post('api/auth/logout', 'Auth/logout');
Route::get('api/auth/me', 'Auth/me')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/auth/password', 'Auth/password')->middleware(\app\middleware\AdminAuth::class);

// ---------- 概览 ----------
Route::get('api/dashboard', 'DataApi/dashboard')->middleware(\app\middleware\AdminAuth::class);

// ---------- 表单管理 ----------
Route::get('api/forms', 'FormApi/index')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/forms', 'FormApi/save')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/forms/:id/copy', 'FormApi/copy')->middleware(\app\middleware\AdminAuth::class);
Route::put('api/forms/:id', 'FormApi/update')->middleware(\app\middleware\AdminAuth::class);
Route::delete('api/forms/:id', 'FormApi/delete')->middleware(\app\middleware\AdminAuth::class);
Route::get('api/forms/:id', 'FormApi/read')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/forms/:id/status', 'FormApi/status')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/forms/:id/restore', 'FormApi/restore')->middleware(\app\middleware\AdminAuth::class);
Route::delete('api/forms/:id/purge', 'FormApi/purge')->middleware(\app\middleware\AdminAuth::class);

// ---------- 渠道管理 ----------
Route::get('api/forms/:formId/channels', 'ChannelApi/index')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/forms/:formId/channels', 'ChannelApi/save')->middleware(\app\middleware\AdminAuth::class);
Route::put('api/channels/:id', 'ChannelApi/update')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/channels/:id/status', 'ChannelApi/status')->middleware(\app\middleware\AdminAuth::class);
Route::delete('api/channels/:id', 'ChannelApi/delete')->middleware(\app\middleware\AdminAuth::class);

// ---------- 表单协作成员 ----------
Route::get('api/forms/:formId/members', 'FormApi/members')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/forms/:formId/members', 'FormApi/bindMember')->middleware(\app\middleware\AdminAuth::class);
Route::put('api/forms/:formId/members/:memberId', 'FormApi/updateMember')->middleware(\app\middleware\AdminAuth::class);
Route::delete('api/forms/:formId/members/:memberId', 'FormApi/unbindMember')->middleware(\app\middleware\AdminAuth::class);

// ---------- 数据管理 ----------
Route::get('api/data/:formId/export', 'DataApi/export')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/data/:formId/batch-delete', 'DataApi/batchDelete')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/data/:formId/restore', 'DataApi/restore')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/data/:formId/purge', 'DataApi/purge')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/data/batch-mark', 'DataApi/batchMark')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/data/batch-review', 'DataApi/batchReview')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/data/:formId/edit/:id', 'DataApi/edit')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/data/:formId/review', 'DataApi/review')->middleware(\app\middleware\AdminAuth::class);
Route::get('api/data', 'DataApi/all')->middleware(\app\middleware\AdminAuth::class);
Route::get('api/data/export-all', 'DataApi/exportAll')->middleware(\app\middleware\AdminAuth::class);
Route::get('api/data/:formId', 'DataApi/index')->middleware(\app\middleware\AdminAuth::class);
Route::get('api/data/:formId/detail/:id', 'DataApi/show')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/data/:formId/mark/:id', 'DataApi/mark')->middleware(\app\middleware\AdminAuth::class);
Route::delete('api/data/:formId/:id', 'DataApi/delete')->middleware(\app\middleware\AdminAuth::class);

// ---------- 统计分析 ----------
Route::get('api/stats/:formId', 'StatsApi/index')->middleware(\app\middleware\AdminAuth::class);

// ---------- 附件管理 ----------
Route::get('api/uploads', 'UploadApi/index')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/uploads/batch-delete', 'UploadApi/batchDelete')->middleware(\app\middleware\AdminAuth::class);
Route::delete('api/uploads/:id', 'UploadApi/remove')->middleware(\app\middleware\AdminAuth::class);

// ---------- 上传（后台与填写页共用） ----------
Route::post('api/upload', 'UploadApi/save');

// ---------- 用户管理（仅管理员） ----------
Route::get('api/users', 'UserApi/index')->middleware(\app\middleware\AdminAuth::class);
Route::get('api/users/:id/forms', 'UserApi/forms')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/users', 'UserApi/save')->middleware(\app\middleware\AdminAuth::class);
Route::put('api/users/:id', 'UserApi/update')->middleware(\app\middleware\AdminAuth::class);
Route::delete('api/users/:id', 'UserApi/delete')->middleware(\app\middleware\AdminAuth::class);

// ---------- 系统设置（仅管理员） ----------
Route::get('api/sys/settings', 'SysApi/read')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/sys/settings', 'SysApi/save')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/sys/test-notify', 'SysApi/testNotify')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/sys/test-mail', 'SysApi/testMail')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/sys/test-storage', 'SysApi/testStorage')->middleware(\app\middleware\AdminAuth::class);
Route::get('api/sys/about', 'SysApi/about')->middleware(\app\middleware\AdminAuth::class);

// 顶栏通知（需登录）
Route::get('api/notice/list', 'NoticeApi/list')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/notice/read', 'NoticeApi/read')->middleware(\app\middleware\AdminAuth::class);

// AI 助理代理（需登录，成员可用）
Route::post('api/ai/chat', 'AiChat/chat')->middleware(\app\middleware\AdminAuth::class);

// 品牌信息（公开，登录页需要）
Route::get('api/sys/brand', 'SysApi/brand');
Route::post('api/sys/cleanup', 'SysApi/cleanup')->middleware(\app\middleware\AdminAuth::class);

// ---------- 系统日志（仅管理员） ----------
Route::get('api/logs', 'LogApi/index')->middleware(\app\middleware\AdminAuth::class);
Route::get('api/logs/modules', 'LogApi/modules')->middleware(\app\middleware\AdminAuth::class);
Route::get('api/logs/:id', 'LogApi/detail')->middleware(\app\middleware\AdminAuth::class);
Route::delete('api/logs', 'LogApi/clear')->middleware(\app\middleware\AdminAuth::class);

// ---------- 公开填写 ----------
Route::get('api/fill/:slug', 'FillApi/read');
Route::post('api/fill/:slug/submit', 'FillApi/submit');
Route::post('api/fill/:slug/order', 'PayApi/order');
Route::get('api/fill/order/:orderNo/status', 'PayApi/status');
Route::post('api/fill/order/:orderNo/voucher', 'PayApi/voucher');
Route::post('api/fill/order/:orderNo/transfer-done', 'PayApi/transferDone');
Route::get('s/pay/return', 'PayApi/payReturn');

// ---------- 支付回调（渠道服务器调用，无会话无 CSRF） ----------
Route::post('api/pay/notify/wechat', 'PayApi/notifyWechat');
Route::post('api/pay/notify/alipay', 'PayApi/notifyAlipay');

// ---------- 订单管理（管理端） ----------
Route::get('api/orders/stats', 'OrderApi/stats')->middleware(\app\middleware\AdminAuth::class);
Route::get('api/orders', 'OrderApi/index')->middleware(\app\middleware\AdminAuth::class);
Route::get('api/orders/:id', 'OrderApi/read')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/orders/:id/verify', 'OrderApi/verify')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/orders/:id/refund', 'OrderApi/refund')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/orders/:id/cancel', 'OrderApi/cancel')->middleware(\app\middleware\AdminAuth::class);

// ---------- 授权与在线更新（管理端） ----------
Route::get('api/license/status', 'UpdateApi/status')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/license/activate', 'UpdateApi/activate')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/license/verify', 'UpdateApi/verify')->middleware(\app\middleware\AdminAuth::class);
Route::get('api/update/check', 'UpdateApi/check')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/update/apply', 'UpdateApi/apply')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/fill/:slug/sms-code', 'FillApi/smsCode');
Route::get('api/captcha', 'FillApi/captcha');

// ---------- 引流中心（公开入口） ----------
Route::get('q/:code', 'PromoJump/jump');
Route::post('api/q/:code/press', 'PromoJump/press');

// ---------- 页面访问（公开） ----------
Route::get('api/page/:slug', 'PageViewApi/read');
Route::post('api/page/:slug/view', 'PageViewApi/view');
Route::post('api/page/:slug/unlock', 'PageViewApi/unlock');

// ---------- 自定义页面管理 ----------
Route::get('api/pages', 'PageApi/index')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/pages/ai', 'PageApi/ai')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/pages', 'PageApi/save')->middleware(\app\middleware\AdminAuth::class);
Route::get('api/pages/:id', 'PageApi/read')->middleware(\app\middleware\AdminAuth::class);
Route::put('api/pages/:id', 'PageApi/update')->middleware(\app\middleware\AdminAuth::class);
Route::delete('api/pages/:id', 'PageApi/delete')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/pages/:id/status', 'PageApi/status')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/pages/:id/restore', 'PageApi/restore')->middleware(\app\middleware\AdminAuth::class);
Route::delete('api/pages/:id/purge', 'PageApi/purge')->middleware(\app\middleware\AdminAuth::class);

// ---------- 引流中心管理 ----------
Route::get('api/domains', 'PromoApi/domains')->middleware(\app\middleware\AdminAuth::class);
Route::get('api/promos', 'PromoApi/index')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/promos', 'PromoApi/save')->middleware(\app\middleware\AdminAuth::class);
Route::get('api/promos/:id', 'PromoApi/read')->middleware(\app\middleware\AdminAuth::class);
Route::put('api/promos/:id', 'PromoApi/update')->middleware(\app\middleware\AdminAuth::class);
Route::delete('api/promos/:id', 'PromoApi/delete')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/promos/:id/status', 'PromoApi/status')->middleware(\app\middleware\AdminAuth::class);
Route::post('api/promos/:id/restore', 'PromoApi/restore')->middleware(\app\middleware\AdminAuth::class);
Route::delete('api/promos/:id/purge', 'PromoApi/purge')->middleware(\app\middleware\AdminAuth::class);
Route::get('api/promos/:id/stats', 'PromoApi/stats')->middleware(\app\middleware\AdminAuth::class);

// 未匹配的 API 路径返回 JSON 404
Route::miss(function () {
    if (str_starts_with(request()->pathinfo(), 'api/')) {
        return json(['code' => 404, 'msg' => '接口不存在', 'data' => []], 404);
    }
    return redirect('/admin/');
});
