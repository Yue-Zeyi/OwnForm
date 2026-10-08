<script setup lang="ts">
import { ref, reactive, onMounted } from "vue";
import { Search, Download } from "@element-plus/icons-vue";
import { useRouter } from "vue-router";
import { message } from "@/utils/message";
import {
  getAllData,
  getForm,
  getSubmission,
  markSubmission,
  batchMarkData,
  batchReviewData,
  editSubmission,
  exportAllDataUrl
} from "@/api/ownform";
import {
  extractFields,
  toUploadUrls,
  FLAG_COLORS
} from "@/utils/formRule";

defineOptions({
  name: "FormDataAll"
});

const loading = ref(false);
const list = ref<any[]>([]);
const total = ref(0);
const forms = ref<any[]>([]);
const query = reactive<any>({
  formId: "",
  keyword: "",
  range: [],
  status: "",
  page: 1,
  size: 20
});

/** 多选批量标旗/备注 */
const selected = ref<any[]>([]);
const applying = ref(false);
const reviewing = ref(false);
const batchStatus = ref<number>(1);
const batchFlag = ref(0);
const batchRemark = ref("");

/** 详情抽屉 */
const drawer = ref(false);
const detailLoading = ref(false);
const detailFields = ref<any[]>([]);
const detail = ref<any>({ data: {}, createdAt: "" });

function isImage(u: string) {
  return /\.(png|jpe?g|gif|webp|bmp)$/i.test(u);
}

function displayValue(v: any): string {
  if (v === null || v === undefined) return "";
  if (Array.isArray(v)) {
    return v.map((x: any) => (x && typeof x === "object" ? x.name || x.url || "" : String(x))).join("、");
  }
  if (typeof v === "object") return v.name || v.url || JSON.stringify(v);
  return String(v);
}

