<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount, h } from "vue";
import { useRouter } from "vue-router";
import { message } from "@/utils/message";
import { ElMessageBox } from "element-plus";
import {
  getForms,
  getForm,
  createForm,
  copyFormApi,
  deleteForm,
  setFormStatus,
  restoreForm,
  purgeForm,
  getChannels,
  createChannel,
  updateChannel,
  setChannelStatus,
  deleteChannel,
  getFormMembers,
  bindFormMember,
  updateFormMember,
  unbindFormMember
} from "@/api/ownform";
import { getMe } from "@/api/user";
import { TEMPLATES } from "@/utils/formRule";
import FormStats from "@/views/stats/index.vue";

// 模板列表（模板使用）
const templates = TEMPLATES;
import { copyWithTip } from "@/utils/clipboard";
import {
  Search,
  Plus,
  Edit,
  Document,
  DataAnalysis,
  Share,
  Delete,
  Iphone,
  Flag,
  Star,
  ChatDotRound,
  Suitcase,
  Calendar,
  Service,
  User,
  ArrowDown,
  DocumentCopy
} from "@element-plus/icons-vue";

const TPL_ICONS: Record<string, any> = {
  Document,
  Iphone,
  Flag,
  Star,
  ChatDotRound,
  Suitcase,
  Calendar,
  Service
};

defineOptions({
  name: "FormsList"
});

const router = useRouter();

/* 统计弹窗：不跳转独立页面，直接在列表弹窗内展示 */
const statsVisible = ref(false);
const statsFormId = ref("");
const statsTitle = ref("");
const copyingId = ref(0);
async function copyForm(row: any) {
  try {
    copyingId.value = row.id;
    const d = await copyFormApi(row.id);
    message("副本已创建：\u300C" + (d.title || row.title + " - 副本") + "\u300D", { type: "success" });
    await load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  } finally {
    copyingId.value = 0;
  }
}
function openStats(row: any) {
  statsFormId.value = String(row.id);
  statsTitle.value = row.title || "";
  statsVisible.value = true;
}
// 管理员视图：先读 localStorage 快速渲染，再用 getMe 实时校验（防旧会话无 roles 字段）
const isAdminView = ref(
  (() => {
    try {
      return (
        JSON.parse(localStorage.getItem("user-info") || "{}").roles?.[0] ===
        "admin"
      );
    } catch {
      return false;
    }
  })()
);
onMounted(async () => {
  try {
    const me = await getMe();
    isAdminView.value = me?.role === "admin";
  } catch {
    /* 保持本地判断 */
  }
  load(1);
});
/** 成员可被关闭"创建表单"能力：permissions 数组存在且不含 form:create 即被关闭 */
const canCreate = (() => {
  try {
    const p = JSON.parse(localStorage.getItem("user-info") || "{}")
      .permissions as string[] | undefined;
    return !p || !p.length || p.includes("form:create");
  } catch {
    return true;
  }
})();
const loading = ref(false);
const list = ref<any[]>([]);
const total = ref(0);
const query = reactive({
  page: 1,
  size: 20,
  keyword: "",
  status: "" as number | "",
  deleted: 0
});

const fmtTime = (s: string) =>
  s ? String(s).replace("T", " ").slice(0, 16) : "-";
const statusTag = (s: number) =>
  s === 1 ? "success" : s === 2 ? "warning" : "info";
const statusText = (s: number) =>
  s === 1 ? "收集中" : s === 2 ? "已关闭" : "草稿";

/** 被授权的协作表单（成员视角）：无编辑权，按钮按授权能力显示 */
const isAuthRow = (row: any) => !!row.auth;
const canEditRow = (row: any) => isAdminView.value || !isAuthRow(row);

/** 「更多」下拉命令分发 */
function moreCmd(cmd: string, row: any) {
  if (cmd === "stats") openStats(row);
  else if (cmd === "copy") copyForm(row);
  else if (cmd === "members") openMembers(row);
  else if (cmd === "delete") remove(row);
}

