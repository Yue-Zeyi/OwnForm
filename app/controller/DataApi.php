<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\logic\FieldUtil;
use app\logic\PageUtil;
use app\logic\PromoUtil;
use think\facade\Db;

/**
 * 提交数据管理 + 概览
 */
class DataApi extends BaseController
{
    /**
     * 数据列表
     */
    public function index(int $formId)
    {
        $form = $this->loadVisibleForm($formId);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        $perm = $this->permOf($form);

        $fields  = FieldUtil::extractFields(json_decode((string)$form['fields_json'], true) ?: []);
        $page    = max(1, (int)input('page', 1));
        $size    = min(200, max(1, (int)input('size', 20)));
        $keyword = trim((string)input('keyword', ''));
        $start   = trim((string)input('start', ''));
        $end     = trim((string)input('end', ''));
        $field   = trim((string)input('field', ''));
        $value   = trim((string)input('value', ''));
        $status  = input('status', '');
        $flag    = input('flag', '');
        $payStatus = input('pay_status', '');
        if ($payStatus !== '' && $payStatus !== null) {
            $query->where('s.pay_status', (int)$payStatus);
        }
        // 排序：仅白名单字段（默认提交时间倒序）
        $sort = (string)input('sort', 'created_at');
        $sort = in_array($sort, ['created_at', 'id'], true) ? $sort : 'created_at';
        $order = strtolower((string)input('order', 'desc')) === 'asc' ? 'asc' : 'desc';
        // 回收站模式：仅看已删除的提交
        $deleted = (int)input('deleted', 0) === 1;

        // own 范围授权：仅可见归属自己渠道的提交（含回收站视角）
        // null = 不做渠道过滤（管理员/创建者/all 范围授权者）
        $ownChannelIds = (!$perm['owner'] && $perm['scope'] === 'own')
            ? $this->ownChannelIds($formId)
            : null;
        $scopeFilter = function ($q) use ($ownChannelIds) {
            if ($ownChannelIds !== null) {
                // 空数组也必须显式过滤：授权成员未建渠道时一条都不可见
                $q->whereIn('channel_id', $ownChannelIds ?: [0]);
            }
        };

        $query = Db::name('form_submissions')->where('form_id', $formId)
            ->whereNull('deleted_at');
        if ($deleted) {
            $query = Db::name('form_submissions')->where('form_id', $formId)
                ->whereNotNull('deleted_at');
        }
        $scopeFilter($query);
        // 审核模式：默认只显示已通过，可显式查看待审核（回收站模式不过滤状态）
        $needReview = self::needReview($form);
        if (!$deleted) {
            if ($status !== '' && $status !== null) {
                $query->where('status', (int)$status);
            } elseif ($needReview) {
                $query->where('status', 1);
            }
        }

        if ($keyword !== '') {
            $safe = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword);
            $query->where(function ($q) use ($safe) {
                $q->whereLike('data_json', '%' . $safe . '%')
                  ->whereOr('remark', 'like', '%' . $safe . '%');
            });
        }
        if ($flag !== '' && $flag !== null) {
            $query->where('flag', (int)$flag);
        }
        if (isset($payStatus) && $payStatus !== '' && $payStatus !== null) {
            $query->where('pay_status', (int)$payStatus);
        }
        $channelFilter = input('channel_id', '');
        if ($channelFilter !== '' && $channelFilter !== null) {
            $query->where('channel_id', (int)$channelFilter);
        }
        if ($start !== '') {
            $query->whereTime('created_at', '>=', $start);
        }
        if ($end !== '' && strtotime($end) !== false) {
            $query->whereTime('created_at', '<=', date('Y-m-d 23:59:59', strtotime($end)));
        }
        if ($field !== '' && $value !== '' && self::validField($fields, $field)) {
            self::applyJsonFieldFilter($query, $field, $value);
        }

        $total = (clone $query)->count();
        $list  = $query->field('id, channel_id, data_json, ip, device, user_agent, status, pay_status, order_no, flag, remark, created_at, deleted_at')
            ->page($page, $size)
            ->order($sort, $order)
            ->select()
            ->toArray();

        // 渠道名称映射（含已删除渠道：历史归因保留名称快照）
        $channelIds = array_filter(array_unique(array_map('intval', array_column($list, 'channel_id'))));
        $channelMap = $channelIds
            ? Db::name('form_channels')->whereIn('id', $channelIds)->column('name', 'id')
            : [];

        $items = [];
        foreach ($list as $row) {
            $chId = $row['channel_id'] !== null ? (int)$row['channel_id'] : 0;
            $items[] = [
                'id'          => (int)$row['id'],
                'data'        => json_decode((string)$row['data_json'], true) ?: [],
                'ip'          => $row['ip'],
                'device'      => $row['device'],
                'status'      => (int)$row['status'],
                'payStatus'   => (int)($row['pay_status'] ?? 0),
                'orderNo'     => (string)($row['order_no'] ?? ''),
                'flag'        => (int)$row['flag'],
                'remark'      => (string)$row['remark'],
                'channelId'   => $chId,
                'channelName' => $chId ? ($channelMap[$chId] ?? '已删除渠道') : '',
                'createdAt'   => $row['created_at'],
                'deletedAt'   => $row['deleted_at'] ?? null,
            ];
        }

        // 渠道筛选下拉（own 视角仅自己名下渠道）
        $chQuery = Db::name('form_channels')->where('form_id', $formId)->whereNull('deleted_at')
            ->field('id, name, member_id, status')->order('id', 'desc');
        if (!$perm['owner'] && $perm['scope'] === 'own') {
            $chQuery->where('member_id', $this->uid());
        }
        $channels = $chQuery->select()->toArray();

