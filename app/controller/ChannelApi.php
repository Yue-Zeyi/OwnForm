<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\logic\ChannelUtil;
use think\facade\Db;

/**
 * 表单渠道管理
 *
 * 权限口径：
 *  - 管理员 / 表单创建者：管理该表单全部渠道（可为成员代建、改归属）
 *  - 被授权成员（can_channel 开）：仅能新建和管理自己名下的渠道
 */
class ChannelApi extends BaseController
{
    /**
     * 渠道列表
     */
    public function index(int $formId)
    {
        $form = $this->loadVisibleForm($formId);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        $perm = $this->permOf($form);
        if (!$perm['owner'] && !$perm['canChannel']) {
            return $this->fail('无渠道管理权限', 403);
        }

        $query = Db::name('form_channels')->where('form_id', $formId)->whereNull('deleted_at');
        if (!$perm['owner']) {
            $query->where('member_id', $this->uid());
        }
        $list = $query->order('id', 'desc')->select()->toArray();

        // 归属成员昵称（含已删除成员，兜底文案）
        $memberIds = array_filter(array_unique(array_column($list, 'member_id')));
        $nicknames = $memberIds
            ? Db::name('users')->whereIn('id', $memberIds)->column('nickname', 'id')
            : [];

        foreach ($list as &$row) {
            $row = $this->format($row, $form, $nicknames);
        }
        unset($row);

        return $this->ok([
            'list'      => $list,
            // 管理员/创建者视角：可指定归属成员（仅成员角色账号可选）
            'canAssign' => $perm['owner'],
            'members'   => $perm['owner'] ? $this->memberOptions() : [],
        ]);
    }

