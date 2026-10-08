<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\Response;

/**
 * 静态页面输出：读取 html 外壳并注入数据
 */
class Page extends BaseController
{
    /**
     * 自定义页面 /p/{slug}
     */
    public function show(string $slug)
    {
        return $this->render('page/index.html', $slug);
    }

    /**
     * 填写页 /s/{slug} 或带渠道 /s/{slug}/{code}
     */
    public function fill(string $slug, string $code = '')
    {
        return $this->render('fill/index.html', $slug, $code);
    }

    /**
     * 输出静态外壳并注入 slug / 渠道码
     */
    private function render(string $shell, string $slug, string $channel = ''): Response
    {
        $file = app()->getRootPath() . 'public/static/' . $shell;
        $html = $this->loadShell($file);
        $payload = ['slug' => $slug];
        if ($channel !== '') {
            $payload['ch'] = $channel;
        }
        $safe = addslashes(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $html = str_replace(
            '<!--INJECT-->',
            '<script>window.__PAGE_DATA__ = JSON.parse("' . $safe . '");</script>',
            $html
        );
        // 外壳 HTML 禁止启发式缓存：版本升级覆盖文件后浏览器必须立即拿到新页面
        // （引用的静态资源仍走 ?v= 版本号长缓存）
        return Response::create($html)
            ->contentType('text/html', 'utf-8')
            ->cacheControl('no-cache, private');
    }

    private function loadShell(string $file): string
    {
        if (!is_file($file)) {
            return '<!doctype html><meta charset="utf-8"><title>404</title><p style="padding:40px">页面文件缺失</p>';
        }
        return (string)file_get_contents($file);
    }
}
