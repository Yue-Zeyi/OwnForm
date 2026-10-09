<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { message } from "@/utils/message";
import { ElMessageBox } from "element-plus";
import {
  getSubmissions,
  getForm,
  deleteSubmission,
  batchDeleteSubmissions,
  restoreSubmissions,
  purgeSubmissions,
  reviewSubmissions,
  markSubmission,
  batchMarkData,
  exportUrl
} from "@/api/ownform";
import {
  isUploadUrls,
  toUploadUrls,
  FLAG_COLORS,
  flagSvgHtml
} from "@/utils/formRule";
import {
  DataAnalysis,
  Edit,
  Download,
  Setting,
  Delete,
  Picture
} from "@element-plus/icons-vue";

defineOptions({
  name: "FormData"
});

const route = useRoute();
const router = useRouter();
const formId = route.params.id as string;

const loading = ref(false);
const form = ref<any>({});
const fields = ref<any[]>([]);
const list = ref<any[]>([]);
const total = ref(0);
const lastVersionAt = ref<string>("");
const needReview = ref(false);
const payEnabled = ref(false);
const pending = ref(0);
const reviewTab = ref("1");
const selection = ref<any[]>([]);
// 批量标旗/备注/审核（与表单数据聚合页同一套操作）
const batchFlag = ref(0);
const batchRemark = ref("");
const batchStatus = ref(1);
const applying = ref(false);
const reviewing = ref(false);
const range = ref<[string, string] | null>(null);
// 当前用户对该表单的数据权限（owner 可管理；scope=own 仅自己渠道数据）
const perm = ref<any>({ owner: true, scope: "all", canExport: true });
const canEdit = computed(() => !!perm.value.owner);

