<script setup lang="ts">
import { ref, reactive, onMounted, onBeforeUnmount } from "vue";
import { useRouter } from "vue-router";
import { message } from "@/utils/message";
import { ElMessageBox } from "element-plus";
import {
  getPages,
  createPage,
  deletePage,
  setPageStatus,
  restorePage,
  purgePage
} from "@/api/pages";
import { getForms } from "@/api/ownform";
import { copyWithTip } from "@/utils/clipboard";
import {
  Search,
  Plus,
  Edit,
  Delete,
  Share,
  View
} from "@element-plus/icons-vue";

const router = useRouter();

const loading = ref(false);
const list = ref<any[]>([]);
const total = ref(0);
const query = reactive({
  page: 1,
  size: 20,
  keyword: "",
  status: "" as any,
  deleted: 0
});

async function load(page?: number) {
  if (page) query.page = page;
  loading.value = true;
  try {
    const d = await getPages({
      page: query.page,
      size: query.size,
      keyword: query.keyword,
      status: query.status === "" ? "" : query.status,
      deleted: query.deleted
    });
    list.value = d.list;
    total.value = d.total;
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  loading.value = false;
}

function search() {
  query.page = 1;
  load();
}

function reset() {
  query.keyword = "";
  query.status = "";
  search();
}

function toggleDeleted() {
  query.deleted = query.deleted ? 0 : 1;
  search();
}

/* 新建：先落库拿到 id，再进编辑器 */
async function create() {
  try {
    const d = await createPage({
      title: "未命名页面",
      description: "",
      content: "",
      contentType: 1,
      settings: {}
    });
    router.push("/page/edit/" + d.id);
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

function edit(row: any) {
  router.push("/page/edit/" + row.id);
}

/**
 * 状态开关：开 = 发布(1)，关 = 下线(2)
 *
 * switchingIds 记录请求中的行，开关显示 loading：
 * 一来防止连点重复提交，二来请求失败时开关不会先翻转再弹回。
 */
const switchingIds = ref(new Set<number>());

async function toggleStatus(row: any, on: boolean) {
  const target = on ? 1 : 2;
  switchingIds.value.add(row.id);
  try {
    await setPageStatus(row.id, target);
    row.status = target;
    message(on ? "页面已发布" : "页面已下线", { type: "success" });
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  switchingIds.value.delete(row.id);
}

async function remove(row: any) {
  try {
    await ElMessageBox.confirm(
      `确定删除页面「${row.title}」？删除后可在回收站恢复。`,
      "删除确认",
      {
        type: "warning",
        confirmButtonText: "确定删除",
        cancelButtonText: "取消"
      }
    );
    await deletePage(row.id);
    message("已删除", { type: "success" });
    load();
  } catch (e: any) {
    if (e !== "cancel") message(e.message, { type: "error" });
  }
}

async function restore(row: any) {
  try {
    await restorePage(row.id);
    message("已恢复为草稿", { type: "success" });
    load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

async function purge(row: any) {
  try {
    await ElMessageBox.confirm(
      `彻底删除后无法恢复，确定删除「${row.title}」？`,
      "彻底删除",
      { type: "error", confirmButtonText: "彻底删除", cancelButtonText: "取消" }
    );
    await purgePage(row.id);
    message("已彻底删除", { type: "success" });
    load();
  } catch (e: any) {
    if (e !== "cancel") message(e.message, { type: "error" });
  }
}

/* ---------- 分享（链接 + 二维码） ---------- */
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

/**
 * 加载本地 qrcode UMD 库（/static/vendor/qrcode.min.js）
 *
 * 走运行时注入而非 import：用的是 UMD 全局 window.QRCode，
 * 打进主包会白白增加体积，而二维码只在分享弹窗打开时才需要。
 */
function loadQrcodeLib(): Promise<void> {
  return new Promise(resolve => {
    if ((window as any).QRCode) return resolve();
    const s = document.createElement("script");
    s.src = "/static/vendor/qrcode.min.js";
    s.onload = () => resolve();
    // 加载失败也要 resolve，否则弹窗会一直挂着不渲染
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

function copyShare() {
  copyWithTip(shareRow.value.shareUrl, "链接已复制");
}

function openPage(row: any) {
  window.open(row.shareUrl, "_blank", "noopener");
}

function fmtTime(s: string) {
  return s ? String(s).slice(0, 16) : "-";
}

onMounted(() => load());

// 对话框关闭后延时回调仍可能触发，必须清理
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
      <div>
        <el-button
          v-if="!query.deleted"
          type="primary"
          :icon="Plus"
          @click="create"
        >
          新建页面
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
        <el-input
          v-model="query.keyword"
          placeholder="搜索页面标题"
          clearable
          style="width: 220px"
          @keyup.enter="search"
        >
          <template #prefix
            ><el-icon><Search /></el-icon
          ></template>
        </el-input>
        <el-select
          v-model="query.status"
          placeholder="全部状态"
          clearable
          style="width: 130px"
          @change="search"
        >
          <el-option label="草稿" :value="0" />
          <el-option label="已发布" :value="1" />
          <el-option label="已下线" :value="2" />
        </el-select>
        <el-button type="primary" @click="search">查询</el-button>
        <el-button @click="reset">重置</el-button>
      </div>

      <el-table v-loading="loading" :data="list" stripe>
        <el-table-column label="标题" min-width="220">
          <template #default="{ row }">
            <div class="cell-title">{{ row.title }}</div>
            <div v-if="row.description" class="cell-desc">
              {{ row.description }}
            </div>
          </template>
        </el-table-column>

        <el-table-column
          v-if="!query.deleted"
          label="状态"
          width="100"
          align="center"
        >
          <template #default="{ row }">
            <!--
              与表单列表一致：开关直接发布/下线，操作列不再重复放按钮。
              草稿（status=0）视为关；切到开即发布，关即下线。
            -->
            <el-switch
              :model-value="row.status === 1"
              inline-prompt
              active-text="已发布"
              inactive-text="未发布"
              :loading="switchingIds.has(row.id)"
              @change="(v: boolean) => toggleStatus(row, v)"
            />
          </template>
        </el-table-column>

        <el-table-column
          v-if="!query.deleted"
          label="访问量"
          width="90"
          align="center"
        >
          <template #default="{ row }">{{ row.view_count }}</template>
        </el-table-column>

        <el-table-column v-if="!query.deleted" label="分享链接" min-width="260">
          <template #default="{ row }">
            <el-link
              v-if="row.shareUrl"
              type="primary"
              :underline="false"
              @click="copyWithTip(row.shareUrl, '链接已复制')"
            >
              {{ row.shareUrl }}
            </el-link>
            <span v-else>-</span>
          </template>
        </el-table-column>

        <el-table-column label="创建时间" width="140">
          <template #default="{ row }">
            <span class="cell-time">{{ fmtTime(row.created_at) }}</span>
          </template>
        </el-table-column>

        <!-- 操作列与表单管理页一致：文字链接按钮，宽度容纳 4 个按钮 -->
        <el-table-column
          label="操作"
          width="290"
          fixed="right"
          class-name="op-nowrap"
        >
          <template #default="{ row }">
            <template v-if="!query.deleted">
              <el-button link type="primary" :icon="Edit" @click="edit(row)">
                编辑
              </el-button>
              <el-button
                link
                type="primary"
                :icon="View"
                @click="openPage(row)"
              >
                预览
              </el-button>
              <el-button link type="success" :icon="Share" @click="share(row)">
                分享
              </el-button>
              <el-button link type="danger" :icon="Delete" @click="remove(row)">
                删除
              </el-button>
            </template>
            <template v-else>
              <el-button type="primary" link @click="restore(row)">
                恢复
              </el-button>
              <el-button type="danger" link @click="purge(row)">
                彻底删除
              </el-button>
            </template>
          </template>
        </el-table-column>

        <template #empty>
          <div class="empty-tip">
            {{ query.deleted ? "回收站为空" : "还没有页面，点击右上角新建" }}
          </div>
        </template>
      </el-table>

      <el-pagination
        class="pager"
        layout="total, prev, pager, next"
        :total="total"
        :page-size="query.size"
        :current-page="query.page"
        @current-change="load"
      />
    </el-card>

    <el-dialog v-model="shareVisible" title="分享页面" width="460px">
      <div v-if="shareRow" class="share-box">
        <div class="share-title">{{ shareRow.title }}</div>
        <div class="share-url-row">
          <el-input :model-value="shareRow.shareUrl" readonly />
          <el-button type="primary" @click="copyShare">复制</el-button>
        </div>
        <div class="share-qr-wrap">
          <div ref="qrEl" class="share-qr" />
          <div class="share-qr-tip">扫码访问</div>
        </div>
        <el-alert
          v-if="shareRow.status !== 1"
          type="warning"
          :closable="false"
          title="页面当前未发布，访客打开将提示页面不存在"
        />
      </div>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
/*
 * 布局类名与表单管理页保持一致。
 * 表单页用的是 scoped 样式，不会作用到本页，因此这里必须重新声明，
 * 否则 .page-head / .filter-bar / .pager 没有任何布局规则，
 * 筛选栏会横向溢出、卡片紧贴按钮、表格列被挤压。
 */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
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

/* 分页用 flex 居右，并允许横向滚动，避免撑破容器 */
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 14px;
  overflow-x: auto;
}

/* 操作列不换行 */
:deep(.op-nowrap .cell) {
  white-space: nowrap;
}

/* 卡片留白 + 表格区域可横向滚动，而不是把列压扁 */
:deep(.el-card__body) {
  padding: 16px;
  overflow-x: auto;
}

.cell-title {
  font-weight: 600;
}
.cell-desc {
  color: #909399;
  font-size: 12px;
  margin-top: 3px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 320px;
}
.empty-tip {
  color: #909399;
  padding: 28px 0;
  font-size: 14px;
}
/* 时间列必须单行：列宽被压缩时 nowrap 会让日期竖排成一列，很难看 */
.cell-time {
  white-space: nowrap;
  color: #909399;
  font-size: 13px;
}
.share-box {
  text-align: center;
}
.share-title {
  font-weight: 600;
  margin-bottom: 12px;
}
.share-url-row {
  display: flex;
  gap: 8px;
}
.share-qr-wrap {
  margin-top: 16px;
}
.share-qr {
  display: inline-block;
  min-height: 160px;
}
.share-qr-tip {
  color: #909399;
  font-size: 12px;
  margin-top: 8px;
}
</style>