        $pendingQuery = Db::name('form_submissions')->where('form_id', $formId)->whereNull('deleted_at')->where('status', 0);
        $scopeFilter($pendingQuery);

        return $this->ok([
            'list'  => $items,
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
            'fields' => $fields,
            'needReview' => $needReview,
            'payConfig'  => \app\logic\OrderUtil::payConfig($form),
            'deleted' => $deleted,
            // 当前用户对该表单的数据权限（前端控制按钮显隐）
            'perm' => [
                'owner'     => $perm['owner'],
                'scope'     => $perm['scope'],
                'canExport' => $perm['canExport'],
            ],
            'channels' => $channels,
            // 最近一次结构快照时间：提交早于该时间的可能来自旧版表单
            'lastVersionAt' => Db::name('form_versions')->where('form_id', $formId)->max('created_at') ?: null,
            'pending' => $needReview ? $pendingQuery->count() : 0,
        ]);
    }

    /**
     * 批量打标旗/备注（表单数据页多选）
     *
     * 逐条做归属校验：管理员/创建者直接生效；
     * own 范围授权的表单仅限归属自己渠道的提交；无授权的表单跳过。
     */
    public function batchMark()
    {
        $items = input('items/a', []);
        if (!is_array($items) || !$items) {
            return $this->fail('请先选择要操作的提交');
        }
        $flag   = min(6, max(0, (int)input('flag', 0)));
        $remark = mb_substr(trim((string)input('remark', '')), 0, 500);

        $updated = 0;
        foreach ($items as $it) {
            $formId = (int)($it['formId'] ?? 0);
            $id     = (int)($it['id'] ?? 0);
            if (!$formId || !$id) {
                continue;
            }
            $form = $this->loadVisibleForm($formId);
            if (!$form) {
                continue;
            }
            $perm = $this->permOf($form);
            $row = Db::name('form_submissions')
                ->where('form_id', $formId)->where('id', $id)
                ->whereNull('deleted_at')->find();
            if (!$row) {
                continue;
            }
            if (!$perm['owner'] && $perm['scope'] === 'own') {
                $ownIds = $this->ownChannelIds($formId);
                if (!in_array((int)($row['channel_id'] ?? 0), $ownIds, true)) {
                    continue;
                }
            } elseif (!$perm['owner'] && $perm['scope'] === 'none') {
                continue;
            }
            Db::name('form_submissions')->where('id', $id)
                ->update(['flag' => $flag, 'remark' => $remark]);
            $updated++;
        }
        if ($updated) {
            \app\logic\OpLog::write('data', '批量标旗备注', '更新 ' . $updated . ' 条提交（旗标=' . $flag . '）');
        }
        return $this->ok(['updated' => $updated], '已更新 ' . $updated . ' 条');
    }

    /**
     * 批量审核（表单数据页多选）：status 1=通过 0=隐藏
     *
     * 逐条做归属校验：管理员/创建者直接生效；
     * own 范围授权的成员无审核权（审核决策属表单所有者），无授权的表单跳过。
     */
    public function batchReview()
    {
        $items = input('items/a', []);
        $status = (int)input('status', 1) === 1 ? 1 : 0;
        if (!is_array($items) || !$items) {
            return $this->fail('请先选择要操作的提交');
        }
        $updated = 0;
        foreach ($items as $it) {
            $formId = (int)($it['formId'] ?? 0);
            $id     = (int)($it['id'] ?? 0);
            if (!$formId || !$id) {
                continue;
            }
            $form = $this->loadVisibleForm($formId);
            if (!$form) {
                continue;
            }
            $perm = $this->permOf($form);
            if (!$perm['owner']) {
                continue;
            }
            $ok = Db::name('form_submissions')
                ->where('form_id', $formId)->where('id', $id)
                ->whereNull('deleted_at')
                ->update(['status' => $status]);
            $updated += $ok ? 1 : 0;
        }
        return $this->ok(['updated' => $updated], '已更新 ' . $updated . ' 条');
    }

    /**
     * 提交内容编辑（管理员/创建者）：按表单字段定义校验后重写 data_json
     */
    public function edit(int $formId, int $id)
    {
        $form = $this->loadVisibleForm($formId);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        $perm = $this->permOf($form);
        if (!$perm['owner']) {
            return $this->fail('无权编辑该提交', 403);
        }
        $row = Db::name('form_submissions')
            ->where('form_id', $formId)->where('id', $id)
            ->whereNull('deleted_at')->find();
        if (!$row) {
            return $this->fail('记录不存在', 404);
        }
        $payload = $this->input();
        $data = is_array($payload['form'] ?? null) ? $payload['form'] : [];
        if (!$data) {
            return $this->fail('请填写要更新的内容');
        }
        $fields = FieldUtil::extractFields(json_decode((string)$form['fields_json'], true) ?: []);
        $values = FieldUtil::unwrapValues($data);
        $missing = [];
        foreach ($fields as $f) {
            if (!empty($f['required'])) {
                $v = $values[$f['field']] ?? '';
                if ($v === '' || $v === null || $v === []) {
                    $missing[] = $f['title'] ?: $f['field'];
                }
            }
        }
        if ($missing) {
            return $this->fail('请填写必填项：' . implode('、', $missing));
        }
        $clean = FieldUtil::sanitizeData($values, $fields);
        if (!$clean && $fields) {
            return $this->fail('提交内容为空');
        }
        Db::name('form_submissions')->where('id', $id)
            ->update(['data_json' => json_encode($clean, JSON_UNESCAPED_UNICODE)]);
        \app\logic\OpLog::write('data', '编辑提交', '表单#' . $formId . ' 提交#' . $id);
        return $this->ok(['data' => $clean], '已保存');
    }

    /**
     * 跨表单提交列表（「表单数据」聚合页）
     *
     * 管理员可见全部；成员可见「有协作授权 + 自己创建」的表单的提交，
     * 其中 own 范围授权的表单仅可见归属自己渠道的提交。
     */
    public function all()
    {
        $isAdmin = $this->isAdmin();
        $uid     = $this->uid();

        $formId  = (int)input('formId', 0);
        $page    = max(1, (int)input('page', 1));
        $size    = min(100, max(1, (int)input('size', 20)));
        $keyword = trim((string)input('keyword', ''));
        $start   = trim((string)input('start', ''));
        $end     = trim((string)input('end', ''));
        $status  = input('status', '');

        // 成员的可见表单集合：协作授权 + 自己创建的未删除表单
        $visibleIds = null;
        $fullIds    = [];
        $ownMap     = [];
        if (!$isAdmin) {
            $authIds = array_map('intval', array_keys($this->authForms()));
            $ownIds  = array_map('intval', Db::name('forms')
                ->where('user_id', $uid)->whereNull('deleted_at')->column('id'));
            $visibleIds = array_values(array_unique(array_merge($authIds, $ownIds)));
            $forms = Db::name('forms')->whereNull('deleted_at')
                ->whereIn('id', $visibleIds ?: [0])->field('id, user_id')->select()->toArray();
            foreach ($forms as $fr) {
                $perm = $this->permOf($fr);
                if ($perm['scope'] === 'all') {
                    $fullIds[] = (int)$fr['id'];
                } elseif ($perm['scope'] === 'own') {
                    $ownMap[(int)$fr['id']] = $this->ownChannelIds((int)$fr['id']);
                }
            }
            if ($formId > 0 && !in_array($formId, $visibleIds, true)) {
                return $this->ok(['list' => [], 'total' => 0, 'page' => $page, 'size' => $size, 'forms' => []]);
            }
        }

        $query = Db::name('form_submissions')->alias('s')
            ->leftJoin('forms f', 'f.id = s.form_id')
            ->whereNull('s.deleted_at');
        if (!$isAdmin) {
            // 成员可见范围：全量授权的表单 + own 授权表单名下自己的渠道
            $query->where(function ($q) use ($fullIds, $ownMap) {
                if ($fullIds) {
                    $q->whereIn('s.form_id', $fullIds);
                }
                foreach ($ownMap as $fid => $ids) {
                    $q->whereOr(function ($qq) use ($fid, $ids) {
                        $qq->where('s.form_id', $fid)->whereIn('s.channel_id', $ids ?: [0]);
                    });
                }
                if (!$fullIds && !$ownMap) {
                    $q->where('s.form_id', 0);
                }
            });
        }
        if ($formId > 0) {
            $query->where('s.form_id', $formId);
        }
        if ($keyword !== '') {
            $safe = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword);
            $query->where(function ($q) use ($safe) {
                $q->whereLike('s.data_json', '%' . $safe . '%')
                  ->whereOr('s.remark', 'like', '%' . $safe . '%')
                  ->whereOr('f.title', 'like', '%' . $safe . '%');
            });
        }
        if ($start !== '') {
            $query->whereTime('s.created_at', '>=', $start);
        }
        if ($end !== '' && strtotime($end) !== false) {
            $query->whereTime('s.created_at', '<=', date('Y-m-d 23:59:59', strtotime($end)));
        }
        if ($status !== '' && $status !== null) {
            $query->where('s.status', (int)$status);
        }

        $total = (clone $query)->count();
        $list  = $query->field('s.id, s.form_id, f.title as form_title, s.data_json, s.device, s.status, s.pay_status, s.order_no, s.flag, s.remark, s.created_at')
            ->page($page, $size)
            ->order('s.id', 'desc')
            ->select()
            ->toArray();

        $items = array_map(function ($row) {
            $data = json_decode((string)$row['data_json'], true) ?: [];
            // 摘要：前两个条目的值拼接（聚合列表不含字段定义，无法还原标题）
            $preview = [];
            foreach (array_slice($data, 0, 2, true) as $v) {
                if (is_array($v)) {
                    $v = implode('、', array_map(fn($x) => is_scalar($x) ? (string)$x : '', $v));
                }
                $preview[] = mb_substr((string)$v, 0, 20);
            }
            return [
                'id'        => (int)$row['id'],
                'formId'    => (int)$row['form_id'],
                'formTitle' => (string)$row['form_title'],
                'summary'   => implode(' ｜ ', array_filter($preview)),
                'device'    => $row['device'],
                'status'    => (int)$row['status'],
                'flag'      => (int)$row['flag'],
                'remark'    => (string)$row['remark'],
                'createdAt' => $row['created_at'],
            ];
        }, $list);

        // 表单筛选下拉：当前可见范围内的全部未删除表单
        $formsQuery = Db::name('forms')->whereNull('deleted_at')
            ->field('id, title, submit_count')->order('id', 'desc');
        if (!$isAdmin) {
            $formsQuery->whereIn('id', $visibleIds ?: [0]);
        }

        return $this->ok([
            'list'  => $items,
            'total' => $total,
            'page'  => $page,
            'size'  => $size,
            'forms' => $formsQuery->select()->toArray(),
        ]);
    }

    /**
     * 单条详情（独立详情页）
     */
    public function show(int $formId, int $id)
    {
        $form = $this->loadVisibleForm($formId);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        $perm = $this->permOf($form);
        $row = Db::name('form_submissions')
            ->where('form_id', $formId)->where('id', $id)
            ->whereNull('deleted_at')->find();
        if (!$row) {
            return $this->fail('记录不存在', 404);
        }
        // own 范围授权：只能看归属自己渠道的提交
        if (!$perm['owner'] && $perm['scope'] === 'own') {
            $ownIds = $this->ownChannelIds($formId);
            if (!in_array((int)($row['channel_id'] ?? 0), $ownIds, true)) {
                return $this->fail('记录不存在', 404);
            }
        }
        $chId = $row['channel_id'] !== null ? (int)$row['channel_id'] : 0;
        $channelName = $chId
            ? (string)(Db::name('form_channels')->where('id', $chId)->value('name') ?: '已删除渠道')
            : '';
        // 该提交之后表单结构是否有版本快照（有则说明提交来自旧版表单，字段可能对不上）
        $fromOldVersion = (bool)Db::name('form_versions')
            ->where('form_id', $formId)
            ->where('created_at', '>', (string)$row['created_at'])
            ->count();
        return $this->ok([
            'id'        => (int)$row['id'],
            'data'      => json_decode((string)$row['data_json'], true) ?: [],
            'ip'        => $row['ip'],
            'device'    => $row['device'],
            'status'    => (int)$row['status'],
            'flag'      => (int)$row['flag'],
            'remark'    => (string)$row['remark'],
            'channelId'   => $chId,
            'channelName' => $channelName,
            'userAgent' => (string)$row['user_agent'],
            'createdAt' => $row['created_at'],
            'oldVersion' => $fromOldVersion,
        ]);
    }

    /**
     * 标记：旗标/备注
     */
    public function mark(int $formId, int $id)
    {
        $form = $this->loadOwnedForm($formId);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        $row = Db::name('form_submissions')->where('form_id', $formId)->where('id', $id)
            ->whereNull('deleted_at')->find();
        if (!$row) {
            return $this->fail('记录不存在', 404);
        }
        $data   = $this->input();
        $update = [];
        if (isset($data['flag'])) {
            $flag = (int)$data['flag'];
            $update['flag'] = ($flag >= 0 && $flag <= 6) ? $flag : 0;
        }
        if (isset($data['remark'])) {
            $update['remark'] = mb_substr(trim((string)$data['remark']), 0, 500);
        }
        if (!$update) {
            return $this->fail('无更新内容');
        }
        Db::name('form_submissions')->where('id', $id)->update($update);
        $action = isset($data['flag'])
            ? ($update['flag'] > 0 ? '旗标标记' : '取消旗标')
            : '数据备注';
        \app\logic\OpLog::write('data', $action, '表单#' . $formId . ' 数据#' . $id);
        return $this->ok(['flag' => $update['flag'] ?? (int)$row['flag'], 'remark' => $update['remark'] ?? (string)$row['remark']], '已保存');
    }

    /**
     * 审核：批量 通过(1)/隐藏(0)
     */
    public function review(int $formId)
    {
        $form = $this->loadOwnedForm($formId);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        $data   = $this->input();
        $ids    = array_filter(array_map('intval', (array)($data['ids'] ?? [])));
        $status = (int)($data['status'] ?? 1) === 1 ? 1 : 0;
        if (!$ids) {
            return $this->fail('请选择要审核的数据');
        }
        $count = Db::name('form_submissions')->where('form_id', $formId)->whereIn('id', $ids)
            ->whereNull('deleted_at')->update(['status' => $status]);
        \app\logic\OpLog::write('data', $status === 1 ? '审核通过' : '审核隐藏', '表单#' . $formId . ' 共' . $count . '条');
        return $this->ok(['count' => $count], $status === 1 ? "已通过 {$count} 条" : "已隐藏 {$count} 条");
    }

    private static function needReview(array $form): bool
    {
        $settings = json_decode((string)$form['settings_json'], true) ?: [];
        return !empty($settings['needReview']);
    }

    /**
     * 删除（进回收站，可恢复）
     */
    public function delete(int $formId, int $id)
    {
        if (!$this->loadOwnedForm($formId)) {
            return $this->fail('表单不存在', 404);
        }
        $count = Db::name('form_submissions')->where('form_id', $formId)->where('id', $id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $this->now()]);
        if (!$count) {
            return $this->fail('记录不存在', 404);
        }
        \app\logic\OpLog::write('data', '删除提交数据', '表单#' . $formId . ' 数据#' . $id . '（移入回收站）');
        return $this->ok([], '已移入回收站');
    }

    public function batchDelete(int $formId)
    {
        if (!$this->loadOwnedForm($formId)) {
            return $this->fail('表单不存在', 404);
        }
        $data  = $this->input();
        $ids   = array_filter(array_map('intval', (array)($data['ids'] ?? [])));
        if (!$ids) {
            return $this->fail('请选择要删除的数据');
        }
        $count = Db::name('form_submissions')->where('form_id', $formId)->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $this->now()]);
        \app\logic\OpLog::write('data', '批量删除提交数据', '表单#' . $formId . ' 共' . $count . '条（移入回收站）');
        return $this->ok(['count' => $count], "已移入回收站 {$count} 条");
    }

    /**
     * 回收站还原
     */
    public function restore(int $formId)
    {
        if (!$this->loadOwnedForm($formId)) {
            return $this->fail('表单不存在', 404);
        }
        $ids = array_filter(array_map('intval', (array)($this->input()['ids'] ?? [])));
        if (!$ids) {
            return $this->fail('请选择要还原的数据');
        }
        $count = Db::name('form_submissions')->where('form_id', $formId)->whereIn('id', $ids)
            ->whereNotNull('deleted_at')
            ->update(['deleted_at' => null]);
        \app\logic\OpLog::write('data', '还原提交数据', '表单#' . $formId . ' 共' . $count . '条');
        return $this->ok(['count' => $count], "已还原 {$count} 条");
    }

    /**
     * 回收站彻底删除（物理删除，扣回提交数）
     */
    public function purge(int $formId)
    {
        if (!$this->loadOwnedForm($formId)) {
            return $this->fail('表单不存在', 404);
        }
        $ids = array_filter(array_map('intval', (array)($this->input()['ids'] ?? [])));
        $query = Db::name('form_submissions')->where('form_id', $formId)->whereNotNull('deleted_at');
        if ($ids) {
            $query->whereIn('id', $ids);
        }
        $count = $query->count();
        if (!$count) {
            return $this->fail('回收站中没有可删除的数据', 404);
        }
        $query->delete();
        Db::name('forms')->where('id', $formId)->dec('submit_count', $count)->update();
        \app\logic\OpLog::write('data', '彻底删除提交数据', '表单#' . $formId . ' 共' . $count . '条（不可恢复）');
        return $this->ok(['count' => $count], "已彻底删除 {$count} 条");
    }

    /**
     * 导出 CSV
     */
    public function export(int $formId)
    {
        set_time_limit(120);
        $form = $this->loadVisibleForm($formId);
        if (!$form) {
            return $this->fail('表单不存在', 404);
        }
        $perm = $this->permOf($form);
        if (!$perm['canExport']) {
            return $this->fail('无导出权限', 403);
        }
        $fields = FieldUtil::extractFields(json_decode((string)$form['fields_json'], true) ?: []);
        $safe   = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim((string)input('keyword', '')));
        $query  = Db::name('form_submissions')->where('form_id', $formId)
            ->where('status', 1)->whereNull('deleted_at');
        // own 范围授权：仅导出归属自己渠道的提交
        if (!$perm['owner'] && $perm['scope'] === 'own') {
            $ownIds = $this->ownChannelIds($formId);
            $query->whereIn('channel_id', $ownIds ?: [0]);
        }
        if ($safe !== '') {
            $query->whereLike('data_json', '%' . $safe . '%');
        }
        $flag  = input('flag', '');
        $field = trim((string)input('field', ''));
        $value = trim((string)input('value', ''));
        $channelFilter = input('channel_id', '');
        if ($channelFilter !== '' && $channelFilter !== null) {
            $query->where('channel_id', (int)$channelFilter);
        }
        if ($flag !== '' && $flag !== null) {
            $query->where('flag', (int)$flag);
        }
        if ($field !== '' && $value !== '' && self::validField($fields, $field)) {
            self::applyJsonFieldFilter($query, $field, $value);
        }
        $start = trim((string)input('start', ''));
        $end   = trim((string)input('end', ''));
        if ($start !== '') {
            $query->whereTime('created_at', '>=', $start);
        }
        if ($end !== '' && strtotime($end) !== false) {
            $query->whereTime('created_at', '<=', date('Y-m-d 23:59:59', strtotime($end)));
        }

        // 导出脱敏：手机号/身份证号中间打码（对外分享场景）
        $masked = (int)input('mask', 0) === 1;
        $filename = 'export_' . $form['slug'] . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // Excel 中文 BOM
        $headers = ['提交ID', '提交时间', '渠道', 'IP', '设备'];
        foreach ($fields as $f) {
            $headers[] = self::csvCell($f['title'] !== '' ? $f['title'] : $f['field']);
        }
        fputcsv($out, $headers);

        $channelNames = Db::name('form_channels')->where('form_id', $formId)->column('name', 'id');
        $lastId = 0;
        $limit  = 1000;
        while (true) {
            $rows = (clone $query)->where('id', '>', $lastId)->order('id', 'asc')->limit($limit)->select()->toArray();
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $data   = json_decode((string)$row['data_json'], true) ?: [];
                $chId   = $row['channel_id'] !== null ? (int)$row['channel_id'] : 0;
                $line   = [
                    $row['id'],
                    $row['created_at'],
                    self::csvCell($chId ? (string)($channelNames[$chId] ?? '已删除渠道') : '直接访问'),
                    $row['ip'],
                    $row['device'],
                ];
                foreach ($fields as $f) {
                    $v = $data[$f['field']] ?? '';
                    if (is_array($v)) {
                        $parts = [];
                        foreach ($v as $item) {
                            if (is_array($item)) {
                                $parts[] = $item['name'] ?? $item['url'] ?? json_encode($item, JSON_UNESCAPED_UNICODE);
                            } else {
                                $parts[] = (string)$item;
                            }
                        }
                        $v = implode('、', $parts);
                    }
                    $v = (string)$v;
                    // 导出脱敏：mask=1 时手机号/身份证号中间打码
                    if ($masked) {
                        $v = preg_replace('/^(1\d{2})\d{4}(\d{4})$/', '$1****$2', $v);
                        $v = preg_replace('/^(\d{6})\d{8}(\w{4})$/', '$1********$2', $v);
                    }
                    $line[] = self::csvCell($v);
                }
                fputcsv($out, $line);
            }
            if (count($rows) < $limit) {
                break;
            }
        }
        fclose($out);
        exit;
    }

    /**
     * 跨表单聚合导出（表单数据页）：与 all() 同一套筛选，
     * 列为汇总口径（时间/表单/内容摘要/设备/状态），适配多表单混排场景
     */
    public function exportAll()
    {
        $isAdmin = $this->isAdmin();
        $uid     = $this->uid();
        $formId  = (int)input('formId', 0);
        $keyword = trim((string)input('keyword', ''));
        $start   = trim((string)input('start', ''));
        $end     = trim((string)input('end', ''));
        $status  = input('status', '');

        $visibleIds = null;
        $fullIds    = [];
        $ownMap     = [];
        if (!$isAdmin) {
            $authIds = array_map('intval', array_keys($this->authForms()));
            $ownIds  = array_map('intval', Db::name('forms')
                ->where('user_id', $uid)->whereNull('deleted_at')->column('id'));
            $visibleIds = array_values(array_unique(array_merge($authIds, $ownIds)));
            $forms = Db::name('forms')->whereNull('deleted_at')
                ->whereIn('id', $visibleIds ?: [0])->field('id, user_id')->select()->toArray();
            foreach ($forms as $fr) {
                $perm = $this->permOf($fr);
                if ($perm['scope'] === 'all') {
                    $fullIds[] = (int)$fr['id'];
                } elseif ($perm['scope'] === 'own') {
                    $ownMap[(int)$fr['id']] = $this->ownChannelIds((int)$fr['id']);
                }
            }
        }

        $query = Db::name('form_submissions')->alias('s')
            ->leftJoin('forms f', 'f.id = s.form_id')
            ->whereNull('s.deleted_at');
        if (!$isAdmin) {
            $query->where(function ($q) use ($fullIds, $ownMap) {
                if ($fullIds) {
                    $q->whereIn('s.form_id', $fullIds);
                }
                foreach ($ownMap as $fid => $ids) {
                    $q->whereOr(function ($qq) use ($fid, $ids) {
                        $qq->where('s.form_id', $fid)->whereIn('s.channel_id', $ids ?: [0]);
                    });
                }
                if (!$fullIds && !$ownMap) {
                    $q->where('s.form_id', 0);
                }
            });
        }
        if ($formId > 0) {
            $query->where('s.form_id', $formId);
        }
        if ($keyword !== '') {
            $safe = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $keyword);
            $query->where(function ($q) use ($safe) {
                $q->whereLike('s.data_json', '%' . $safe . '%')
                  ->whereOr('s.remark', 'like', '%' . $safe . '%')
                  ->whereOr('f.title', 'like', '%' . $safe . '%');
            });
        }
        if ($start !== '') {
            $query->whereTime('s.created_at', '>=', $start);
        }
        if ($end !== '' && strtotime($end) !== false) {
            $query->whereTime('s.created_at', '<=', date('Y-m-d 23:59:59', strtotime($end)));
        }
        if ($status !== '' && $status !== null) {
            $query->where('s.status', (int)$status);
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="all_data_' . date('Ymd_His') . '.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['提交ID', '表单', '提交时间', '设备', '状态', '内容摘要']);

        $lastId = 0;
        $count  = 0;
        while (true) {
            $q2 = (clone $query)->field('s.id, s.form_id, f.title as form_title, s.data_json, s.device, s.status, s.created_at')
                ->order('s.id', 'asc');
            if ($lastId) {
                $q2->where('s.id', '>', $lastId);
            }
            $rows = $q2->limit(1000)->select()->toArray();
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $data = json_decode((string)$row['data_json'], true) ?: [];
                $preview = [];
                foreach (array_slice($data, 0, 2, true) as $v) {
                    if (is_array($v)) {
                        $v = implode('、', array_map(fn($x) => is_scalar($x) ? (string)$x : '', $v));
                    }
                    $preview[] = mb_substr((string)$v, 0, 20);
                }
                $line = [
                    $row['id'],
                    (string)$row['form_title'],
                    (string)$row['created_at'],
                    $row['device'] === 'mobile' ? '手机' : '电脑',
                    (int)$row['status'] === 1 ? '正常' : '待审核',
                    implode(' ｜ ', array_filter($preview)),
                ];
                fputcsv($out, $line);
                $count++;
            }
        }
        fclose($out);
        exit;
    }

    /**
     * CSV 单元格防公式注入：= + - @ 开头或含制表符的值前置单引号
     */
    private static function csvCell(string $v): string
    {
        // 纯数字（含负数/小数/科学计数）不是公式，直接输出避免污染数值类型
        if (is_numeric($v)) {
            return $v;
        }
        if ($v !== '' && (in_array($v[0], ['=', '+', '-', '@'], true) || strpbrk($v, "\t\r") !== false)) {
            return "'" . $v;
        }
        return $v;
    }

    /**
     * 概览统计
     */
    public function dashboard()
    {
        // 数据保留策略 / 孤儿附件 / 每日汇总：每日首个后台请求触发一次（缓存节流）
        try {
            \app\logic\Retention::run();
        } catch (\Throwable $e) {
            \think\facade\Log::write('[retention] 后台触发清理失败：' . $e->getMessage(), 'notice');
        }
        $fq = $this->formQuery();
        $formTotal = (clone $fq)->count();
        $activeTotal = (clone $fq)->where('status', 1)->count();

        // 成员的数据范围（用 whereIn 避免 join 字段歧义）：
        // 全量口径 = 自己创建的表单 + all 范围授权的表单；
        // own 范围授权的表单仅计入归属自己渠道的提交
        $fullFormIds = [];
        $ownScopeFormIds = [];
        $myChannelIds = [];
        if (!$this->isAdmin()) {
            $fullFormIds = array_map('intval', Db::name('forms')
                ->where('user_id', $this->uid())->whereNull('deleted_at')->column('id'));
            foreach ($this->authForms() as $formId => $b) {
                if ($b['data_scope'] === 'all') {
                    $fullFormIds[] = (int)$formId;
                } else {
                    $ownScopeFormIds[] = (int)$formId;
                }
            }
            $myChannelIds = array_map('intval', Db::name('form_channels')
                ->where('member_id', $this->uid())->whereNull('deleted_at')->column('id'));
        }
        $scope = function () use ($fullFormIds, $ownScopeFormIds, $myChannelIds) {
            $q = Db::name('form_submissions')->whereNull('deleted_at');
            if ($this->isAdmin()) {
                return $q;
            }
            if (!$fullFormIds && !$ownScopeFormIds) {
                return $q->where('id', 0);
            }
            $q->where(function ($qq) use ($fullFormIds, $ownScopeFormIds, $myChannelIds) {
                if ($fullFormIds) {
                    $qq->whereIn('form_id', $fullFormIds);
                }
                if ($ownScopeFormIds) {
                    $cond = function ($q2) use ($ownScopeFormIds, $myChannelIds) {
                        $q2->whereIn('form_id', $ownScopeFormIds)
                            ->whereIn('channel_id', $myChannelIds ?: [0]);
                    };
                    $fullFormIds ? $qq->whereOr($cond) : $qq->where($cond);
                }
            });
            return $q;
        };

        $submitTotal = (clone $scope())->count();
        $today = (clone $scope())->whereTime('created_at', 'today')->count();
        $yesterday = (clone $scope())->whereTime('created_at', 'yesterday')->count();
        // 本月 / 上月：卡片用「本月 vs 上月」给环比语境。
        // 上月下界不能用 strtotime('-1 month')：月尾（如 3 月 31 日）会溢出
        // 塌缩到本月 1 号，形成 >= X AND < X 的空区间，上月统计恒 0。
        // 先锚定到本月 1 号再减 1 天取上月末，天然规避溢出。
        $monthStart = date('Y-m-01 00:00:00');
        $prevMonthEnd = date('Y-m-d 23:59:59', strtotime($monthStart . ' -1 day'));
        $prevMonthStart = date('Y-m-01 00:00:00', strtotime($prevMonthEnd));
        $monthTotal = (clone $scope())->whereTime('created_at', 'month')->count();
        $prevMonthTotal = (clone $scope())
            ->whereTime('created_at', '>=', $prevMonthStart)
            ->whereTime('created_at', '<=', $prevMonthEnd)
            ->count();

        // 近 30 日趋势（供前端切换 7/30 天视图）
        $trendRows30 = (clone $scope())
            ->whereTime('created_at', '>=', date('Y-m-d 00:00:00', strtotime('-29 days')))
            ->field("DATE(created_at) as d, COUNT(*) as c")
            ->group('d')
            ->select()->toArray();
        $trendMap = [];
        foreach ($trendRows30 as $r) {
            $trendMap[$r['d']] = (int)$r['c'];
        }
        $trend = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $trend[] = ['date' => $d, 'count' => $trendMap[$d] ?? 0];
        }
        $last7 = array_sum(array_slice(array_column($trend, 'count'), -7));
        $prev7 = array_sum(array_slice(array_column($trend, 'count'), -14, 7));

        // 待审核数（成员视角为自己的表单）
        $pending = (clone $scope())->where('status', 0)->count();

        // 运行天数与日均提交
        $firstForm = (clone $fq)->order('created_at', 'asc')->value('created_at');
        $days = $firstForm ? max(1, (int)ceil((time() - strtotime((string)$firstForm)) / 86400)) : 1;
        $avgPerDay = round($submitTotal / $days, 1);

        // 上传附件数（成员口径：全量数据范围的表单附件；素材库附件不对成员开放）
        if ($this->isAdmin()) {
            $uploadTotal = Db::name('uploads')->count();
        } elseif ($fullFormIds) {
            $uploadTotal = Db::name('uploads')->whereIn('form_id', $fullFormIds)->count();
        } else {
            $uploadTotal = 0;
        }

        // 最新提交
        $recent = Db::name('form_submissions')->alias('s')
            ->join('forms f', 'f.id = s.form_id')
            ->where('s.status', 1)
            ->whereNull('s.deleted_at')
            ->whereNull('f.deleted_at')
            ->when(!$this->isAdmin(), function ($q) use ($fullFormIds, $ownScopeFormIds, $myChannelIds) {
                if (!$fullFormIds && !$ownScopeFormIds) {
                    $q->where('f.id', 0);
                    return;
                }
                $q->where(function ($qq) use ($fullFormIds, $ownScopeFormIds, $myChannelIds) {
                    if ($fullFormIds) {
                        $qq->whereIn('f.id', $fullFormIds);
                    }
                    if ($ownScopeFormIds) {
                        $cond = function ($q2) use ($ownScopeFormIds, $myChannelIds) {
                            $q2->whereIn('f.id', $ownScopeFormIds)
                                ->whereIn('s.channel_id', $myChannelIds ?: [0]);
                        };
                        $fullFormIds ? $qq->whereOr($cond) : $qq->where($cond);
                    }
                });
            })
            ->order('s.id', 'desc')
            ->limit(8)
            ->field('s.id, s.form_id, f.title, s.data_json, s.created_at')
            ->select()->toArray();
        $recentList = [];
        foreach ($recent as $r) {
            $data = json_decode((string)$r['data_json'], true) ?: [];
            $preview = [];
            foreach (array_slice($data, 0, 3, true) as $k => $v) {
                if (is_array($v)) {
                    $v = implode('、', array_map(fn($x) => is_scalar($x) ? (string)$x : '', $v));
                }
                $preview[] = (string)$v;
            }
            $recentList[] = [
                'id'        => (int)$r['id'],
                'formId'    => (int)$r['form_id'],
                'formTitle' => $r['title'],
                'preview'   => implode(' | ', array_filter($preview)),
                'createdAt' => $r['created_at'],
            ];
        }

        // 提交最多的表单
        $topForms = $this->formQuery()
            ->order('submit_count', 'desc')->limit(5)
            ->field('id, title, slug, status, submit_count')
            ->select()->toArray();
        foreach ($topForms as &$t) {
            $t['shareUrl'] = FormApi::shareUrl($t['slug']);
        }
        unset($t);

        // ---------- 页面统计（与表单同样的成员数据隔离） ----------
        $pq = Db::name('pages')->whereNull('deleted_at');
        if (!$this->isAdmin()) {
            $pq->where('user_id', $this->uid());
        }
        $pageTotal = (clone $pq)->count();
        $pagePublished = (clone $pq)->where('status', 1)->count();
        $pageViews = (int)(clone $pq)->sum('view_count');
        // 浏览最多的页面（只展示已发布——草稿和下线页的浏览量没有展示意义）
        $topPages = (clone $pq)->where('status', 1)
            ->order('view_count', 'desc')->limit(5)
            ->field('id, title, slug, view_count')
            ->select()->toArray();
        foreach ($topPages as &$tp) {
            $tp['shareUrl'] = PageUtil::shareUrl($tp['slug']);
        }
        unset($tp);

        // ---------- 引流统计（活码/短链，成员数据隔离与表单/页面一致） ----------
        $prq = Db::name('promos')->whereNull('deleted_at');
        if (!$this->isAdmin()) {
            $prq->where('user_id', $this->uid());
        }
        $promoTotal   = (clone $prq)->count();
        $promoActive  = (clone $prq)->where('status', 1)->count();
        $qrcodeTotal  = (clone $prq)->where('type', 'qrcode')->count();
        $shortTotal   = (clone $prq)->where('type', 'short')->count();
        $promoClicks  = (int)(clone $prq)->sum('click_count');

        // 今日扫码/点击：日志按可见推广位过滤（成员只能看到自己的）
        $promoIds = (clone $prq)->column('id');
        $promoToday = 0;
        if ($promoIds) {
            $promoToday = Db::name('promo_logs')
                ->whereIn('promo_id', $promoIds)
                ->whereTime('created_at', 'today')->count();
        }

        // 扫码/点击最多的推广位（启用中的才有展示意义）
        $topPromos = (clone $prq)->where('status', 1)
            ->order('click_count', 'desc')->limit(5)
            ->field('id, name, type, code, domain, click_count')
            ->select()->toArray();
        foreach ($topPromos as &$rp) {
            $rp['shareUrl'] = PromoUtil::buildShareUrl($rp['code'], $rp['domain']);
        }
        unset($rp);

        return $this->ok([
            'formTotal'   => $formTotal,
            'activeTotal' => $activeTotal,
            'submitTotal' => $submitTotal,
            'today'       => $today,
            'yesterday'   => $yesterday,
            'monthTotal'  => $monthTotal,
            'prevMonthTotal' => $prevMonthTotal,
            'last7'       => $last7,
            'prev7'       => $prev7,
            'pending'     => $pending,
            'avgPerDay'   => $avgPerDay,
            'uploadTotal' => $uploadTotal,
            'trend'       => $trend,
            'recent'      => $recentList,
            'topForms'    => $topForms,
            'pageTotal'     => $pageTotal,
            'pagePublished' => $pagePublished,
            'pageViews'     => $pageViews,
            'topPages'      => $topPages,
            'promoTotal'    => $promoTotal,
            'promoActive'   => $promoActive,
            'qrcodeTotal'   => $qrcodeTotal,
            'shortTotal'    => $shortTotal,
            'promoClicks'   => $promoClicks,
            'promoToday'    => $promoToday,
            'topPromos'     => $topPromos,
        ]);
    }

    private static function validField(array $fields, string $field): bool
    {
        foreach ($fields as $f) {
            if ($f['field'] === $field) {
                return true;
            }
        }
        return false;
    }

    /**
     * 按 JSON 字段做模糊筛选
     *
     * JSON 路径无法参数化绑定，只能拼接，因此对字段名执行严格白名单：
     * 仅允许字母、数字、下划线与短横线，且长度受限。字段名来自表单定义，
     * 而表单定义由任意已登录用户（含成员角色）创建，若不校验，
     * 构造形如 x') UNION SELECT password FROM of_users-- 的字段名即可闭合
     * SQL 字符串完成注入，进而读取管理员密码哈希。
     *
     * 不满足白名单时直接忽略该筛选条件，而不是拼接。
     */
    private static function applyJsonFieldFilter($query, string $field, string $value): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $field)) {
            return;
        }
        // 转义 LIKE 通配符，参数化绑定避免注入（必须先转反斜杠再转通配符）
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value) . '%';
        $query->whereRaw(
            "JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.\"" . $field . "\"')) LIKE ?",
            [$like]
        );
    }
}