    /**
     * 新建渠道
     */
    public function save(int $formId)
    {
        $form = $this->loadVisibleForm($formId);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        $perm = $this->permOf($form);
        if (!$perm['owner'] && !$perm['canChannel']) {
            return $this->fail('无渠道管理权限', 403);
        }

        $data     = $this->input();
        $name     = trim((string)($data['name'] ?? ''));
        $memberId = isset($data['member_id']) ? (int)$data['member_id'] : 0;

        if ($name === '' || mb_strlen($name) > 100) {
            return $this->fail('渠道名称必填且不超过 100 字');
        }
        // 成员自建渠道强制归属自己；管理员/创建者可代建给指定成员或留空为公共渠道
        if (!$perm['owner']) {
            $memberId = $this->uid();
        }
        if ($memberId > 0) {
            $member = Db::name('users')->where('id', $memberId)->where('role', 'member')->where('status', 1)->find();
            if (!$member) {
                return $this->fail('归属成员不存在或不是可用成员账号');
            }
            // 为成员代建渠道但不建授权是允许的：渠道数据归其名下做归因统计，
            // 成员后台能否查看由 form_members 授权决定
        }

        // 定时上下线：起止均可留空；起点晚于终点自动对调
        $onlineFrom  = trim((string)($data['online_from'] ?? ''));
        $onlineUntil = trim((string)($data['online_until'] ?? ''));
        if ($onlineFrom !== '' && strtotime($onlineFrom) === false) {
            $onlineFrom = '';
        }
        if ($onlineUntil !== '' && strtotime($onlineUntil) === false) {
            $onlineUntil = '';
        }
        if ($onlineFrom !== '' && $onlineUntil !== '' && strtotime($onlineFrom) > strtotime($onlineUntil)) {
            [$onlineFrom, $onlineUntil] = [$onlineUntil, $onlineFrom];
        }

        $now = $this->now();
        $id  = (int)Db::name('form_channels')->insertGetId([
            'form_id'      => $formId,
            'name'         => $name,
            'code'         => ChannelUtil::genCode(),
            'member_id'    => $memberId > 0 ? $memberId : null,
            'status'       => 1,
            'online_from'  => $onlineFrom !== '' ? date('Y-m-d H:i:s', strtotime($onlineFrom)) : null,
            'online_until' => $onlineUntil !== '' ? date('Y-m-d H:i:s', strtotime($onlineUntil)) : null,
            'submit_count' => 0,
            'created_by'   => $this->uid(),
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
        \app\logic\OpLog::write('form', '新建渠道', '表单#' . $formId . ' 渠道「' . $name . '」');
        return $this->ok(['id' => $id], '渠道已创建');
    }

    /**
     * 编辑渠道：改名 / 改归属（归属仅管理员/创建者可改）
     */
    public function update(int $id)
    {
        [$channel, $form, $perm] = $this->loadManaged($id);
        if (!$channel) {
            return $this->fail('渠道不存在', 404);
        }
        $data   = $this->input();
        $update = ['updated_at' => $this->now()];

        if (isset($data['name'])) {
            $name = trim((string)$data['name']);
            if ($name === '' || mb_strlen($name) > 100) {
                return $this->fail('渠道名称必填且不超过 100 字');
            }
            $update['name'] = $name;
        }
        if (array_key_exists('member_id', $data)) {
            if (!$perm['owner']) {
                return $this->fail('无权修改渠道归属', 403);
            }
            $memberId = (int)$data['member_id'];
            if ($memberId > 0) {
                $member = Db::name('users')->where('id', $memberId)->where('role', 'member')->find();
                if (!$member) {
                    return $this->fail('归属成员不存在或不是成员账号');
                }
                $update['member_id'] = $memberId;
            } else {
                $update['member_id'] = null;
            }
        }
        // 定时上下线窗口：起止均可独立清空；起点晚于终点自动对调
        if (array_key_exists('online_from', $data) || array_key_exists('online_until', $data)) {
            $from  = trim((string)($data['online_from'] ?? ($channel['online_from'] ?? '')));
            $until = trim((string)($data['online_until'] ?? ($channel['online_until'] ?? '')));
            if ($from !== '' && strtotime($from) === false) {
                $from = '';
            }
            if ($until !== '' && strtotime($until) === false) {
                $until = '';
            }
            if ($from !== '' && $until !== '' && strtotime($from) > strtotime($until)) {
                [$from, $until] = [$until, $from];
            }
            $update['online_from'] = $from !== '' ? date('Y-m-d H:i:s', strtotime($from)) : null;
            $update['online_until'] = $until !== '' ? date('Y-m-d H:i:s', strtotime($until)) : null;
        }
        Db::name('form_channels')->where('id', $id)->update($update);
        \app\logic\OpLog::write('form', '编辑渠道', '表单#' . $channel['form_id'] . ' 渠道「' . $channel['name'] . '」');
        return $this->ok([], '已保存');
    }

    /**
     * 启用 / 停用（停用仅不再推广，链接仍可填写并照常归因）
     */
    public function status(int $id)
    {
        [$channel, , ] = $this->loadManaged($id);
        if (!$channel) {
            return $this->fail('渠道不存在', 404);
        }
        $status = (int)($this->input()['status'] ?? 1) === 1 ? 1 : 0;
        Db::name('form_channels')->where('id', $id)->update(['status' => $status, 'updated_at' => $this->now()]);
        \app\logic\OpLog::write('form', $status ? '启用渠道' : '停用渠道', '表单#' . $channel['form_id'] . ' 渠道「' . $channel['name'] . '」');
        return $this->ok(['status' => $status], $status ? '已启用' : '已停用');
    }

    /**
     * 删除（软删：链接不再归因，历史提交渠道列显示"已删除渠道"）
     */
    public function delete(int $id)
    {
        [$channel, , ] = $this->loadManaged($id);
        if (!$channel) {
            return $this->fail('渠道不存在', 404);
        }
        Db::name('form_channels')->where('id', $id)->update(['deleted_at' => $this->now(), 'status' => 0, 'updated_at' => $this->now()]);
        \app\logic\OpLog::write('form', '删除渠道', '表单#' . $channel['form_id'] . ' 渠道「' . $channel['name'] . '」');
        return $this->ok([], '已删除');
    }

    /**
     * 载入当前用户可管理的渠道，返回 [渠道, 表单, 权限]；不可管理返回 [null, null, null]
     */
    private function loadManaged(int $id): array
    {
        $channel = Db::name('form_channels')->where('id', $id)->whereNull('deleted_at')->find();
        if (!$channel) {
            return [null, null, null];
        }
        $form = $this->loadVisibleForm((int)$channel['form_id']);
        if (!$form) {
            return [null, null, null];
        }
        $perm = $this->permOf($form);
        if (!$perm['owner'] && (!$perm['canChannel'] || (int)($channel['member_id'] ?? 0) !== $this->uid())) {
            return [null, null, null];
        }
        return [$channel, $form, $perm];
    }

    private function format(array $row, array $form, array $nicknames): array
    {
        $memberId = $row['member_id'] !== null ? (int)$row['member_id'] : 0;
        return [
            'id'          => (int)$row['id'],
            'name'        => (string)$row['name'],
            'code'        => (string)$row['code'],
            'memberId'    => $memberId,
            'memberName'  => $memberId ? ($nicknames[$memberId] ?? '已注销成员') : '',
            'status'      => (int)$row['status'],
            'onlineFrom'  => (string)($row['online_from'] ?? ''),
            'onlineUntil' => (string)($row['online_until'] ?? ''),
            'onlineNow'   => ChannelUtil::isOnline($row),
            'submitCount' => (int)$row['submit_count'],
            'shareUrl'    => ChannelUtil::shareUrl((string)$form['slug'], (string)$row['code']),
            'createdAt'   => $row['created_at'],
        ];
    }

    /**
     * 可选归属成员列表（仅成员角色）
     */
    private function memberOptions(): array
    {
        return Db::name('users')
            ->where('role', 'member')->where('status', 1)
            ->field('id, username, nickname')
            ->order('id', 'asc')->select()->toArray();
    }
}
