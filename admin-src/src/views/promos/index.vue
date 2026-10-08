<script setup lang="ts">
/**
 * 引流中心：活码系统 + 短链（对齐引流宝核心交互）
 *
 * 活码：一个二维码长期不变，落地页按轮询分发二维码图片，
 *       单张图达到扫码上限自动切换下一张（群满自动换群），支持并流多码齐推。
 * 短链：一个短码背后多条目标链接（轮询域名），直接跳转或中转页。
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount } from "vue";
import { message } from "@/utils/message";
import { ElMessageBox } from "element-plus";
import {
  getPromos,
  getPromo,
  createPromo,
  updatePromo,
  deletePromo,
  restorePromo,
  purgePromo,
  setPromoStatus,
  getDomains
} from "@/api/promos";
import {
  Search,
  Plus,
  Edit,
  Delete,
  Share,
  View,
  RefreshLeft
} from "@element-plus/icons-vue";
import { copyWithTip } from "@/utils/clipboard";
import StatsDialog from "./StatsDialog.vue";

/* ---------- 列表（Tab + 卡片） ---------- */
const activeKind = ref<"qrcode" | "short">("qrcode");
const loading = ref(false);
const list = ref<any[]>([]);
const total = ref(0);
const query = reactive({
  page: 1,
  size: 24,
  keyword: "",
  deleted: 0
});