async function load(page?: number) {
  if (page) query.page = page;
  loading.value = true;
  try {
    const params: any = {
      page: query.page,
      size: query.size,
      keyword: query.keyword,
      deleted: query.deleted
    };
    if (query.status !== "" && !query.deleted) params.status = query.status;
    const d = await getForms(params);
    list.value = d.list;
    total.value = d.total;
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  loading.value = false;
}

/* 新建表单弹窗 */
const pickTpl = ref("blank");
const newTitle = ref("");
const createVisible = ref(false);
const creating = ref(false);

function openCreate() {
  pickTpl.value = "blank";
  newTitle.value = "";
  createVisible.value = true;
}

/**
 * 点击表单标题 → 复制分享链接
 *
 * 标题是列表里最醒目的元素，把它作为「拿链接去分享」的快捷入口；
 * 进设计器的入口保留在操作列的「设计」按钮。
 * 回收站里的表单没有可分享的地址（shareUrl 为空串），点击时提示先发布。
 * own 范围的协作成员看不到直接提交的数据，引导走自己的渠道链接。
 */
function copyRowLink(row: any) {
  if (!row.shareUrl) {
    return message("该表单还没有分享链接，请先发布", { type: "warning" });
  }
  if (row.auth?.scope === "own") {
    return message("直接链接的提交不属于你，请在「分享」里用你的渠道链接", {
      type: "warning"
    });
  }
  copyWithTip(row.shareUrl, "链接已复制");
}

/* 回收站 */
function toggleDeleted() {
  query.deleted = query.deleted ? 0 : 1;
  load(1);
}

async function restore(row: any) {
  try {
    await restoreForm(row.id);
    message("已恢复为草稿", { type: "success" });
    load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

async function purge(row: any) {
  const ok = await ElMessageBox.confirm(
    `彻底删除「${row.title}」？其全部提交数据与附件记录将一并删除，且不可恢复！`,
    "彻底删除",
    {
      confirmButtonText: "彻底删除",
      cancelButtonText: "取消",
      type: "error",
      confirmButtonClass: "el-button--danger"
    }
  )
    .then(() => true)
    .catch(() => false);
  if (!ok) return;
  try {
    await purgeForm(row.id);
    message("已彻底删除", { type: "success" });
    load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

async function create() {
  const tpl = TEMPLATES.find(t => t.key === pickTpl.value);
  const title = (newTitle.value || "").trim() || tpl.name;
  creating.value = true;
  try {
    const d = await createForm({
      title,
      fields: JSON.stringify(tpl.fields),
      settings: {}
    });
    message("创建成功", { type: "success" });
    createVisible.value = false;
    router.push("/design/" + d.id);
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  creating.value = false;
}

/* 状态开关 */
const switchingIds = ref<Set<number>>(new Set());
async function toggleStatus(row: any, on: boolean) {
  const target = on ? 1 : 2;
  switchingIds.value.add(row.id);
  try {
    await setFormStatus(row.id, target);
    row.status = target;
    message(on ? "已开始收集" : "已停止收集", { type: "success" });
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  switchingIds.value.delete(row.id);
}

/* 删除 */
async function remove(row: any) {
  const confirmed = await ElMessageBox.confirm(
    `确定删除表单「${row.title}」吗？其收集的数据将保留但不再展示在前台。`,
    "删除表单",
    { confirmButtonText: "确定", cancelButtonText: "取消", type: "warning" }
  )
    .then(() => true)
    .catch(() => false);
  if (!confirmed) return;
  try {
    await deleteForm(row.id);
    message("已删除", { type: "success" });
    load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

/* ==================== 分享与渠道抽屉 ==================== */
const shareVisible = ref(false);
const shareRow = ref<any>(null);
const qrEl = ref<HTMLElement>();
const shareTab = ref("base");

function openShare(row: any) {
  shareRow.value = row;
  // own 范围的协作成员：直接链接的数据不属于他，默认落在渠道页签
  shareTab.value = row.auth?.scope === "own" ? "channels" : "base";
  shareVisible.value = true;
  if (qrTimer) clearTimeout(qrTimer);
  qrTimer = setTimeout(renderQr, 100);
  if (canManageChannels(row)) loadChannels();
}

/** 渠道可见：管理员/创建者全部，own+可建渠道成员仅自己的 */
const canManageChannels = (row: any) =>
  isAdminView.value || !row.auth || row.auth.canChannel;

const channels = ref<any[]>([]);
const channelsLoading = ref(false);
const canAssign = ref(false);
const memberOptions = ref<any[]>([]);
const newChannelName = ref("");
const newChannelMember = ref<number | 0>(0);
const addingChannel = ref(false);

async function loadChannels() {
  if (!shareRow.value) return;
  channelsLoading.value = true;
  try {
    const d = await getChannels(shareRow.value.id);
    channels.value = d.list;
    canAssign.value = !!d.canAssign;
    memberOptions.value = d.members || [];
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  channelsLoading.value = false;
}

async function addChannel() {
  const name = (newChannelName.value || "").trim();
  if (!name) return message("请输入渠道名称", { type: "warning" });
  addingChannel.value = true;
  try {
    await createChannel(shareRow.value.id, {
      name,
      member_id: newChannelMember.value || 0
    });
    message("渠道已创建", { type: "success" });
    newChannelName.value = "";
    newChannelMember.value = 0;
    loadChannels();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  addingChannel.value = false;
}

async function renameChannel(row: any) {
  try {
    const { value } = await ElMessageBox.prompt(
      "修改渠道名称：",
      "重命名渠道",
      {
        confirmButtonText: "确定",
        cancelButtonText: "取消",
        inputValue: row.name,
        inputPattern: /\S+/,
        inputErrorMessage: "名称不能为空"
      }
    );
    await updateChannel(row.id, { name: value.trim() });
    row.name = value.trim();
    message("已保存", { type: "success" });
  } catch (e: any) {
    if (e !== "cancel" && e?.message) message(e.message, { type: "error" });
  }
}

async function toggleChannel(row: any, on: boolean) {
  try {
    await setChannelStatus(row.id, on ? 1 : 0);
    row.status = on ? 1 : 0;
    message(on ? "已启用" : "已停用", { type: "success" });
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

async function removeChannel(row: any) {
  const ok = await ElMessageBox.confirm(
    `删除渠道「${row.name}」？删除后该链接不再统计新提交（历史数据保留），不可恢复。`,
    "删除渠道",
    { confirmButtonText: "确定", cancelButtonText: "取消", type: "warning" }
  )
    .then(() => true)
    .catch(() => false);
  if (!ok) return;
  try {
    await deleteChannel(row.id);
    message("已删除", { type: "success" });
    loadChannels();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

/* 二维码：默认链接与渠道共用一个弹窗 */
const qrDialogVisible = ref(false);
const qrDialogUrl = ref("");

/* 渠道定时上下线：popover 编辑与保存 */
const schedulePopId = ref(0);
const scheduleFrom = ref("");
const scheduleUntil = ref("");
function initSchedule(row: any) {
  scheduleFrom.value = row.onlineFrom || "";
  scheduleUntil.value = row.onlineUntil || "";
}
function scheduleState(row: any): string {
  const now = Date.now();
  const from = row.onlineFrom ? Date.parse(row.onlineFrom) : 0;
  const until = row.onlineUntil ? Date.parse(row.onlineUntil) : Infinity;
  if (from && now < from) return "未开始";
  if (until !== Infinity && now > until) return "已结束";
  return from || until !== Infinity ? "推广中" : "";
}
async function saveSchedule(row: any) {
  try {
    const d = await updateChannel(row.id, {
      online_from: scheduleFrom.value,
      online_until: scheduleUntil.value
    });
    row.onlineFrom = d.onlineFrom || "";
    row.onlineUntil = d.onlineUntil || "";
    message("定时上下线已保存", { type: "success" });
    schedulePopId.value = 0;
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}
const qrDialogName = ref("渠道二维码");
const qrDialogEl = ref<HTMLElement>();

async function showQr(url: string, name = "渠道二维码") {
  qrDialogName.value = name;
  qrDialogUrl.value = url;
  qrDialogVisible.value = true;
  setTimeout(renderQrDialog, 100);
}

/** 加载本地 qrcode UMD 库（/static/vendor/qrcode.min.js） */
function loadQrcodeLib(): Promise<void> {
  return new Promise(resolve => {
    if ((window as any).QRCode) return resolve();
    const s = document.createElement("script");
    s.src = "/static/vendor/qrcode.min.js";
    s.onload = () => resolve();
    s.onerror = () => resolve();
    document.head.appendChild(s);
  });
}

async function renderQr() {
  if (!qrEl.value || !shareRow.value) return;
  qrEl.value.innerHTML = "";
  await loadQrcodeLib();
  try {
    if (!(window as any).QRCode) throw new Error("lib missing");
    new (window as any).QRCode(qrEl.value, {
      text: shareRow.value.shareUrl,
      width: 160,
      height: 160,
      correctLevel: (window as any).QRCode.CorrectLevel.M
    });
  } catch (e) {
    qrEl.value.innerHTML =
      '<div style="color:#909399;font-size:12px;padding:20px">二维码生成失败</div>';
  }
}

async function renderQrDialog() {
  if (!qrDialogEl.value) return;
  qrDialogEl.value.innerHTML = "";
  await loadQrcodeLib();
  try {
    if (!(window as any).QRCode) throw new Error("lib missing");
    new (window as any).QRCode(qrDialogEl.value, {
      text: qrDialogUrl.value,
      width: 180,
      height: 180,
      correctLevel: (window as any).QRCode.CorrectLevel.M
    });
  } catch (e) {
    qrDialogEl.value.innerHTML =
      '<div style="color:#909399;font-size:12px;padding:20px">二维码生成失败</div>';
  }
}

function copyShare() {
  copyWithTip(shareRow.value.shareUrl, "链接已复制");
}

/** 取二维码 canvas（未生成返回 null） */
function qrCanvasOf(el?: HTMLElement): HTMLCanvasElement | null {
  return el ? el.querySelector("canvas") : null;
}

/** 复制二维码图片到剪贴板（需 https 或 localhost） */
async function copyQrImage(el?: HTMLElement) {
  const canvas = qrCanvasOf(el);
  if (!canvas) return message("二维码尚未生成", { type: "warning" });
  try {
    const blob = await new Promise<Blob | null>(r => canvas.toBlob(r, "image/png"));
    if (!blob) throw new Error("blob");
    await navigator.clipboard.write([new ClipboardItem({ "image/png": blob })]);
    message("二维码图片已复制到剪贴板", { type: "success" });
  } catch (e) {
    message("复制失败，请改用「保存 PNG」", { type: "error" });
  }
}

/** 保存二维码为 PNG */
function saveQrImage(el?: HTMLElement, name = "二维码") {
  const canvas = qrCanvasOf(el);
  if (!canvas) return message("二维码尚未生成", { type: "warning" });
  const a = document.createElement("a");
  a.href = canvas.toDataURL("image/png");
  a.download = name + ".png";
  a.click();
  message("已保存 " + a.download, { type: "success" });
}

// 二维码渲染的延时句柄：对话框关闭后回调仍会触发，必须在卸载时清理
let qrTimer: ReturnType<typeof setTimeout> | null = null;

onBeforeUnmount(() => {
  if (qrTimer) {
    clearTimeout(qrTimer);
    qrTimer = null;
  }
});

/* ==================== 协作成员抽屉 ==================== */
const membersVisible = ref(false);
const membersLoading = ref(false);
const memberList = ref<any[]>([]);

function openMembers(row: any) {
  shareRow.value = row;
  membersVisible.value = true;
  loadMembers();
}

async function loadMembers() {
  if (!shareRow.value) return;
  membersLoading.value = true;
  try {
    const d = await getFormMembers(shareRow.value.id);
    memberList.value = d.list;
    memberOptions.value = d.members || [];
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  membersLoading.value = false;
}

/* 授权弹窗（新建 / 调整共用） */
const bindVisible = ref(false);
const bindSaving = ref(false);
const bindEditing = ref<number | 0>(0); // 被调整的 member_id，0=新建
const bindForm = reactive({
  member_id: 0,
  data_scope: "own",
  can_channel: true,
  can_export: false
});

function openBind() {
  bindEditing.value = 0;
  Object.assign(bindForm, {
    member_id: 0,
    data_scope: "own",
    can_channel: true,
    can_export: false
  });
  bindVisible.value = true;
}

function openBindEdit(row: any) {
  bindEditing.value = row.member_id;
  Object.assign(bindForm, {
    member_id: row.member_id,
    data_scope: row.data_scope,
    can_channel: !!row.can_channel,
    can_export: !!row.can_export
  });
  bindVisible.value = true;
}

async function saveBinding() {
  if (!bindEditing.value && !bindForm.member_id) {
    return message("请选择成员", { type: "warning" });
  }
  bindSaving.value = true;
  try {
    const payload = {
      data_scope: bindForm.data_scope,
      can_channel: bindForm.can_channel,
      can_export: bindForm.can_export
    };
    if (bindEditing.value) {
      await updateFormMember(shareRow.value.id, bindEditing.value, payload);
    } else {
      await bindFormMember(shareRow.value.id, {
        member_id: bindForm.member_id,
        ...payload
      });
    }
    message("已保存", { type: "success" });
    bindVisible.value = false;
    loadMembers();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  bindSaving.value = false;
}

async function removeBinding(row: any) {
  const name = row.nickname || row.username;
  const ok = await ElMessageBox.confirm(
    `移除「${name}」的授权？其将立即失去该表单的访问权；名下渠道保留（历史归因不丢）。`,
    "移除授权",
    { confirmButtonText: "确定", cancelButtonText: "取消", type: "warning" }
  )
    .then(() => true)
    .catch(() => false);
  if (!ok) return;
  try {
    await unbindFormMember(shareRow.value.id, row.member_id);
    message("已移除授权", { type: "success" });
    loadMembers();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}
</script>

<template>
  <div v-loading="loading">
    <div class="page-head">
      <div>
        <el-button
          v-if="!query.deleted && canCreate"
          type="primary"
          :icon="Plus"
          @click="openCreate"
        >
          新建表单
        </el-button>
        <el-button
          :type="query.deleted ? 'primary' : 'default'"
          :icon="Delete"
          @click="toggleDeleted"
        >
          {{ query.deleted ? "返回列表" : "回收站" }}
        </el-button>
      </div>
    </div>

    <el-card shadow="never">
      <div class="filter-bar">
        <el-select
          v-model="query.status"
          placeholder="全部状态"
          clearable
          style="width: 130px"
          @change="load(1)"
        >
          <el-option label="草稿" :value="0" />
          <el-option label="收集中" :value="1" />
          <el-option label="已关闭" :value="2" />
        </el-select>
        <el-input
          v-model="query.keyword"
          placeholder="搜索表单标题"
          clearable
          style="width: 240px"
          :prefix-icon="Search"
          @keyup.enter="load(1)"
          @clear="load(1)"
        />
        <el-button @click="load(1)">查询</el-button>
      </div>

      <el-table
        :data="list"
        :empty-text="
          query.deleted
            ? '回收站为空'
            : canCreate
              ? '还没有表单，点右上角新建'
              : '还没有表单'
        "
      >
        <el-table-column label="表单" min-width="220">
          <template #default="{ row }">
            <div>
              <el-tooltip content="点击复制分享链接" placement="top">
                <el-link type="primary" @click="copyRowLink(row)">
                  {{ row.title }}
                </el-link>
              </el-tooltip>
              <el-tag
                v-if="isAuthRow(row)"
                size="small"
                type="warning"
                effect="plain"
                style="margin-left: 6px"
              >
                协作
              </el-tag>
              <div v-if="row.description" class="form-cell-desc">
                {{ row.description }}
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-switch
              v-if="canEditRow(row)"
              :model-value="row.status === 1"
              inline-prompt
              active-text="收集中"
              inactive-text="停止"
              :loading="switchingIds.has(row.id)"
              @change="(v: boolean) => toggleStatus(row, v)"
            />
            <el-tag v-else :type="statusTag(row.status)" size="small">
              {{ statusText(row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="提交数" width="80" align="center">
          <template #default="{ row }">
            <el-link
              :underline="false"
              @click="router.push('/forms/data/' + row.id)"
            >
              <span :class="{ 'count-hot': row.submit_count > 0 }">
                {{ row.submit_count }}
              </span>
            </el-link>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="160">
          <template #default="{ row }">{{ fmtTime(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="最近更新" width="160">
          <template #default="{ row }">{{ fmtTime(row.updated_at) }}</template>
        </el-table-column>
        <el-table-column v-if="isAdminView" label="创建人" width="95">
          <template #default="{ row }">{{ row.creator || "—" }}</template>
        </el-table-column>
        <el-table-column
          v-if="!query.deleted"
          label="操作"
          width="290"
          fixed="right"
          class-name="op-nowrap"
        >
          <template #default="{ row }">
            <el-button
              v-if="canEditRow(row)"
              link
              type="primary"
              :icon="Edit"
              @click="router.push('/design/' + row.id)"
            >
              设计
            </el-button>
            <el-button
              link
              type="primary"
              :icon="Document"
              @click="router.push('/forms/data/' + row.id)"
            >
              数据
            </el-button>
            <el-button link type="success" :icon="Share" @click="openShare(row)">
              分享
            </el-button>
            <el-dropdown
              trigger="click"
              @command="(cmd: string) => moreCmd(cmd, row)"
            >
              <el-button link type="primary" class="op-more">
                更多<el-icon class="el-icon--right"><ArrowDown /></el-icon>
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="stats" :icon="DataAnalysis">
                    统计
                  </el-dropdown-item>
                  <el-dropdown-item
                    command="copy"
                    :icon="DocumentCopy"
                    :disabled="copyingId === row.id"
                  >
                    {{ copyingId === row.id ? "复制中…" : "副本" }}
                  </el-dropdown-item>
                  <el-dropdown-item
                    v-if="canEditRow(row)"
                    command="members"
                    :icon="User"
                  >
                    成员
                  </el-dropdown-item>
                  <el-dropdown-item
                    v-if="canEditRow(row)"
                    command="delete"
                    :icon="Delete"
                    divided
                  >
                    <span style="color: var(--el-color-danger)">删除</span>
                  </el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </template>
        </el-table-column>
        <el-table-column
          v-else
          label="操作"
          width="200"
          fixed="right"
          class-name="op-nowrap"
        >
          <template #default="{ row }">
            <el-button link type="success" @click="restore(row)"
              >恢复</el-button
            >
            <el-button link type="danger" @click="purge(row)"
              >彻底删除</el-button
            >
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination
          background
          layout="total, prev, pager, next"
          :total="total"
          :page-size="query.size"
          :current-page="query.page"
          @current-change="(p: number) => load(p)"
        />
      </div>
    </el-card>

    <!-- 新建表单弹窗 -->
    <el-dialog
      v-model="createVisible"
      title="新建表单"
      width="720px"
      :close-on-click-modal="false"
    >
      <div class="tpl-grid">
        <div
          v-for="t in templates"
          :key="t.key"
          class="tpl-item"
          :class="{ active: pickTpl === t.key }"
          @click="pickTpl = t.key"
        >
          <div class="tpl-icon">
            <el-icon :size="24">
              <component :is="TPL_ICONS[t.icon] || Document" />
            </el-icon>
          </div>
          <div class="tpl-name">{{ t.name }}</div>
          <div class="tpl-desc">{{ t.desc }}</div>
        </div>
      </div>
      <el-input
        v-model="newTitle"
        placeholder="表单标题，如：3 月新品试用申请"
        maxlength="100"
        show-word-limit
        class="mt-4"
      />
      <template #footer>
        <el-button @click="createVisible = false">取消</el-button>
        <el-button type="primary" :loading="creating" @click="create">
          创建并设计
        </el-button>
      </template>
    </el-dialog>

    <!-- 二维码弹窗（默认链接 / 渠道共用）：支持复制图片与保存 PNG -->
    <el-dialog v-model="qrDialogVisible" title="渠道二维码" width="340px">
      <div class="share-qr-card">
        <div ref="qrDialogEl" class="share-qr" />
        <div class="share-qr-tip">扫码或长按识别填写</div>
        <div class="share-url-mini">{{ qrDialogUrl }}</div>
        <div class="share-qr-acts">
          <el-button size="small" @click="copyQrImage(qrDialogEl)">
            复制图片
          </el-button>
          <el-button
            size="small"
            type="primary"
            plain
            @click="saveQrImage(qrDialogEl, qrDialogName)"
          >
            保存 PNG
          </el-button>
        </div>
      </div>
    </el-dialog>

    <!-- 分享与渠道抽屉 -->
    <!-- 统计弹窗 -->
    <el-dialog
      v-model="statsVisible"
      :title="'统计分析 · ' + statsTitle"
      width="960px"
      top="4vh"
      destroy-on-close
      class="stats-dialog"
    >
      <FormStats v-if="statsVisible" :form-id="statsFormId" />
    </el-dialog>

    <el-dialog
      v-model="shareVisible"
      title="分享与渠道"
      width="560px"
      class="share-dialog"
    >
      <el-tabs v-model="shareTab">
        <el-tab-pane
          v-if="shareRow && shareRow.auth?.scope !== 'own'"
          label="默认链接"
          name="base"
        >
          <div v-if="shareRow" class="share-box">
            <div class="share-name-row">
              <span class="share-name">{{ shareRow.title }}</span>
              <el-tag
                v-if="shareRow.status !== 1"
                type="warning"
                size="small"
                effect="light"
              >
                未收集
              </el-tag>
            </div>

            <div class="share-qr-card">
              <div ref="qrEl" class="share-qr" />
              <div class="share-qr-tip">扫码或长按识别填写</div>
              <div class="share-qr-acts">
                <el-button size="small" @click="copyQrImage(qrEl)">
                  复制图片
                </el-button>
                <el-button
                  size="small"
                  type="primary"
                  plain
                  @click="saveQrImage(qrEl, shareRow.title)"
                >
                  保存 PNG
                </el-button>
              </div>
            </div>

            <div class="share-field">
              <div class="share-field-label">默认链接（相当于默认渠道）</div>
              <div class="share-url-row">
                <el-input :model-value="shareRow.shareUrl" readonly />
                <el-button type="primary" @click="copyShare">复制</el-button>
              </div>
            </div>

            <el-alert
              v-if="shareRow.status !== 1"
              type="warning"
              :closable="false"
              title="表单当前未处于收集状态，发布后访客才能打开填写页"
              class="mt-3"
            />
          </div>
        </el-tab-pane>

        <el-tab-pane
          v-if="shareRow && canManageChannels(shareRow)"
          label="渠道推广"
          name="channels"
        >
          <div class="ch-tip">
            每条渠道是同一表单的独立分发链接，提交数据按渠道归因；停用仅不再推广，不拦截提交。
          </div>
          <div class="ch-add-row">
            <el-input
              v-model="newChannelName"
              placeholder="渠道名称，如：朋友圈 / 线下传单A"
              maxlength="100"
              style="flex: 1"
              @keyup.enter="addChannel"
            />
            <el-select
              v-if="canAssign"
              v-model="newChannelMember"
              placeholder="归属（公共）"
              clearable
              style="width: 160px"
            >
              <el-option
                v-for="m in memberOptions"
                :key="m.id"
                :label="m.nickname || m.username"
                :value="m.id"
              />
            </el-select>
            <el-button type="primary" :loading="addingChannel" @click="addChannel">
              添加渠道
            </el-button>
          </div>
          <el-table
            v-loading="channelsLoading"
            :data="channels"
            size="small"
            empty-text="还没有渠道，上方添加后即可分发独立链接"
          >
            <el-table-column label="渠道" min-width="130" show-overflow-tooltip>
              <template #default="{ row }">
                {{ row.name }}
                <el-tag
                  v-if="row.memberId"
                  size="small"
                  effect="plain"
                  style="margin-left: 4px"
                >
                  {{ row.memberName }}
                </el-tag>
                <el-tag
                  v-if="scheduleState(row)"
                  size="small"
                  :type="scheduleState(row) === '推广中' ? 'success' : 'info'"
                  style="margin-left: 4px"
                >
                  {{ scheduleState(row) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="提交数" width="70" align="center">
              <template #default="{ row }">
                <span :class="{ 'count-hot': row.submitCount > 0 }">
                  {{ row.submitCount }}
                </span>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="70" align="center">
              <template #default="{ row }">
                <el-switch
                  :model-value="row.status === 1"
                  @change="(v: boolean) => toggleChannel(row, v)"
                />
              </template>
            </el-table-column>
            <el-table-column label="操作" width="230" class-name="op-nowrap">
              <template #default="{ row }">
                <el-button
                  link
                  type="primary"
                  @click="copyWithTip(row.shareUrl, '链接已复制')"
                >
                  复制
                </el-button>
                <el-button
                  link
                  type="primary"
                  @click="showQr(row.shareUrl, row.name)"
                >
                  二维码
                </el-button>
                <el-popover
                  :visible="schedulePopId === row.id"
                  placement="left"
                  :width="380"
                  trigger="click"
                >
                  <template #reference>
                    <el-button
                      link
                      type="primary"
                      @click="schedulePopId === row.id ? (schedulePopId = 0) : initSchedule(row)"
                    >
                      定时
                    </el-button>
                  </template>
                  <div class="schedule-form">
                    <div class="schedule-title">定时上下线</div>
                    <el-date-picker
                      v-model="scheduleFrom"
                      type="datetime"
                      placeholder="上线时间（可空）"
                      format="YYYY-MM-DD HH:mm"
                      value-format="YYYY-MM-DD HH:mm:ss"
                      style="width: 100%; margin-bottom: 8px"
                    />
                    <el-date-picker
                      v-model="scheduleUntil"
                      type="datetime"
                      placeholder="下线时间（可空）"
                      format="YYYY-MM-DD HH:mm"
                      value-format="YYYY-MM-DD HH:mm:ss"
                      style="width: 100%; margin-bottom: 8px"
                    />
                    <div class="form-tip" style="margin-bottom: 8px">
                      窗口外提交按直接访问归因；两边留空表示长期推广
                    </div>
                    <div style="display: flex; gap: 8px">
                      <el-button
                        type="primary"
                        size="small"
                        style="flex: 1"
                        @click="saveSchedule(row)"
                      >
                        保存
                      </el-button>
                      <el-button size="small" @click="schedulePopId = 0">
                        关闭
                      </el-button>
                    </div>
                  </div>
                </el-popover>
                <el-button link type="danger" @click="removeChannel(row)">
                  删除
                </el-button>
              </template>
            </el-table-column>
          </el-table>
          <div v-if="shareRow?.auth?.scope === 'own'" class="ch-tip" style="margin-top: 10px">
            你的数据范围是「仅自己渠道」：通过上方渠道链接收集的数据才会在你的数据列表中可见。
          </div>
        </el-tab-pane>
      </el-tabs>
    </el-dialog>

    <!-- 协作成员抽屉 -->
    <el-drawer v-model="membersVisible" title="协作成员" size="640px">
      <div class="ch-tip">
        把表单共享给成员查看/分享/收集数据；移除授权后成员立即失去访问，名下渠道保留归因。
      </div>
      <div style="margin-bottom: 12px">
        <el-button type="primary" :icon="Plus" @click="openBind">
          添加协作成员
        </el-button>
      </div>
      <el-table
        v-loading="membersLoading"
        :data="memberList"
        size="small"
        empty-text="还没有协作成员"
      >
        <el-table-column label="成员" min-width="120">
          <template #default="{ row }">
            {{ row.nickname || row.username }}
            <div class="member-username">{{ row.username }}</div>
          </template>
        </el-table-column>
        <el-table-column label="数据范围" width="110" align="center">
          <template #default="{ row }">
            <el-tooltip
              :content="
                row.data_scope === 'all'
                  ? '可查看该表单全部提交数据'
                  : '仅可查看通过其名下渠道链接提交的数据'
              "
              placement="top"
            >
              <el-tag
                size="small"
                :type="row.data_scope === 'all' ? 'success' : 'warning'"
                effect="plain"
              >
                {{ row.data_scope === "all" ? "全部数据" : "仅自己渠道" }}
              </el-tag>
            </el-tooltip>
          </template>
        </el-table-column>
        <el-table-column label="渠道数" width="70" align="center">
          <template #default="{ row }">{{ row.channel_count }}</template>
        </el-table-column>
        <el-table-column label="能力" min-width="130">
          <template #default="{ row }">
            <el-tag v-if="row.can_channel" size="small" effect="plain">
              可建渠道
            </el-tag>
            <el-tag v-if="row.can_export" size="small" effect="plain" style="margin-left: 4px">
              可导出
            </el-tag>
            <span v-if="!row.can_channel && !row.can_export" style="color: #c0c4cc">
              仅查看
            </span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="130" class-name="op-nowrap">
          <template #default="{ row }">
            <el-button link type="primary" @click="openBindEdit(row)">
              调整
            </el-button>
            <el-button link type="danger" @click="removeBinding(row)">
              移除
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <!-- 授权弹窗 -->
      <el-dialog
        v-model="bindVisible"
        :title="bindEditing ? '调整授权' : '添加协作成员'"
        width="460px"
        append-to-body
      >
        <el-form label-width="90px" label-position="left">
          <el-form-item label="成员">
            <el-select
              v-model="bindForm.member_id"
              :disabled="!!bindEditing"
              placeholder="选择成员账号"
              style="width: 100%"
            >
              <el-option
                v-for="m in memberOptions"
                :key="m.id"
                :label="(m.nickname || m.username) + '（' + m.username + '）'"
                :value="m.id"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="数据范围">
            <el-radio-group v-model="bindForm.data_scope">
              <el-radio value="own">仅自己渠道</el-radio>
              <el-radio value="all">全部数据</el-radio>
            </el-radio-group>
            <div class="bind-tip">
              {{
                bindForm.data_scope === "own"
                  ? "成员只能看到通过其名下渠道链接提交的数据，各推各的互不可见"
                  : "成员可查看该表单的全部提交数据"
              }}
            </div>
          </el-form-item>
          <el-form-item label=" ">
            <el-checkbox v-model="bindForm.can_channel">
              允许自建渠道链接
            </el-checkbox>
            <el-checkbox v-model="bindForm.can_export">允许导出 CSV</el-checkbox>
          </el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="bindVisible = false">取消</el-button>
          <el-button type="primary" :loading="bindSaving" @click="saveBinding">
            保存
          </el-button>
        </template>
      </el-dialog>
    </el-drawer>
  </div>
</template>

<style scoped lang="scss">
:deep(.op-nowrap .cell) {
  white-space: nowrap;
  display: flex;
  align-items: center;
}

.op-more {
  margin-left: 12px;
}

.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;

  h2 {
    margin: 0;
    font-size: 18px;
  }
}

.filter-bar {
  display: flex;
  gap: 10px;
  margin-bottom: 14px;
  flex-wrap: wrap;
  align-items: center;
}

.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 14px;
}

.form-cell-desc {
  color: #909399;
  font-size: 12px;
  margin-top: 2px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.count-hot {
  color: var(--el-color-primary);
  font-weight: 600;
}

.share-box {
  display: flex;
  flex-direction: column;
  align-items: stretch;
}

.share-name-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;

  .share-name {
    font-size: 15px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }
}

/* 二维码主视觉卡片：浅底 + 边框圆角，扫码区/提示/操作集中呈现 */
.share-qr-card {
  padding: 16px;
  background: var(--el-fill-color-light);
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 10px;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.share-qr {
  width: 180px;
  height: 180px;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  background: #fff;
  border-radius: 8px;

  :deep(canvas),
  :deep(img) {
    display: block;
  }
}

.share-qr-tip {
  margin-top: 8px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.share-qr-acts {
  display: flex;
  gap: 8px;
  margin-top: 10px;
}

.share-url-mini {
  margin-top: 8px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  word-break: break-all;
  max-width: 260px;
  text-align: center;
}

.share-field {
  width: 100%;
  margin-top: 14px;
}

.share-field-label {
  font-size: 12.5px;
  color: var(--el-text-color-secondary);
  margin-bottom: 6px;
}

.share-url-row {
  display: flex;
  gap: 8px;
}

.ch-tip {
  color: #909399;
  font-size: 12px;
  line-height: 1.6;
  margin-bottom: 12px;
}

.ch-add-row {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
}

.member-username {
  color: #c0c4cc;
  font-size: 12px;
}

.bind-tip {
  color: #909399;
  font-size: 12px;
  line-height: 1.5;
  margin-top: 4px;
  width: 100%;
}
</style>

<style lang="scss">
/* 新建表单弹窗里的模板选择 */
.tpl-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;

  .tpl-item {
    border: 1.5px solid #e4e7ed;
    border-radius: 10px;
    padding: 16px 10px;
    text-align: center;
    cursor: pointer;
    transition: all 0.15s;

    &:hover {
      border-color: var(--el-color-primary);
      transform: translateY(-2px);
      box-shadow: 0 6px 16px rgb(64 158 255 / 12%);
    }

    &.active {
      border-color: var(--el-color-primary);
      background: #ecf5ff;
    }

    .tpl-icon {
      width: 44px;
      height: 44px;
      margin: 0 auto;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #f0f5ff;
      color: var(--el-color-primary);
      font-size: 22px;
      font-weight: 700;
    }

    .tpl-name {
      font-weight: 600;
      margin: 10px 0 4px;
      font-size: 14px;
    }

    .tpl-desc {
      color: #909399;
      font-size: 12px;
      line-height: 1.5;
    }
  }
}

@media (max-width: 900px) {
  .tpl-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

.schedule-form {
  display: flex;
  flex-direction: column;
}

.schedule-title {
  font-weight: 600;
  margin-bottom: 10px;
}
</style>
