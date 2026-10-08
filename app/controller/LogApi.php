<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\Db;

/**
 * 系统日志查询（仅管理员）
 */
class LogApi extends BaseController
{
    private const MODULES = [
        'auth'   => '登录认证',
        'form'   => '表单管理',
        'data'   => '提交数据',
        'fill'   => '表单提交',
        'upload' => '附件',
        'user'   => '用户管理',
        'page'   => '页面管理',
        'promo'  => '引流中心',
        'sys'    => '系统设置',
        'http'   => '接口报文'
    ];

    public function index()
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        $page    = max(1, (int)input('page', 1));
        $size    = min(200, max(1, (int)input('size', 30)));
        $module  = trim((string)input('module', ''));
        $status  = input('status', '');
        $keyword = trim((string)input('keyword', ''));
        $start   = trim((string)input('start', ''));
        $end     = trim((string)input('end', ''));

        $excludeFill = (int)input('exclude_fill', 0);

        $query = Db::name('logs');
        if ($module !== '' && isset(self::MODULES[$module])) {
            $query->where('module', $module);
        } elseif ($excludeFill) {
            $query->where('module', '<>', 'fill');
        }
        if ($status !== '' && $status !== null) {
            $query->where('status', (int)$status);
        }
        if ($keyword !== '') {
            $safe = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword);
            $query->whereLike('username|action|detail', '%' . $safe . '%');
        }
        if ($start !== '') {
            $query->whereTime('created_at', '>=', $start);
        }
        if ($end !== '') {
            $query->whereTime('created_at', '<=', date('Y-m-d 23:59:59', strtotime($end)));
        }

        $total = (clone $query)->count();
        // 列表不返回 request/response 报文（单条可达 6KB），否则日志表增长后
        // 单页响应会膨胀到数十 MB
        $list  = $query->order('id', 'desc')
            ->field('id, user_id, username, module, action, detail, status, ip, user_agent, duration, created_at')
            ->page($page, $size)->select()->toArray();

        return $this->ok([
            'list'     => $list,
            'total'    => $total,
            'page'     => $page,
            'size'     => $size,
            'modules'  => self::MODULES,
        ]);
    }

    /**
     * 单条日志详情（含报文，仅管理员）
     */
    public function detail(int $id)
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        $row = Db::name('logs')->where('id', $id)->find();
        if (!$row) {
            return $this->fail('日志不存在', 404);
        }
        return $this->ok($row);
    }

    /**
     * 清空全部日志（管理员）
     */
    public function clear()
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        $n = Db::name('logs')->delete(true);
        \app\logic\OpLog::write('sys', '清空日志', '共清除 ' . $n . ' 条');
        return $this->ok(['count' => $n], '已清空 ' . $n . ' 条日志');
    }

    public function modules()
    {
        if ($err = $this->forbidNonAdmin()) {
            return $err;
        }
        return $this->ok(self::MODULES);
    }
}