async function load(page?: number) {
  if (page) query.page = page;
  loading.value = true;
  try {
    const d = await getPromos({
      page: query.page,
      size: query.size,
      keyword: query.keyword,
      kind: activeKind.value,
      deleted: query.deleted
    });
    list.value = d.list;
    total.value = d.total;
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  loading.value = false;
}

function switchKind() {
  query.page = 1;
  load();
}

function search() {
  query.page = 1;
  load();
}

function reset() {
  query.keyword = "";
  query.deleted = 0;
  search();
}

function toggleDeleted() {
  query.deleted = query.deleted ? 0 : 1;
  search();
}

const isShort = computed(() => activeKind.value === "short");

/* ---------- 新建 / 编辑弹窗（按 kind 切换字段） ---------- */
const dialogVisible = ref(false);
const dialogMode = ref<"create" | "edit">("create");
const dialogId = ref<number | string>(0);
const dialogLoading = ref(false);
const saving = ref(false);
const domains = ref<string[]>([]);
const uploading = ref(false);

const form = reactive({
  name: "",
  // 活码
  rotate: "seq",
  flow: false,
  title: "",
  tip: "",
  bottomType: "none",
  bottomText: "",
  bottomTarget: "",
  fallback: "",
  items: [] as any[],
  // 短链
  mode: "direct",
  domain: ""
});

function emptyItem(kind: string) {
  return {
    kind,
    target: "",
    scan_limit: 0,
    weight: 1,
    device: "all",
    time_from: "",
    time_to: ""
  };
}

function openCreate() {
  dialogMode.value = "create";
  dialogId.value = 0;
  form.name = "";
  form.rotate = "seq";
  form.flow = false;
  form.title = "";
  form.tip = "";
  form.bottomType = "none";
  form.bottomText = "";
  form.bottomTarget = "";
  form.fallback = "";
  form.mode = "direct";
  form.domain = "";
  form.items = [emptyItem(isShort.value ? "url" : "img")];
  dialogVisible.value = true;
  loadDomains();
}

async function edit(row: any) {
  dialogMode.value = "edit";
  dialogId.value = row.id;
  form.name = "";
  form.items = [];
  dialogVisible.value = true;
  dialogLoading.value = true;
  loadDomains();
  try {
    const d = await getPromo(row.id);
    form.name = d.name;
    form.rotate = d.rotate || "seq";
    form.flow = !!d.flow;
    form.title = d.title || "";
    form.tip = d.tip || "";
    form.bottomType = d.bottomType || "none";
    form.bottomText = d.bottomText || "";
    form.bottomTarget = d.bottomTarget || "";
    form.fallback = d.fallback || "";
    form.mode = d.mode || "direct";
    form.domain = d.domain || "";
    form.items = (Array.isArray(d.items) ? d.items : []).map((it: any) => ({
      id: it.id,
      kind: it.kind,
      target: it.target || "",
      scan_limit: it.scan_limit || 0,
      weight: it.weight || 1,
      device: it.device || "all",
      time_from: it.time_from || "",
      time_to: it.time_to || ""
    }));
  } catch (e: any) {
    message(e.message, { type: "error" });
    dialogVisible.value = false;
  }
  dialogLoading.value = false;
}

function loadDomains() {
  getDomains()
    .then(d => {
      domains.value = d.domains || [];
    })
    .catch(() => {
      domains.value = [];
    });
}

function addItem() {
  if (form.items.length >= 50) {
    return message("最多添加 50 个条目", { type: "warning" });
  }
  form.items.push(emptyItem(isShort.value ? "url" : "img"));
}

function removeItem(i: number) {
  form.items.splice(i, 1);
}

function moveItem(i: number, dir: -1 | 1) {
  const j = i + dir;
  if (j < 0 || j >= form.items.length) return;
  const [it] = form.items.splice(i, 1);
  form.items.splice(j, 0, it);
}

function uploadFor(i: number) {
  return (opt: any) => uploadImage(opt, i);
}

async function uploadImage(opt: any, i: number) {
  uploading.value = true;
  const fd = new FormData();
  fd.append("file", opt.file);
  fetch("/api/upload", {
    method: "POST",
    credentials: "same-origin",
    body: fd
  })
    .then(r => r.json())
    .then(d => {
      if (d.code !== 0) throw new Error(d.msg || "上传失败");
      if (form.items[i]) form.items[i].target = d.data.url;
      message("图片已上传", { type: "success" });
      opt.onSuccess?.(d);
    })
    .catch(e => {
      message(e.message || "上传失败", { type: "error" });
      opt.onError?.(e);
    })
    .finally(() => {
      uploading.value = false;
    });
}

/** 目标有效性校验（镜像后端 normalizeItems） */
function itemValid(kind: string, target: string): boolean {
  const t = String(target || "").trim();
  if (!t) return false;
  if (kind === "img") {
    if (t.startsWith("/storage/uploads/")) return true;
    return (
      /^https?:\/\//i.test(t) && /\.(png|jpe?g|gif|webp|bmp)(\?|$)/i.test(t)
    );
  }
  return /^https?:\/\//i.test(t);
}

function validate(): string {
  if (!form.name.trim()) return "请填写推广名称";
  if (!form.items.length) {
    return isShort.value ? "至少添加一条目标链接" : "至少上传一张二维码图片";
  }
  for (const [i, it] of form.items.entries()) {
    if (!itemValid(it.kind, it.target)) {
      return isShort.value
        ? `第 ${i + 1} 条链接需以 http:// 或 https:// 开头`
        : `第 ${i + 1} 张请上传图片或填写图片地址`;
    }
    // 时段必须成对：后端对半截规则会双双清空（静默失效），前端提前拦截
    const hasFrom = !!String(it.time_from || "").trim();
    const hasTo = !!String(it.time_to || "").trim();
    if (hasFrom !== hasTo) {
      return `第 ${i + 1} 个条目的时段需成对填写（或全部留空表示不限）`;
    }
  }
  return "";
}

async function submitDialog() {
  const err = validate();
  if (err) return message(err, { type: "warning" });
  saving.value = true;
  try {
    const payload: any = {
      type: activeKind.value,
      name: form.name.trim(),
      rotate: form.rotate,
      domain: form.domain,
      items: form.items
    };
    if (isShort.value) {
      payload.mode = form.mode;
    } else {
      payload.flow = form.flow ? 1 : 0;
      payload.title = form.title.trim();
      payload.tip = form.tip.trim();
      payload.bottom_type = form.bottomType;
      payload.bottom_text = form.bottomText.trim();
      payload.bottom_target = form.bottomTarget.trim();
      payload.fallback = form.fallback.trim();
    }
    if (dialogMode.value === "create") {
      await createPromo(payload);
      message("创建成功，链接与二维码已生成", { type: "success" });
    } else {
      await updatePromo(dialogId.value, payload);
      message("已保存。二维码不变，配置立即生效", { type: "success" });
    }
    dialogVisible.value = false;
    load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  saving.value = false;
}

/* ---------- 状态 / 删除 ---------- */
async function toggleStatus(row: any) {
  const next = row.status === 1 ? 0 : 1;
  try {
    await setPromoStatus(row.id, next);
    message(next === 1 ? "已启用" : "已停用", { type: "success" });
    load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

async function remove(row: any) {
  try {
    await ElMessageBox.confirm(
      `确定删除「${row.name}」？删除后可在回收站恢复。`,
      "删除确认",
      {
        type: "warning",
        confirmButtonText: "确定删除",
        cancelButtonText: "取消"
      }
    );
    await deletePromo(row.id);
    message("已删除", { type: "success" });
    load();
  } catch (e: any) {
    if (e !== "cancel") message(e.message, { type: "error" });
  }
}

async function restore(row: any) {
  try {
    await restorePromo(row.id);
    message("已恢复", { type: "success" });
    load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

async function purge(row: any) {
  try {
    await ElMessageBox.confirm(
      `彻底删除「${row.name}」后数据一并清除且不可恢复，确定？`,
      "彻底删除",
      { type: "error", confirmButtonText: "彻底删除", cancelButtonText: "取消" }
    );
    await purgePromo(row.id);
    message("已彻底删除", { type: "success" });
    load();
  } catch (e: any) {
    if (e !== "cancel") message(e.message, { type: "error" });
  }
}

/* ---------- 统计弹窗 ---------- */
const statsVisible = ref(false);
const statsRow = ref<any>(null);

function stats(row: any) {
  statsRow.value = row;
  statsVisible.value = true;
}

/* ---------- 二维码分享弹窗 ---------- */
const shareVisible = ref(false);
const shareRow = ref<any>(null);
const qrEl = ref<HTMLElement>();
let qrTimer: ReturnType<typeof setTimeout> | null = null;

function share(row: any) {
  shareRow.value = row;
  shareVisible.value = true;
  if (qrTimer) clearTimeout(qrTimer);
  qrTimer = setTimeout(renderQr, 100);
}

let qrcodePromise: Promise<void> | null = null;

function loadQrcodeLib(): Promise<void> {
  // 并发调用共享同一个加载 Promise，避免重复注入 script
  if ((window as any).QRCode) return Promise.resolve();
  if (qrcodePromise) return qrcodePromise;
  qrcodePromise = new Promise(resolve => {
    const s = document.createElement("script");
    // BASE_URL：dev 为 /，prod 为 /admin/（vite 会把 public/ 目录原样复制进产物）
    s.src = `${import.meta.env.BASE_URL}vendor/qrcode.min.js`;
    s.onload = () => resolve();
    // 加载失败也要 resolve，否则弹窗会一直挂着不渲染
    s.onerror = () => resolve();
    document.head.appendChild(s);
  });
  return qrcodePromise;
}

async function renderQr() {
  if (!qrEl.value || !shareRow.value) return;
  qrEl.value.innerHTML = "";
  await loadQrcodeLib();
  try {
    if (!(window as any).QRCode) throw new Error("lib missing");
    new (window as any).QRCode(qrEl.value, {
      text: shareRow.value.shareUrl,
      width: 180,
      height: 180,
      correctLevel: (window as any).QRCode.CorrectLevel.M
    });
  } catch (e) {
    qrEl.value.innerHTML =
      '<div style="color:#909399;font-size:12px;padding:20px">二维码生成失败</div>';
  }
}

function copyShare() {
  copyWithTip(shareRow.value.shareUrl, "链接已复制");
}

function downloadQr() {
  const canvas = qrEl.value?.querySelector("canvas");
  if (!canvas) {
    return message("二维码尚未生成", { type: "warning" });
  }
  const a = document.createElement("a");
  a.href = canvas.toDataURL("image/png");
  a.download = `qrcode-${shareRow.value.code}.png`;
  a.click();
}

/** 卡片缩略地址（相对路径补域名） */
function thumbUrl(target: string) {
  return target.startsWith("/") ? location.origin + target : target;
}

function fmtTime(s: string) {
  return s ? String(s).slice(0, 16) : "-";
}

onMounted(() => load());

onBeforeUnmount(() => {
  if (qrTimer) {
    clearTimeout(qrTimer);
    qrTimer = null;
  }
});
</script>

<template>
  <div v-loading="loading">
    <div class="page-head">
      <el-radio-group v-model="activeKind" @change="switchKind">
        <el-radio-button value="qrcode">活码</el-radio-button>
        <el-radio-button value="short">短链</el-radio-button>
      </el-radio-group>
      <div class="head-actions">
        <el-button type="primary" :icon="Plus" @click="openCreate">
          {{ isShort ? "新建短链" : "新建活码" }}
        </el-button>
        <el-button :icon="RefreshLeft" @click="toggleDeleted">
          {{ query.deleted ? "返回列表" : "回收站" }}
        </el-button>
      </div>
    </div>

    <div class="kind-intro">
      <template v-if="isShort">
        长链接生成短链；可配置多条目标链接轮询（多域名轮换，降低单域名风险），支持中转引导。
      </template>
      <template v-else>
        一个二维码长期不变，扫码后按轮询展示二维码图片；单张图达到扫码上限自动切换下一张（群满自动换群），支持多码并流。
      </template>
    </div>

    <div v-if="!query.deleted" class="filter-bar">
      <el-input
        v-model="query.keyword"
        placeholder="搜索名称"
        clearable
        style="width: 200px"
        @keyup.enter="search"
      >
        <template #prefix
          ><el-icon><Search /></el-icon
        ></template>
      </el-input>
      <el-button type="primary" @click="search">查询</el-button>
      <el-button @click="reset">重置</el-button>
    </div>

    <!-- 卡片列表 -->
    <el-row v-if="list.length" :gutter="14">
      <el-col
        v-for="row in list"
        :key="row.id"
        :xs="24"
        :sm="12"
        :lg="8"
        :xl="6"
      >
        <div class="promo-card" :class="{ disabled: row.status !== 1 }">
          <div class="pc-head">
            <div class="pc-thumb">
              <template v-if="!isShort">
                <el-image
                  v-if="row.items && row.items.length"
                  :src="thumbUrl(row.items[0].target)"
                  fit="cover"
                  :preview-src-list="[thumbUrl(row.items[0].target)]"
                  preview-teleported
                />
                <div v-else class="pc-thumb-empty">未上传</div>
              </template>
              <div v-else class="pc-thumb-link">
                <span class="pc-link-host">{{
                  row.items?.[0]?.target || "未配置"
                }}</span>
              </div>
            </div>
            <div class="pc-main">
              <div class="pc-name" :title="row.name">{{ row.name }}</div>
              <div class="pc-meta">
                <template v-if="isShort">
                  {{ row.items?.length || 0 }} 条链接 ·
                  {{ row.mode === "guide" ? "中转引导" : "直接跳转" }}
                </template>
                <template v-else>
                  {{ row.items?.length || 0 }} 张码 ·
                  {{
                    row.flow
                      ? "多码并流"
                      : row.rotate === "rand"
                        ? "随机分配"
                        : "顺序轮询"
                  }}
                </template>
                <span class="pc-dot">·</span>{{ fmtTime(row.created_at) }}
              </div>
              <div class="pc-stats">
                <span class="pc-num">{{ row.click_count }}</span
                ><span class="pc-num-label">{{
                  isShort ? "点击量" : "扫码量"
                }}</span>
              </div>
            </div>
            <el-switch
              v-if="!query.deleted"
              :model-value="row.status === 1"
              @change="toggleStatus(row)"
            />
          </div>

          <el-link
            type="primary"
            :underline="false"
            class="pc-url"
            @click="copyWithTip(row.shareUrl, '链接已复制')"
          >
            {{ row.shareUrl || "已删除" }}
          </el-link>

          <div class="pc-foot">
            <div class="pc-ops">
              <template v-if="!query.deleted">
                <el-button link type="primary" :icon="Edit" @click="edit(row)">
                  编辑
                </el-button>
                <el-button link type="primary" :icon="View" @click="stats(row)">
                  统计
                </el-button>
                <el-button
                  link
                  type="success"
                  :icon="Share"
                  @click="share(row)"
                >
                  二维码
                </el-button>
                <el-button
                  link
                  type="danger"
                  :icon="Delete"
                  @click="remove(row)"
                >
                  删除
                </el-button>
              </template>
              <template v-else>
                <el-button link type="primary" @click="restore(row)"
                  >恢复</el-button
                >
                <el-button link type="danger" @click="purge(row)"
                  >彻底删除</el-button
                >
              </template>
            </div>
          </div>
        </div>
      </el-col>
    </el-row>

    <div v-if="!list.length" class="empty-tip">
      {{
        query.deleted
          ? "回收站为空"
          : isShort
            ? "还没有短链，点击右上角新建"
            : "还没有活码，点击右上角新建"
      }}
    </div>

    <el-pagination
      v-if="total > query.size"
      class="pager"
      layout="total, prev, pager, next"
      :total="total"
      :page-size="query.size"
      :current-page="query.page"
      @current-change="load"
    />

    <!-- 新建 / 编辑弹窗 -->
    <el-dialog
      v-model="dialogVisible"
      :title="
        (dialogMode === 'create' ? '新建' : '编辑') +
        (isShort ? '短链' : '活码')
      "
      width="720px"
      :close-on-click-modal="false"
      top="6vh"
    >
      <div v-loading="dialogLoading" class="dialog-body">
        <el-form label-width="92px">
          <el-form-item label="推广名称" required>
            <el-input
              v-model="form.name"
              maxlength="100"
              :placeholder="
                isShort ? '如：抖音投放 0315' : '如：门店进群码 / 客服活码'
              "
            />
          </el-form-item>

          <!-- 活码专属 -->
          <template v-if="!isShort">
            <el-form-item label="分发方式">
              <div class="stack">
                <div class="inline-row">
                  <el-radio-group v-model="form.rotate">
                    <el-radio-button value="seq">顺序轮询</el-radio-button>
                    <el-radio-button value="rand">随机分配</el-radio-button>
                  </el-radio-group>
                  <el-checkbox v-model="form.flow">
                    并流（所有二维码同时展示）
                  </el-checkbox>
                </div>
                <div class="form-tip-inline">
                  顺序轮询：按扫码顺序依次分配；随机分配：每次随机抽取。并流适合多群同时推广。
                </div>
              </div>
            </el-form-item>

            <el-form-item label="目标图片" required>
              <div class="items-wrap">
                <div v-for="(it, i) in form.items" :key="i" class="item-row">
                  <div class="item-top">
                    <div class="item-preview">
                      <el-image
                        v-if="it.target"
                        :src="thumbUrl(it.target)"
                        fit="cover"
                      />
                      <span v-else class="item-preview-empty">空</span>
                    </div>
                    <div class="item-fields">
                      <div class="item-line">
                        <el-input
                          v-model="it.target"
                          size="small"
                          placeholder="图片地址：上传后自动填入，或填写 https://… 链接"
                        />
                        <div class="item-ops">
                          <el-button
                            link
                            size="small"
                            :disabled="i === 0"
                            @click="moveItem(i, -1)"
                            >上移</el-button
                          >
                          <el-button
                            link
                            size="small"
                            :disabled="i === form.items.length - 1"
                            @click="moveItem(i, 1)"
                            >下移</el-button
                          >
                          <el-button
                            link
                            size="small"
                            type="danger"
                            @click="removeItem(i)"
                            >删除</el-button
                          >
                        </div>
                      </div>
                      <div class="item-line">
                        <el-upload
                          :show-file-list="false"
                          :http-request="uploadFor(i)"
                          accept="image/*"
                        >
                          <el-button size="small" :loading="uploading">
                            上传图片
                          </el-button>
                        </el-upload>
                        <span class="item-label">扫码上限</span>
                        <el-input-number
                          v-model="it.scan_limit"
                          :min="0"
                          :max="1000000"
                          size="small"
                          style="width: 100px"
                        />
                      </div>
                    </div>
                  </div>
                  <div class="item-rule item-line">
                    <span class="item-label">权重</span>
                    <el-input-number
                      v-model="it.weight"
                      :min="1"
                      :max="100"
                      size="small"
                      style="width: 84px"
                    />
                    <span class="item-label">设备</span>
                    <el-select
                      v-model="it.device"
                      size="small"
                      style="width: 92px"
                    >
                      <el-option label="全部" value="all" />
                      <el-option label="iOS" value="ios" />
                      <el-option label="安卓" value="android" />
                      <el-option label="电脑" value="pc" />
                    </el-select>
                    <span class="item-label">时段</span>
                    <el-time-picker
                      v-model="it.time_from"
                      format="HH:mm"
                      value-format="HH:mm"
                      placeholder="开始"
                      size="small"
                      style="width: 92px"
                    />
                    <span class="item-dash">—</span>
                    <el-time-picker
                      v-model="it.time_to"
                      format="HH:mm"
                      value-format="HH:mm"
                      placeholder="结束"
                      size="small"
                      style="width: 92px"
                    />
                  </div>
                </div>
                <el-button size="small" :icon="Plus" plain @click="addItem">
                  添加图片
                </el-button>
                <div class="form-tip-inline">
                  扫码上限 = 该图达到次数后自动切换下一张，0
                  表示不限（如群满200人填200）；权重决定分发配比（2:1
                  即前一个约获三分之二流量）；设备/时段不匹配的访客自动落到其他命中条目。
                </div>
              </div>
            </el-form-item>

            <el-form-item label="页面标题">
              <el-input
                v-model="form.title"
                maxlength="100"
                placeholder="默认：扫码添加"
              />
            </el-form-item>
            <el-form-item label="引导文案">
              <el-input
                v-model="form.tip"
                maxlength="200"
                placeholder="默认：长按识别二维码"
              />
            </el-form-item>
            <el-form-item label="底部按钮">
              <div class="stack">
                <el-radio-group v-model="form.bottomType">
                  <el-radio-button value="none">不显示</el-radio-button>
                  <el-radio-button value="text">纯文字提示</el-radio-button>
                  <el-radio-button value="link">跳转按钮</el-radio-button>
                </el-radio-group>
                <template v-if="form.bottomType !== 'none'">
                  <el-input
                    v-model="form.bottomText"
                    maxlength="100"
                    placeholder="条上显示的文字，如：联系客服 / 客服可能不在线，回复较慢"
                  />
                  <el-input
                    v-if="form.bottomType === 'link'"
                    v-model="form.bottomTarget"
                    placeholder="跳转目标：https:// 链接或站内页面分享码"
                  />
                  <div
                    v-if="form.bottomType === 'link'"
                    class="form-tip-inline"
                  >
                    橙色条整条可点击；填页面分享码跳站内页，填 http(s)
                    链接直接跳转
                  </div>
                </template>
              </div>
            </el-form-item>
            <el-form-item label="满员提示">
              <el-input
                v-model="form.fallback"
                maxlength="200"
                placeholder="默认：所有二维码均已满员，请联系管理员补充"
              />
            </el-form-item>
          </template>

          <!-- 短链专属 -->
          <template v-else>
            <el-form-item label="跳转方式">
              <el-radio-group v-model="form.mode">
                <el-radio-button value="direct">直接跳转</el-radio-button>
                <el-radio-button value="guide">中转引导</el-radio-button>
              </el-radio-group>
              <div class="form-tip-inline">
                中转引导：先落本站引导页，提示用户右上角浏览器打开，适合微信/抖音等封闭平台
              </div>
            </el-form-item>
            <el-form-item label="目标链接" required>
              <div class="items-wrap">
                <div v-for="(it, i) in form.items" :key="i" class="item-row">
                  <div class="item-line">
                    <el-input v-model="it.target" placeholder="https://…" />
                    <div class="item-ops">
                      <el-button
                        link
                        size="small"
                        :disabled="i === 0"
                        @click="moveItem(i, -1)"
                        >上移</el-button
                      >
                      <el-button
                        link
                        size="small"
                        :disabled="i === form.items.length - 1"
                        @click="moveItem(i, 1)"
                        >下移</el-button
                      >
                      <el-button
                        link
                        size="small"
                        type="danger"
                        @click="removeItem(i)"
                        >删除</el-button
                      >
                    </div>
                  </div>
                  <div class="item-rule item-line">
                    <span class="item-label">权重</span>
                    <el-input-number
                      v-model="it.weight"
                      :min="1"
                      :max="100"
                      size="small"
                      style="width: 84px"
                    />
                    <span class="item-label">设备</span>
                    <el-select
                      v-model="it.device"
                      size="small"
                      style="width: 92px"
                    >
                      <el-option label="全部" value="all" />
                      <el-option label="iOS" value="ios" />
                      <el-option label="安卓" value="android" />
                      <el-option label="电脑" value="pc" />
                    </el-select>
                    <span class="item-label">时段</span>
                    <el-time-picker
                      v-model="it.time_from"
                      format="HH:mm"
                      value-format="HH:mm"
                      placeholder="开始"
                      size="small"
                      style="width: 92px"
                    />
                    <span class="item-dash">—</span>
                    <el-time-picker
                      v-model="it.time_to"
                      format="HH:mm"
                      value-format="HH:mm"
                      placeholder="结束"
                      size="small"
                      style="width: 92px"
                    />
                  </div>
                </div>
                <el-button size="small" :icon="Plus" plain @click="addItem">
                  添加链接
                </el-button>
                <div class="form-tip-inline">
                  多条链接按分发方式轮询跳转（如多个域名轮换，降低单域名被限制的风险）；只填一条即固定跳转；权重/设备/时段可实现精准分流。
                </div>
              </div>
            </el-form-item>
            <el-form-item label="分发方式">
              <el-radio-group v-model="form.rotate">
                <el-radio-button value="seq">顺序轮询</el-radio-button>
                <el-radio-button value="rand">随机分配</el-radio-button>
              </el-radio-group>
            </el-form-item>
          </template>

          <el-form-item label="发布域名">
            <el-select
              v-model="form.domain"
              clearable
              placeholder="默认（当前域名）"
              style="width: 260px"
            >
              <el-option label="默认（当前域名）" value="" />
              <el-option v-for="d in domains" :key="d" :label="d" :value="d" />
            </el-select>
            <div class="form-tip-inline">
              域名池在 系统设置 → 站点域名池 维护；可选落地域名/备用域名生成链接
            </div>
          </el-form-item>
        </el-form>
      </div>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitDialog">
          {{ dialogMode === "create" ? "创建" : "保存" }}
        </el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="shareVisible" title="推广二维码" width="420px">
      <div class="share-box">
        <div class="share-name-row">
          <span class="share-name">{{ shareRow?.name }}</span>
          <el-tag
            v-if="shareRow?.status !== 1"
            type="warning"
            size="small"
            effect="light"
          >
            已停用
          </el-tag>
        </div>

        <div class="share-qr-card">
          <div ref="qrEl" class="share-qr" />
          <div class="share-qr-tip">扫码或长按识别二维码</div>
        </div>

        <div class="share-field">
          <div class="share-field-label">推广链接</div>
          <div class="share-url-row">
            <el-input :model-value="shareRow?.shareUrl" readonly />
            <el-button type="primary" @click="copyShare">复制</el-button>
          </div>
        </div>

        <el-button class="share-download" plain @click="downloadQr">
          下载二维码 PNG
        </el-button>

        <div class="share-tip">
          二维码内容为当前链接，编辑配置后此码不变、立即生效。
        </div>
      </div>
    </el-dialog>

    <!-- 统计弹窗 -->
    <StatsDialog v-model="statsVisible" :promo="statsRow" />
  </div>
</template>

<style scoped lang="scss">
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.head-actions {
  display: flex;
  gap: 10px;
}
.kind-intro {
  font-size: 13px;
  color: #909399;
  line-height: 1.7;
  margin-bottom: 12px;
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
  overflow-x: auto;
}
.empty-tip {
  color: #909399;
  padding: 48px 0;
  font-size: 14px;
  text-align: center;
}

/* ---------- 卡片 ---------- */
.promo-card {
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 10px;
  padding: 14px;
  margin-bottom: 14px;
  background: var(--el-bg-color);
  transition: box-shadow 0.2s;

  &:hover {
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
  }

  &.disabled {
    opacity: 0.65;
  }
}
.pc-head {
  display: flex;
  gap: 12px;
  align-items: flex-start;
}
.pc-thumb {
  width: 64px;
  height: 64px;
  border-radius: 8px;
  overflow: hidden;
  flex-shrink: 0;
  background: var(--el-fill-color-lighter);

  :deep(.el-image) {
    width: 100%;
    height: 100%;
  }
}
.pc-thumb-empty,
.pc-link-host {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  font-size: 11px;
  color: #909399;
  padding: 4px;
  word-break: break-all;
  text-align: center;
}
.pc-main {
  flex: 1;
  min-width: 0;
}
.pc-name {
  font-weight: 600;
  font-size: 15px;
  margin-bottom: 2px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pc-meta {
  font-size: 12px;
  color: #909399;
  margin-bottom: 6px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pc-dot {
  margin: 0 4px;
  color: #c0c4cc;
}
.pc-stats {
  display: flex;
  align-items: baseline;
  gap: 4px;
}
.pc-num {
  font-size: 20px;
  font-weight: 700;
  color: var(--el-color-primary);
}
.pc-num-label {
  font-size: 12px;
  color: #909399;
}
.pc-url {
  display: block;
  margin: 10px 0;
  font-size: 12px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pc-foot {
  border-top: 1px solid var(--el-border-color-lighter);
  padding-top: 8px;
}
/* 操作按钮独占一行并均分宽度：时间已并入 meta 行，避免挤压换行 */
.pc-ops {
  display: flex;
  align-items: center;
  justify-content: space-between;

  :deep(.el-button) {
    margin-left: 0;
    padding: 0 6px;
  }
}

/* ---------- 弹窗 ---------- */
.dialog-body {
  max-height: 66vh;
  overflow-y: auto;
  padding-right: 4px;
}
.form-tip-inline {
  font-size: 12px;
  color: #909399;
  line-height: 1.6;
  margin-top: 4px;
  width: 100%;
}
.items-wrap {
  width: 100%;
}
.item-row {
  display: flex;
  flex-direction: column;
  gap: 10px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
  padding: 10px;
  margin-bottom: 8px;
  background: var(--el-fill-color-lighter);
}
.item-top {
  display: flex;
  align-items: center;
  gap: 10px;
}
/* 规则行独占整行：权重/设备/时段一行放下不折行 */
.item-rule {
  padding-top: 10px;
  border-top: 1px dashed var(--el-border-color-lighter);
}
.item-preview {
  width: 56px;
  height: 56px;
  border-radius: 6px;
  overflow: hidden;
  flex-shrink: 0;
  background: #fff;
  border: 1px solid var(--el-border-color-lighter);
  display: flex;
  align-items: center;
  justify-content: center;

  :deep(.el-image) {
    width: 100%;
    height: 100%;
  }
}
.item-preview-empty {
  font-size: 12px;
  color: #c0c4cc;
}
.item-fields {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.item-line {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;

  :deep(.el-upload) {
    display: inline-flex;
  }
}
.item-label {
  font-size: 12px;
  color: #909399;
  white-space: nowrap;
}
.item-dash {
  color: #c0c4cc;
}
/* 行内操作横排：与所在输入行右端对齐 */
.item-ops {
  display: flex;
  align-items: center;
  flex-shrink: 0;
  margin-left: auto;

  :deep(.el-button + .el-button) {
    margin-left: 2px;
  }
}
.item-line > .el-input {
  flex: 1;
  min-width: 140px;
}
.stack {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
/* radio 按钮组（32px 高）与勾选框同行垂直对齐 */
.inline-row {
  display: flex;
  align-items: center;
  gap: 16px;

  :deep(.el-checkbox) {
    height: 32px;
    margin-right: 0;
  }
}

.share-box {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.share-name-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;

  .share-name {
    font-size: 15px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }
}

/* 二维码主视觉卡片：浅底 + 边框圆角，扫码区与说明集中呈现 */
.share-qr-card {
  width: 100%;
  padding: 18px 16px 12px;
  background: var(--el-fill-color-light);
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 10px;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.share-qr {
  width: 200px;
  height: 200px;
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
  margin-top: 10px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.share-field {
  width: 100%;
  margin-top: 14px;
  text-align: left;
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

.share-download {
  width: 100%;
  margin-top: 14px;
}

.share-tip {
  width: 100%;
  text-align: left;
  font-size: 12px;
  color: var(--el-text-color-placeholder);
  margin-top: 12px;
}
</style>
