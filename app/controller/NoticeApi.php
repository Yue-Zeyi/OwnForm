<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\Db;

/**
 * 顶栏通知：新提交提醒 + 待审核提醒（按用户权限隔离）
 * 已读方式：记录每位用户已读的最大提交 ID（水位），大于该 ID 的提交计为未读
 * 单条已读：把水位推进到该条 ID；全部已读：推进到权限范围内最大提交 ID
 */
class NoticeApi extends BaseController
{
    private function readKey(): string
    {
        return 'notice_read_id_' . $this->uid();
    }

    private function idsKey(): string
    {
        return 'notice_read_ids_' . $this->uid();
    }

    /**
     * 已读状态：水位 wm（≤wm 全部已读）+ 显式已读 ID 集合（处理零散点击）
     * 兼容旧版时间戳记录：一次性换算为该时间前的最大提交 ID
     */
    private function readState(): array
    {
        $row = Db::name('settings')
            ->where('setting_key', $this->readKey())
            ->value('setting_value');
        $wm = $row !== null ? (int)$row : null;
        if ($wm === null) {
            $old = (string)Db::name('settings')
                ->where('setting_key', 'notice_read_' . $this->uid())
                ->value('setting_value');
            if ($old !== '' && ($ts = strtotime($old))) {
                $wm = (int)Db::name('form_submissions')
                    ->whereTime('created_at', '<=', $old)
                    ->max('id');
                $this->saveState($wm, []);
                return ['wm' => $wm, 'ids' => []];
            }
            $wm = 0;
        }
        $ids = array_map('intval', array_filter(explode(',', (string)Db::name('settings')
            ->where('setting_key', $this->idsKey())
            ->value('setting_value'))));
        return ['wm' => $wm, 'ids' => $ids];
    }

    private function saveState(int $wm, array $ids): void
    {
        $now = $this->now();
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);
        // 已读集合超过 60 条时压缩：连续段并入水位
        if (count($ids) > 60) {
            while (in_array($wm + 1, $ids)) {
                $wm++;
                $ids = array_values(array_diff($ids, [$wm]));
            }
        }
        $rows = [
            [$this->readKey(), (string)$wm],
            [$this->idsKey(), implode(',', $ids)],
        ];
        foreach ($rows as [$k, $v]) {
            Db::name('settings')->duplicate([
                'setting_value' => $v,
                'updated_at'    => $now,
            ])->insert([
                'setting_key'   => $k,
                'setting_value' => $v,
                'updated_at'    => $now,
            ]);
        }
    }

    private function isUnread(int $id, array $state): bool
    {
        return $id > $state['wm'] && !in_array($id, $state['ids'], true);
    }

    /** 权限范围内的表单 ID 列表（管理员为全部） */
    private function scopedFormIds(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }
        return (clone $this->formQuery())->column('id');
    }

    /**
     * 通知列表：最近提交（未读数）+ 待审核
     */
    public function list()
    {
        $myFormIds = $this->scopedFormIds();
        $scope = function () use ($myFormIds) {
            $q = Db::name('form_submissions')->whereNull('deleted_at');
            if ($myFormIds !== null) {
                $q->whereIn('form_id', $myFormIds ?: [0]);
            }
            return $q;
        };

        $readState = $this->readState();

        // 最近提交（含待审核标记）
        $submissions = (clone $scope())
            ->order('id', 'desc')->limit(15)
            ->field('id, form_id, data_json, status, created_at')
            ->select()->toArray();
        $formTitles = Db::name('forms')
            ->whereIn('id', array_column($submissions, 'form_id') ?: [0])
            ->column('title', 'id');
        $notices = [];
        $unread = 0;
        foreach ($submissions as $s) {
            $isUnread = $this->isUnread((int)$s['id'], $readState);
            if ($isUnread) {
                $unread++;
            }
            $data = json_decode((string)$s['data_json'], true) ?: [];
            $preview = [];
            foreach (array_slice($data, 0, 2, true) as $v) {
                if (is_array($v)) {
                    $v = implode('、', array_map(fn($x) => is_scalar($x) ? (string)$x : '', $v));
                }
                $preview[] = mb_substr((string)$v, 0, 20);
            }
            $notices[] = [
                'id'        => (int)$s['id'],
                'formId'    => (int)$s['form_id'],
                'title'     => ($formTitles[$s['form_id']] ?? '未知表单') . ' 有新提交' . ((int)$s['status'] === 0 ? '（待审核）' : ''),
                'preview'   => implode(' ｜ ', array_filter($preview)) ?: '（无内容预览）',
                'createdAt' => $s['created_at'],
                'unread'    => $isUnread,
            ];
        }

        // 待审核列表（最新 10 条）
        $pendingRows = (clone $scope())->where('status', 0)
            ->order('id', 'desc')->limit(10)
            ->field('id, form_id, data_json, created_at')->select()->toArray();
        $pendingTitles = Db::name('forms')
            ->whereIn('id', array_column($pendingRows, 'form_id') ?: [0])
            ->column('title', 'id');
        $pending = [];
        foreach ($pendingRows as $s) {
            $data = json_decode((string)$s['data_json'], true) ?: [];
            $preview = [];
            foreach (array_slice($data, 0, 2, true) as $v) {
                if (is_array($v)) {
                    $v = implode('、', array_map(fn($x) => is_scalar($x) ? (string)$x : '', $v));
                }
                $preview[] = mb_substr((string)$v, 0, 20);
            }
            $pending[] = [
                'id'        => (int)$s['id'],
                'formId'    => (int)$s['form_id'],
                'title'     => ($pendingTitles[$s['form_id']] ?? '未知表单') . ' 的提交待审核',
                'preview'   => implode(' ｜ ', array_filter($preview)),
                'createdAt' => $s['created_at'],
            ];
        }

        return $this->ok([
            'unread'  => $unread,
            'notices' => $notices,
            'pending' => $pending,
        ]);
    }

    /**
     * 标记已读：带 id 为单条已读，不带为全部已读
     */
    public function read()
    {
        $myFormIds = $this->scopedFormIds();
        $scope = function () use ($myFormIds) {
            $q = Db::name('form_submissions')->whereNull('deleted_at');
            if ($myFormIds !== null) {
                $q->whereIn('form_id', $myFormIds ?: [0]);
            }
            return $q;
        };

        $data = $this->input();
        $oneId = (int)($data['id'] ?? 0);
        $state = $this->readState();
        if ($oneId > 0) {
            // 单条已读：校验该提交在权限范围内，只读点击的这一条
            $row = Db::name('form_submissions')->where('id', $oneId)->find();
            if (!$row || ($myFormIds !== null && !in_array((int)$row['form_id'], $myFormIds, true))) {
                return $this->fail('通知不存在', 404);
            }
            if ($this->isUnread($oneId, $state)) {
                $state['ids'][] = $oneId;
                $this->saveState($state['wm'], $state['ids']);
                return $this->ok(['unreadDiff' => 1], '已标记为已读');
            }
            return $this->ok(['unreadDiff' => 0], '已标记为已读');
        }

        // 全部已读：水位推进到权限范围内最大提交 ID，清空零散集合
        $maxId = (int)(clone $scope())->max('id');
        $this->saveState($maxId, []);
        return $this->ok([], '已全部标记为已读');
    }
}