/* 提交编辑：仅 owner，可改文本类字段；上传附件保持原值 */
const editVisible = ref(false);
const editSaving = ref(false);
const editForm = ref<any>({});
function openEdit() {
  if (!detailRow.value) return;
  const data: any = {};
  fields.value.forEach((f: any) => {
    const v = detailRow.value?.data?.[f.field];
    // 上传/附件字段保持原值不进入编辑表单
    if (isUploadUrls(v)) return;
    data[f.field] = v;
  });
  editForm.value = data;
  editVisible.value = true;
}
async function saveEdit() {
  if (!detailRow.value) return;
  editSaving.value = true;
  try {
    const d = await editSubmission(formId, detailRow.value.id, editForm.value);
    detailRow.value.data = d.data || editForm.value;
    const li = list.value.find(x => x.id === detailRow.value.id);
    if (li) li.data = detailRow.value.data;
    message("已保存", { type: "success" });
    editVisible.value = false;
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  editSaving.value = false;
}
// 渠道筛选下拉（own 视角后端只返回自己的渠道）
const channels = ref<any[]>([]);
const query = reactive<any>({
  page: 1,
  size: 20,
  keyword: "",
  field: "",
  value: "",
  flag: "" as number | "",
  pay_status: "" as number | "",
  channel_id: "" as number | "",
  start: "",
  end: "",
  sort: "",
  order: "",
  deleted: 0
});
const isRecycle = computed(() => query.deleted === 1);

const shareUrl = computed(
  () => location.origin + "/s/" + (form.value.slug || "")
);

const fmtTime = (s: string) =>
  s ? String(s).replace("T", " ").slice(0, 16) : "-";
const statusTag = (s: number) =>
  s === 1 ? "success" : s === 2 ? "warning" : "info";
const statusText = (s: number) =>
  s === 1 ? "收集中" : s === 2 ? "已关闭" : "草稿";

function displayValue(v: any): string {
  if (v === null || v === undefined) return "";
  if (Array.isArray(v)) {
    return v
      .map((x: any) =>
        x && typeof x === "object" ? x.name || x.url || "" : String(x)
      )
      .join("、");
  }
  if (typeof v === "object") return v.name || v.url || JSON.stringify(v);
  return String(v);
}

const isImage = (u: string) => /\.(png|jpe?g|gif|webp|bmp)$/i.test(u);

/**
 * 请求序号：load() 由查询/重置/翻页/切 Tab/删除后刷新高频触发，
 * 若先发的慢响应后到会覆盖后发的快响应，出现「翻页后数据没变」。
 * 响应回来时校验序号，过期结果直接丢弃。
 */
/** 提交时间列远端排序 */
function onSortChange({ prop, order }: any) {
  query.sort = order ? "created_at" : "";
  query.order = order === "ascending" ? "asc" : "desc";
  load(1);
}

let reqSeq = 0;

async function load(page?: number) {
  if (page) query.page = page;
  if (range.value && range.value.length === 2) {
    query.start = range.value[0];
    query.end = range.value[1];
  } else {
    query.start = query.end = "";
  }
  const seq = ++reqSeq;
  loading.value = true;
  try {
    const params: any = {
      page: query.page,
      size: query.size,
      keyword: query.keyword,
      field: query.field || "",
      value: query.value || "",
      start: query.start || "",
      end: query.end || "",
      sort: query.sort || "",
      order: query.order || ""
    };
    if (query.channel_id !== "") params.channel_id = query.channel_id;
    if (query.pay_status !== "" && query.pay_status !== undefined) params.pay_status = query.pay_status;
    if (query.deleted) {
      params.deleted = 1;
    } else {
      if (needReview.value) params.status = reviewTab.value;
      if (query.flag !== "") params.flag = query.flag;
    }
    const d = await getSubmissions(formId, params);
    // 已有更新的请求发出：丢弃本次过期响应
    if (seq !== reqSeq) return;
    list.value = d.list;
    total.value = d.total;
    lastVersionAt.value = d.lastVersionAt || "";
    fields.value = d.fields;
    perm.value = d.perm || perm.value;
    channels.value = d.channels || [];
    if (!colsInit) {
      // 列设置持久化：优先读本表单上次的选择
      let saved: string[] | null = null;
      try {
        saved = JSON.parse(localStorage.getItem("of_cols_" + formId) || "null");
      } catch {
        saved = null;
      }
      const valid =
        saved &&
        Array.isArray(saved) &&
        saved.every((k: string) => d.fields.some((f: any) => f.field === k));
      visibleCols.value = valid
        ? (saved as string[])
        : d.fields.slice(0, 6).map((f: any) => f.field);
      colsInit = true;
    }
    needReview.value = !!d.needReview;
    payEnabled.value = !!(d.payConfig && d.payConfig.enabled);
    pending.value = d.pending || 0;
    if (!form.value.id) form.value = await getForm(formId);
  } catch (e: any) {
    if (seq !== reqSeq) return;
    message(e.message, { type: "error" });
  }
  if (seq === reqSeq) loading.value = false;
}

function reset() {
  query.keyword = "";
  query.field = "";
  query.value = "";
  query.flag = "";
  query.channel_id = "";
  range.value = null;
  query.pay_status = "";
  query.sort = "";
  query.order = "";
  load(1);
}

/** 弹窗查看详情 */
const detailVisible = ref(false);
const detailRow = ref<any>(null);
// 附件快看弹窗
const filesVisible = ref(false);
const filesList = ref<string[]>([]);
function previewFiles(urls: string[]) {
  filesList.value = urls;
  filesVisible.value = true;
}
// 列设置：默认显示前 6 个字段（colsInit 防止翻页/搜索时重置用户已选列）
const visibleCols = ref<string[]>([]);
let colsInit = false;
const colSettingVisible = ref(false);
watch(visibleCols, v => {
  try {
    localStorage.setItem("of_cols_" + formId, JSON.stringify(v));
  } catch {
    /* 忽略 */
  }
});

const shownFields = computed(() => {
  const vis = visibleCols.value;
  return fields.value.filter(f => vis.includes(f.field));
});
function detail(row: any) {
  detailRow.value = row;
  detailVisible.value = true;
}

/** 独立页查看详情 */
function detailPage(row: any) {
  router.push(`/forms/data/${formId}/${row.id}`);
}

/** 旗标+备注（合并面板） */
const flagPopRow = ref<any>(null);
const flagRemark = ref("");
function flagColor(flag: number) {
  return (FLAG_COLORS[flag] || FLAG_COLORS[0]).color;
}
function flagTitle(flag: number) {
  return (FLAG_COLORS[flag] || FLAG_COLORS[0]).name;
}
async function saveFlagRemark(row: any) {
  try {
    const d = await markSubmission(formId, row.id, {
      flag: row.flag,
      remark: flagRemark.value
    });
    row.flag = d.flag;
    row.remark = d.remark;
    message("已保存", { type: "success" });
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

/** 备注编辑 */
const remarkVisible = ref(false);
const remarkRow = ref<any>(null);
const remarkText = ref("");
function openRemark(row: any) {
  remarkRow.value = row;
  remarkText.value = row.remark || "";
  remarkVisible.value = true;
}
async function saveRemark() {
  try {
    const d = await markSubmission(formId, remarkRow.value.id, {
      remark: remarkText.value
    });
    remarkRow.value.remark = d.remark;
    message("备注已保存", { type: "success" });
    remarkVisible.value = false;
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

async function del(row: any) {
  const ok = await ElMessageBox.confirm(
    "确定删除这条提交数据吗？删除后可在回收站还原。",
    "提示",
    {
      confirmButtonText: "确定",
      cancelButtonText: "取消",
      type: "warning"
    }
  )
    .then(() => true)
    .catch(() => false);
  if (!ok) return;
  try {
    await deleteSubmission(formId, row.id);
    message("已移入回收站", { type: "success" });
    load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

/** 回收站：还原 */
async function restoreRows(ids: number[]) {
  try {
    const d = await restoreSubmissions(formId, ids);
    message(`已还原 ${d.count} 条`, { type: "success" });
    load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

/** 回收站：彻底删除（不可恢复） */
async function purgeRows(ids: number[]) {
  const ok = await ElMessageBox.confirm(
    `彻底删除选中的 ${ids.length} 条数据？该操作不可恢复，提交数将同步扣回。`,
    "彻底删除",
    { confirmButtonText: "彻底删除", cancelButtonText: "取消", type: "error" }
  )
    .then(() => true)
    .catch(() => false);
  if (!ok) return;
  try {
    const d = await purgeSubmissions(formId, ids);
    message(`已彻底删除 ${d.count} 条`, { type: "success" });
    load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

async function batchDel() {
  const ok = await ElMessageBox.confirm(
    `确定删除选中的 ${selection.value.length} 条数据吗？删除后可在回收站还原。`,
    "提示",
    { confirmButtonText: "确定", cancelButtonText: "取消", type: "warning" }
  )
    .then(() => true)
    .catch(() => false);
  if (!ok) return;
  try {
    await batchDeleteSubmissions(
      formId,
      selection.value.map((x: any) => x.id)
    );
    message("已移入回收站", { type: "success" });
    load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

/** 批量标旗/备注：留空备注则不改备注，旗标选「无」且备注为空时仅清旗标 */
async function applyBatch() {
  if (!selection.value.length) return;
  applying.value = true;
  try {
    const d = await batchMarkData(
      selection.value.map((r: any) => ({ formId: Number(formId), id: r.id })),
      batchFlag.value,
      batchRemark.value
    );
    message("已更新 " + (d.updated || 0) + " 条提交", { type: "success" });
    selection.value = [];
    await load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  applying.value = false;
}

/** 批量审核（仅创建者/管理员可见） */
async function applyBatchReview() {
  if (!selection.value.length) return;
  reviewing.value = true;
  try {
    await reviewSubmissions(
      formId,
      selection.value.map((r: any) => r.id),
      batchStatus.value
    );
    message(batchStatus.value === 1 ? "已批量通过" : "已批量隐藏", {
      type: "success"
    });
    selection.value = [];
    await load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  reviewing.value = false;
}

async function review(row: any, status: number) {
  try {
    await reviewSubmissions(formId, [row.id], status);
    message(status === 1 ? "已通过" : "已隐藏", { type: "success" });
    load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

function doExport() {
  location.href = exportUrl(
    formId,
    query.keyword,
    query.start || "",
    query.end || "",
    {
      field: query.field,
      value: query.value,
      flag: query.flag,
      channel_id: query.channel_id
    }
  );
}

onMounted(() => load(1));
</script>

<template>
  <div v-loading="loading">
    <div class="page-head">
      <div>
        <div class="ctx-title">{{ form.title || "数据管理" }}</div>
        <div v-if="form.slug" class="page-sub">
          分享链接：
          <el-link type="primary" :href="shareUrl" target="_blank">
            {{ shareUrl }}
          </el-link>
          <el-tag
            :type="statusTag(form.status)"
            size="small"
            style="margin-left: 8px"
          >
            {{ statusText(form.status) }}
          </el-tag>
        </div>
      </div>
      <div>
        <el-button
          :icon="DataAnalysis"
          @click="router.push('/forms/stats/' + formId)"
        >
          统计分析
        </el-button>
        <el-button
          v-if="canEdit"
          :icon="Edit"
          @click="router.push('/design/' + formId)"
        >
          编辑表单
        </el-button>
      </div>
    </div>

    <el-card shadow="never">
      <el-tabs
        v-if="needReview"
        v-model="reviewTab"
        style="margin-bottom: 4px"
        @tab-change="load(1)"
      >
        <el-tab-pane label="已通过" name="1" />
        <el-tab-pane
          :label="'待审核' + (pending ? ' (' + pending + ')' : '')"
          name="0"
        />
      </el-tabs>

      <div class="filter-bar">
        <el-date-picker
          v-model="range"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          style="width: 260px"
        />
        <el-input
          v-model="query.keyword"
          placeholder="搜索内容 / 备注"
          clearable
          style="width: 200px"
          @keyup.enter="load(1)"
        />
        <el-select
          v-model="query.field"
          placeholder="按字段筛选"
          clearable
          style="width: 170px"
        >
          <el-option
            v-for="f in fields"
            :key="f.field"
            :label="f.title || f.field"
            :value="f.field"
          />
        </el-select>
        <el-input
          v-if="query.field"
          v-model="query.value"
          placeholder="字段包含的值"
          clearable
          style="width: 160px"
        />
        <el-select
          v-model="query.flag"
          placeholder="旗标筛选"
          clearable
          style="width: 130px"
          @change="load(1)"
        >
          <el-option label="无旗标" :value="0" />
          <el-option
            v-for="(c, k) in FLAG_COLORS"
            v-show="Number(k) > 0"
            :key="k"
            :label="c.name"
            :value="Number(k)"
          />
        </el-select>
        <el-select
          v-if="payEnabled"
          v-model="query.pay_status"
          placeholder="支付筛选"
          clearable
          style="width: 130px"
          @change="load(1)"
        >
          <el-option label="待支付" :value="1" />
          <el-option label="已支付" :value="2" />
          <el-option label="待核销" :value="3" />
          <el-option label="已退款" :value="4" />
        </el-select>
        <el-select
          v-if="channels.length"
          v-model="query.channel_id"
          placeholder="渠道筛选"
          clearable
          style="width: 150px"
          @change="load(1)"
        >
          <el-option
            v-for="c in channels"
            :key="c.id"
            :label="c.name"
            :value="c.id"
          />
        </el-select>
        <el-button type="primary" @click="load(1)">查询</el-button>
        <el-button @click="reset">重置</el-button>
        <div style="flex: 1" />
        <template v-if="isRecycle && canEdit">
          <el-button
            v-if="selection.length"
            type="warning"
            plain
            @click="restoreRows(selection.map((x: any) => x.id))"
          >
            还原选中({{ selection.length }})
          </el-button>
          <el-button
            v-if="selection.length"
            type="danger"
            plain
            @click="purgeRows(selection.map((x: any) => x.id))"
          >
            彻底删除({{ selection.length }})
          </el-button>
        </template>
        <template v-else-if="canEdit">
          <el-button
            v-if="selection.length"
            type="danger"
            plain
            @click="batchDel"
          >
            删除选中({{ selection.length }})
          </el-button>
        </template>
        <el-popover placement="bottom-end" :width="220" trigger="click">
          <template #reference>
            <el-button :icon="Setting">列设置</el-button>
          </template>
          <div class="col-setting">
            <div class="col-setting-title">选择展现的字段列</div>
            <el-checkbox-group v-model="visibleCols" class="col-checks">
              <el-checkbox v-for="f in fields" :key="f.field" :label="f.field">
                {{ f.title || f.field }}
              </el-checkbox>
            </el-checkbox-group>
            <div class="col-setting-ops">
              <el-button
                size="small"
                @click="visibleCols = fields.map((f: any) => f.field)"
                >全选</el-button
              >
              <el-button
                size="small"
                @click="
                  visibleCols = fields.slice(0, 3).map((f: any) => f.field)
                "
                >恢复默认</el-button
              >
            </div>
          </div>
        </el-popover>
        <el-button
          v-if="!isRecycle && perm.canExport"
          type="success"
          plain
          :icon="Download"
          @click="doExport"
        >
          导出 CSV
        </el-button>
        <el-button
          v-if="canEdit"
          :icon="Delete"
          @click="
            query.deleted = query.deleted ? 0 : 1;
            load(1);
          "
        >
          {{ isRecycle ? "返回列表" : "回收站" }}
        </el-button>
      </div>

      <el-alert
        v-if="isRecycle"
        type="warning"
        :closable="false"
        show-icon
        style="margin-bottom: 12px"
        title="回收站模式：这里是被删除的提交数据，可还原或彻底删除"
      />

      <!-- 批量操作条：标旗/备注/审核，与「表单数据」聚合页同一套 -->
      <div
        v-if="canEdit && !isRecycle && selection.length"
        class="batch-bar"
      >
        <span class="batch-count">已选 {{ selection.length }} 条</span>
        <el-select v-model="batchFlag" style="width: 110px">
          <el-option
            v-for="(c, k) in FLAG_COLORS"
            :key="k"
            :label="c.name"
            :value="Number(k)"
          />
        </el-select>
        <el-input
          v-model="batchRemark"
          placeholder="批量备注（留空则不改备注）"
          maxlength="500"
          style="width: 240px"
        />
        <el-button type="primary" :loading="applying" @click="applyBatch">
          应用到已选
        </el-button>
        <template v-if="needReview">
          <el-divider direction="vertical" />
          <span class="batch-count">审核</span>
          <el-select v-model="batchStatus" style="width: 110px">
            <el-option label="通过" :value="1" />
            <el-option label="隐藏" :value="0" />
          </el-select>
          <el-button
            type="primary"
            plain
            :loading="reviewing"
            @click="applyBatchReview"
          >
            应用审核
          </el-button>
        </template>
        <el-button @click="selection = []">取消选择</el-button>
      </div>
      <el-table
        :data="list"
        :empty-text="isRecycle ? '回收站为空' : '暂无提交数据'"
        @selection-change="(s: any[]) => (selection = s)"
        @sort-change="onSortChange"
      >
        <el-table-column v-if="canEdit" type="selection" width="45" />
        <el-table-column v-if="canEdit" width="56" align="center">
          <template #default="{ row }">
            <el-popover
              placement="bottom"
              :width="260"
              trigger="click"
              popper-class="flag-popper"
              @show="
                flagPopRow = row;
                flagRemark = row.remark || '';
              "
            >
              <template #reference>
                <span
                  class="flag-cell"
                  :title="row.flag > 0 ? flagTitle(row.flag) : '设置旗标'"
                  @click.stop="flagPopRow = row"
                  v-html="flagSvgHtml(flagColor(row.flag), row.flag > 0)"
                />
              </template>
              <div v-if="flagPopRow === row" class="flag-picker">
                <div class="flag-picker-title">旗标与备注</div>
                <div class="flag-colors">
                  <span
                    v-for="(c, k) in FLAG_COLORS"
                    :key="k"
                    class="flag-color-item"
                    :class="{ active: row.flag === Number(k) }"
                    :title="c.name"
                    @click="row.flag = Number(k)"
                    v-html="flagSvgHtml(c.color, Number(k) > 0)"
                  />
                </div>
                <el-input
                  v-model="flagRemark"
                  type="textarea"
                  :rows="2"
                  maxlength="500"
                  placeholder="备注（可与旗标一起保存）"
                  style="margin-top: 10px"
                />
                <el-button
                  type="primary"
                  size="small"
                  style="width: 100%; margin-top: 8px"
                  @click="saveFlagRemark(row)"
                >
                  保存
                </el-button>
              </div>
            </el-popover>
          </template>
        </el-table-column>
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column
          prop="createdAt"
          label="提交时间"
          width="160"
          sortable="custom"
          :sort-orders="['descending', 'ascending', null]"
        >
          <template #default="{ row }">{{ fmtTime(row.createdAt) }}</template>
        </el-table-column>
        <el-table-column
          v-if="channels.length"
          label="渠道"
          width="120"
          show-overflow-tooltip
        >
          <template #default="{ row }">
            <span v-if="row.channelName" class="cell-channel">
              {{ row.channelName }}
            </span>
            <span v-else class="cell-remark-empty">直接访问</span>
          </template>
        </el-table-column>
        <el-table-column label="备注" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.remark" class="cell-remark">{{ row.remark }}</span>
            <span v-else class="cell-remark-empty">—</span>
          </template>
        </el-table-column>
        <el-table-column
          v-for="f in shownFields"
          :key="f.field"
          :label="f.title || f.field"
          min-width="150"
          show-overflow-tooltip
        >
          <template #default="{ row }">
            <template v-if="isUploadUrls(row.data[f.field])">
              <span
                class="cell-files"
                @click="previewFiles(toUploadUrls(row.data[f.field]))"
              >
                <el-icon><Picture /></el-icon>
                {{ toUploadUrls(row.data[f.field]).length }} 张
              </span>
            </template>
            <span v-else>{{ displayValue(row.data[f.field]) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="设备" width="80" align="center">
          <template #default="{ row }">
            <el-tag
              size="small"
              effect="plain"
              :type="row.device === 'mobile' ? 'success' : 'info'"
            >
              {{
                row.device === "mobile"
                  ? "手机"
                  : row.device === "pc"
                    ? "电脑"
                    : "未知"
              }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column
          v-if="payEnabled"
          label="支付"
          width="86"
          align="center"
        >
          <template #default="{ row }">
            <el-tag v-if="row.payStatus === 2" size="small" type="success">已支付</el-tag>
            <el-tag v-else-if="row.payStatus === 1" size="small" type="info">待支付</el-tag>
            <el-tag v-else-if="row.payStatus === 3" size="small" type="danger">待核销</el-tag>
            <el-tag v-else-if="row.payStatus === 4" size="small" type="warning">已退款</el-tag>
            <span v-else>—</span>
          </template>
        </el-table-column>
        <el-table-column
          v-if="needReview"
          label="状态"
          width="90"
          align="center"
        >
          <template #default="{ row }">
            <el-tag
              size="small"
              :type="row.status === 1 ? 'success' : 'warning'"
            >
              {{ row.status === 1 ? "已通过" : "待审核" }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column
          label="操作"
          :width="isRecycle ? 170 : needReview ? 310 : 210"
          fixed="right"
          class-name="op-nowrap"
        >
          <template #default="{ row }">
            <template v-if="isRecycle">
              <el-button v-if="canEdit" link type="warning" @click="restoreRows([row.id])"
                >还原</el-button
              >
              <el-button v-if="canEdit" link type="danger" @click="purgeRows([row.id])"
                >彻底删除</el-button
              >
            </template>
            <template v-else>
              <el-button link type="primary" @click="detail(row)"
                >详情</el-button
              >
              <el-button link type="primary" @click="detailPage(row)"
                >页面</el-button
              >
              <template v-if="needReview && canEdit">
                <el-button
                  v-if="row.status !== 1"
                  link
                  type="success"
                  @click="review(row, 1)"
                >
                  通过
                </el-button>
                <el-button v-else link type="warning" @click="review(row, 0)">
                  隐藏
                </el-button>
              </template>
              <el-button v-if="canEdit" link type="danger" @click="del(row)"
                >删除</el-button
              >
            </template>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination
          background
          layout="total, prev, pager, next, sizes"
          :total="total"
          :page-sizes="[20, 50, 100, 200]"
          :page-size="query.size"
          :current-page="query.page"
          @current-change="(p: number) => load(p)"
          @size-change="
            (s: number) => {
              query.size = s;
              load(1);
            }
          "
        />
      </div>
    </el-card>

    <!-- 详情抽屉（快速查看） -->
    <el-drawer
      v-model="detailVisible"
      title="提交详情（快速查看）"
      size="520px"
      class="quick-view-drawer"
    >
      <div v-if="detailRow" class="detail-list">
        <el-alert
          v-if="lastVersionAt && detailRow.createdAt < lastVersionAt"
          type="warning"
          :closable="false"
          show-icon
          style="margin-bottom: 12px"
          title="该提交来自旧版表单，字段结构可能与当前不一致"
        />
        <div class="detail-meta">
          <span>ID {{ detailRow.id }}</span>
          <span>{{ fmtTime(detailRow.createdAt) }}</span>
          <span
            >{{ detailRow.device === "mobile" ? "手机" : "电脑" }} ·
            {{ detailRow.ip }}</span
          >
        </div>
        <div v-for="f in fields" :key="f.field" class="detail-item">
          <div class="detail-label">{{ f.title || f.field }}</div>
          <div class="detail-value">
            <template v-if="isUploadUrls(detailRow.data[f.field])">
              <div
                v-for="(u, i) in toUploadUrls(detailRow.data[f.field])"
                :key="i"
              >
                <el-image
                  v-if="isImage(u)"
                  :src="u"
                  :preview-src-list="[u]"
                  fit="cover"
                  class="detail-img"
                  preview-teleported
                />
                <el-link v-else type="primary" :href="u" target="_blank">
                  {{ u.split("/").pop() }}
                </el-link>
              </div>
            </template>
            <template v-else>{{
              displayValue(detailRow.data[f.field]) || "—"
            }}</template>
          </div>
        </div>
        <div style="margin-top: 16px; text-align: center">
          <el-button v-if="canEdit" plain @click="openEdit">编辑提交</el-button>
          <el-button type="primary" plain @click="detailPage(detailRow)">
            进入完整页面查看
          </el-button>
        </div>
      </div>
    </el-drawer>
    <!-- 提交编辑弹窗 -->
    <el-dialog
      v-model="editVisible"
      title="编辑提交"
      width="560px"
      :close-on-click-modal="false"
    >
      <div v-loading="editSaving">
        <el-form label-width="110px">
          <el-form-item
            v-for="f in fields"
            :key="f.field"
            :label="f.title || f.field"
          >
            <template v-if="isUploadUrls(editForm[f.field])">
              <span class="edit-uploads-tip">上传附件不随编辑变更（如需更换请删除后由用户重传）</span>
            </template>
            <el-input
              v-else-if="f.type === 'textarea'"
              v-model="editForm[f.field]"
              type="textarea"
              :rows="3"
            />
            <el-input-number
              v-else-if="f.type === 'inputNumber'"
              v-model="editForm[f.field]"
            />
            <el-switch v-else-if="f.type === 'switch'" v-model="editForm[f.field]" />
            <el-input v-else v-model="editForm[f.field]" />
          </el-form-item>
        </el-form>
        <div class="edit-tip">提示：日期/图片等复杂字段暂不支持编辑，保持原值提交。</div>
      </div>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" :loading="editSaving" @click="saveEdit">
          保存
        </el-button>
      </template>
    </el-dialog>


    <!-- 附件快看弹窗 -->
    <el-dialog v-model="filesVisible" title="附件查看" width="560px">
      <div class="files-grid">
        <template v-for="(u, i) in filesList" :key="i">
          <el-image
            v-if="isImage(u)"
            :src="u"
            :preview-src-list="filesList"
            :initial-index="i"
            fit="cover"
            class="file-thumb"
            preview-teleported
          />
          <el-link v-else type="primary" :href="u" target="_blank">{{
            u.split("/").pop()
          }}</el-link>
        </template>
      </div>
    </el-dialog>

    <!-- 备注弹窗 -->
    <el-dialog v-model="remarkVisible" title="提交备注" width="440px">
      <el-input
        v-model="remarkText"
        type="textarea"
        :rows="4"
        maxlength="500"
        show-word-limit
        placeholder="备注内容（500 字以内），方便统计与维护"
      />
      <template #footer>
        <el-button @click="remarkVisible = false">取消</el-button>
        <el-button type="primary" @click="saveRemark">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
:deep(.op-nowrap .cell) {
  white-space: nowrap;
}

.page-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 14px;
  gap: 12px;
  flex-wrap: wrap;
}

.ctx-title {
  font-size: 16px;
  font-weight: 600;
}

.page-sub {
  color: #909399;
  font-size: 13px;
  margin-top: 6px;
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
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

.flag-cell {
  display: inline-flex;
  cursor: pointer;
  vertical-align: middle;
}

.flag-picker-title {
  font-size: 13px;
  color: #606266;
  margin-bottom: 10px;
  text-align: center;
}

.flag-colors {
  display: flex;
  justify-content: space-between;
  gap: 4px;
}

.flag-color-item {
  display: inline-flex;
  cursor: pointer;
  padding: 4px;
  border-radius: 6px;
  border: 1.5px solid transparent;
  transition: all 0.15s;
}

.flag-color-item:hover {
  background: #f0f2f5;
}

.flag-color-item.active {
  border-color: var(--el-color-primary);
  background: #ecf5ff;
}

.cell-remark {
  color: var(--el-color-warning);
}

.cell-channel {
  color: var(--el-color-primary);
}

.cell-remark-empty {
  color: #c0c4cc;
}

.cell-files {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: var(--el-color-primary);
}

.detail-meta {
  display: flex;
  gap: 14px;
  color: #909399;
  font-size: 12px;
  padding-bottom: 12px;
  border-bottom: 1px solid #f0f0f0;
}

.detail-item {
  padding: 12px 0;
  border-bottom: 1px dashed #f0f0f0;
}

.detail-label {
  color: #909399;
  font-size: 12px;
  margin-bottom: 6px;
}

.detail-value {
  font-size: 14px;
  line-height: 1.7;
  word-break: break-all;
  white-space: pre-wrap;
}

.detail-img {
  width: 72px;
  height: 72px;
  border-radius: 6px;
  margin: 4px 4px 0 0;
}
</style>

<style scoped lang="scss">
.detail-meta {
  display: flex;
  gap: 14px;
  color: #909399;
  font-size: 12px;
  padding-bottom: 12px;
  border-bottom: 1px solid #f0f0f0;
}

.detail-item {
  padding: 12px 0;
  border-bottom: 1px dashed #f0f0f0;
}

.detail-label {
  color: #909399;
  font-size: 12px;
  margin-bottom: 6px;
}

.detail-value {
  font-size: 14px;
  line-height: 1.7;
  word-break: break-all;
  white-space: pre-wrap;
}

.detail-img {
  width: 72px;
  height: 72px;
  border-radius: 6px;
  margin: 4px 4px 0 0;
}

</style>

<style scoped lang="scss">
.col-setting-title {
  font-size: 13px;
  color: #606266;
  margin-bottom: 8px;
}

.col-checks {
  display: flex;
  flex-direction: column;
  max-height: 220px;
  overflow: auto;
}

.col-setting-ops {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
}

.cell-files {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: var(--el-color-primary);
  cursor: pointer;
}

.files-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.file-thumb {
  width: 110px;
  height: 110px;
  border-radius: 8px;
  border: 1px solid #ebeef5;
  cursor: zoom-in;
}
</style>

<style lang="scss">
/* 批量操作条（与表单数据聚合页同一套样式） */
.batch-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 12px;
  padding: 8px 12px;
  background: var(--el-color-primary-light-9);
  border: 1px solid var(--el-color-primary-light-5);
  border-radius: 8px;
}

.batch-count {
  font-size: 13px;
  color: var(--el-text-color-primary);
}
</style>