async function load() {
  loading.value = true;
  try {
    const d = await getAllData({
      formId: query.formId || undefined,
      keyword: query.keyword || undefined,
      start: query.range?.[0] || undefined,
      end: query.range?.[1] ? query.range[1] + " 23:59:59" : undefined,
      status: query.status === "" ? undefined : query.status,
      page: query.page,
      size: query.size
    });
    list.value = d.list || [];
    total.value = d.total || 0;
    forms.value = d.forms || [];
    selected.value = [];
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  loading.value = false;
}

function search() {
  query.page = 1;
  load();
}

function resetQuery() {
  query.formId = "";
  query.keyword = "";
  query.range = [];
  query.status = "";
  query.page = 1;
  load();
}

/** 详情抽屉：拉提交内容 + 该表单的字段定义（还原字段标题） */
async function openDetail(row: any) {
  drawer.value = true;
  detailLoading.value = true;
  detailFields.value = [];
  detail.value = { data: {}, createdAt: row.createdAt };
  try {
    const [frm, det] = await Promise.all([
      getForm(row.formId),
      getSubmission(row.formId, row.id)
    ]);
    detailFields.value = extractFields(frm.fields || []);
    detail.value = det;
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  detailLoading.value = false;
}

const router = useRouter();

/** 旗标配色/图标（与单表单数据页同一套） */
function flagColor(flag: number) {
  return (FLAG_COLORS[flag] || FLAG_COLORS[0]).color;
}
function flagTitle(flag: number) {
  return (FLAG_COLORS[flag] || FLAG_COLORS[0]).name;
}

/** 旗标+备注 popover：绑定当前行；保存走该行所属表单的 mark 接口 */
const flagPopRow = ref<any>(null);
const flagRemark = ref("");
function openFlagPop(row: any) {
  flagPopRow.value = row;
  flagRemark.value = row.remark || "";
}
async function saveFlagRemark(row: any) {
  try {
    const d = await markSubmission(row.formId, row.id, {
      flag: row.flag,
      remark: flagRemark.value
    });
    row.flag = d.flag;
    row.remark = d.remark;
    flagRemark.value = d.remark;
    message("已保存", { type: "success" });
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

/** 跳转该表单的完整数据页 */
function goFormData(row: any) {
  router.push("/forms/data/" + row.formId);
}

async function applyBatch() {
  if (!selected.value.length) return;
  applying.value = true;
  try {
    const d = await batchMarkData(
      selected.value.map(r => ({ formId: r.formId, id: r.id })),
      batchFlag.value,
      batchRemark.value
    );
    message("已更新 " + (d.updated || 0) + " 条提交", { type: "success" });
    selected.value = [];
    await load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  applying.value = false;
}

async function applyBatchReview() {
  if (!selected.value.length) return;
  reviewing.value = true;
  try {
    const d = await batchReviewData(
      selected.value.map(r => ({ formId: r.formId, id: r.id })),
      batchStatus.value
    );
    message("已审核 " + (d.updated || 0) + " 条提交", { type: "success" });
    selected.value = [];
    await load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  reviewing.value = false;
}

/** 聚合导出：与列表同一套筛选，直接下载 CSV */
function exportCsv() {
  const p = new URLSearchParams();
  if (query.formId) p.append("formId", String(query.formId));
  if (query.keyword) p.append("keyword", query.keyword);
  if (query.range?.[0]) p.append("start", query.range[0]);
  if (query.range?.[1]) p.append("end", query.range[1] + " 23:59:59");
  if (query.status !== "") p.append("status", String(query.status));
  window.open(exportAllDataUrl(Object.fromEntries(p)) , "_blank");
}

onMounted(load);
</script>

<template>
  <div>
    <el-card shadow="never" class="mb-3">
      <div class="filter-bar">
        <el-select
          v-model="query.formId"
          clearable
          filterable
          placeholder="全部表单"
          style="width: 210px"
          @change="search"
        >
          <el-option v-for="f in forms" :key="f.id" :label="f.title" :value="f.id" />
        </el-select>
        <el-input
          v-model="query.keyword"
          placeholder="搜索提交内容 / 备注 / 表单名"
          clearable
          style="width: 240px"
          @keyup.enter="search"
        />
        <el-date-picker
          v-model="query.range"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          style="width: 240px"
        />
        <el-select
          v-model="query.status"
          clearable
          placeholder="状态"
          style="width: 110px"
          @change="search"
        >
          <el-option label="正常" value="1" />
          <el-option label="待审核" value="0" />
        </el-select>
        <el-button type="primary" :icon="Search" @click="search">查询</el-button>
        <el-button @click="resetQuery">重置</el-button>
        <el-button :icon="Download" @click="exportCsv">导出 CSV</el-button>
      </div>
    </el-card>

    <el-card shadow="never">
      <div v-if="selected.length" class="batch-bar">
        <span class="batch-count">已选 {{ selected.length }} 条</span>
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
        <el-button @click="selected = []">取消选择</el-button>
      </div>
      <el-table
        v-loading="loading"
        :data="list"
        @selection-change="(s: any[]) => (selected = s)"
      >
        <el-table-column type="selection" width="42" />
        <el-table-column label="ID" width="80">
          <template #default="{ row }">{{ row.id }}</template>
        </el-table-column>
        <el-table-column label="表单" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.formTitle }}</template>
        </el-table-column>
        <el-table-column label="提交内容摘要" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">{{ row.summary || "—" }}</template>
        </el-table-column>
        <el-table-column width="52" align="center">
          <template #default="{ row }">
            <el-popover
              placement="bottom"
              :width="240"
              trigger="click"
              :persistent="false"
              @show="openFlagPop(row)"
            >
              <template #reference>
                <span
                  class="flag-cell"
                  :title="row.flag > 0 ? flagTitle(row.flag) : '设置旗标与备注'"
                  @click.stop="openFlagPop(row)"
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
        <el-table-column label="设备" width="80" align="center">
          <template #default="{ row }">
            <el-tag size="small" effect="plain" :type="row.device === 'mobile' ? 'success' : 'info'">
              {{ row.device === "mobile" ? "手机" : row.device === "pc" ? "电脑" : "未知" }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag size="small" :type="row.status === 1 ? 'success' : 'warning'">
              {{ row.status === 1 ? "正常" : "待审核" }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="备注" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.remark" class="cell-remark">{{ row.remark }}</span>
            <span v-else class="cell-remark-empty">—</span>
          </template>
        </el-table-column>
        <el-table-column label="提交时间" width="170">
          <template #default="{ row }">{{ row.createdAt }}</template>
        </el-table-column>
        <el-table-column label="操作" width="120" fixed="right" align="center">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetail(row)">详情</el-button>
            <el-button link type="primary" @click="goFormData(row)">数据</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination
          background
          layout="total, prev, pager, next, sizes"
          :total="total"
          v-model:current-page="query.page"
          v-model:page-size="query.size"
          :page-sizes="[20, 50, 100]"
          @current-change="load"
          @size-change="() => { query.page = 1; load(); }"
        />
      </div>
    </el-card>

    <!-- 提交详情抽屉（跨表单：按提交所属表单还原字段标题） -->
    <el-drawer v-model="drawer" title="提交详情" size="480px">
      <div v-loading="detailLoading">
        <div class="detail-meta">
          <span>ID {{ detail.id }}</span>
          <span>{{ detail.createdAt }}</span>
          <span v-if="detail.flag > 0" class="detail-flag">{{
            flagTitle(detail.flag)
          }}</span>
        </div>
        <div v-for="f in detailFields" :key="f.field" class="detail-item">
          <div class="detail-label">{{ f.title || f.field }}</div>
          <div class="detail-value">
            <template v-if="toUploadUrls(detail.data[f.field]).length">
              <div v-for="(u, i) in toUploadUrls(detail.data[f.field])" :key="i">
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
            <template v-else>{{ displayValue(detail.data[f.field]) || "—" }}</template>
          </div>
        </div>
      </div>
    </el-drawer>
  </div>
</template>

<style scoped lang="scss">
.filter-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 14px;
}

.detail-meta {
  display: flex;
  gap: 14px;
  color: #909399;
  font-size: 13px;
  margin-bottom: 14px;
}

.detail-item {
  padding: 10px 0;
  border-bottom: 1px dashed #f0f0f0;
}

.detail-label {
  color: #909399;
  font-size: 12.5px;
  margin-bottom: 4px;
}

.detail-value {
  font-size: 14px;
  color: #303133;
  word-break: break-all;
}

.detail-img {
  width: 72px;
  height: 72px;
  border-radius: 8px;
}

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

.detail-flag {
  color: #e6a23c;
}

.flag-cell {
  cursor: pointer;
  display: inline-flex;
}

.flag-picker-title {
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 8px;
}

.flag-colors {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
}

.flag-color-item {
  cursor: pointer;
  display: inline-flex;
  padding: 3px;
  border: 1px solid transparent;
  border-radius: 6px;

  &:hover {
    background: #f5f7fa;
  }

  &.active {
    border-color: var(--el-color-primary);
    background: var(--el-color-primary-light-9);
  }
}

.cell-remark {
  color: #606266;
  font-size: 13px;
}

.cell-remark-empty {
  color: #c0c4cc;
}
</style>
