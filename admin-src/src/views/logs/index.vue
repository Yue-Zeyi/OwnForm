<script setup lang="ts">
import { ref, reactive, computed, onMounted } from "vue";
import { message } from "@/utils/message";
import { ElMessageBox } from "element-plus";
import { http } from "@/utils/http";

// props.mode：fill=提交日志（固定 fill 模块）；operate=操作日志（排除 fill）
const props = defineProps<{
  mode?: "fill" | "operate";
}>();

const isFillLog = computed(() => props.mode === "fill");

const loading = ref(false);
const list = ref<any[]>([]);
const total = ref(0);
const modules = ref<Record<string, string>>({});
const query = reactive({
  page: 1,
  size: 30,
  module: isFillLog.value ? "fill" : "",
  status: "" as number | "",
  keyword: "",
  start: "",
  end: "",
  range: null as [string, string] | null
});
const detailRow = ref<any>(null);
const detailVisible = ref(false);

const fmtTime = (s: string) =>
  s ? String(s).replace("T", " ").slice(0, 19) : "-";

async function load(page?: number) {
  if (page) query.page = page;
  if (query.range?.length === 2) {
    query.start = query.range[0] + " 00:00:00";
    query.end = query.range[1] + " 23:59:59";
  } else {
    query.start = query.end = "";
  }
  loading.value = true;
  try {
    const params: any = {
      page: query.page,
      size: query.size,
      module: query.module,
      keyword: query.keyword,
      start: query.start,
      end: query.end,
      exclude_fill: isFillLog.value ? 0 : 1
    };
    if (query.status !== "") params.status = query.status;
    const d = await http.get<any, any>("/logs", { params });
    list.value = d.list;
    total.value = d.total;
    modules.value = d.modules || {};
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  loading.value = false;
}

function moduleText(m: string) {
  return modules.value[m] || m;
}

function reset() {
  query.module = "";
  query.status = "";
  query.keyword = "";
  query.range = null;
  load(1);
}

onMounted(() => load(1));
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <div class="filter-bar">
        <el-select
          v-if="!isFillLog"
          v-model="query.module"
          placeholder="全部模块"
          clearable
          style="width: 140px"
          @change="load(1)"
        >
          <el-option
            v-for="(label, key) in modules"
            :key="key"
            :label="label"
            :value="key"
          />
        </el-select>
        <el-select
          v-model="query.status"
          placeholder="全部状态"
          clearable
          style="width: 120px"
          @change="load(1)"
        >
          <el-option label="成功" :value="1" />
          <el-option label="失败/拦截" :value="0" />
        </el-select>
        <el-date-picker
          v-model="query.range"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          style="width: 260px"
        />
        <el-input
          v-model="query.keyword"
          placeholder="搜索账号/动作/详情"
          clearable
          style="width: 200px"
          @keyup.enter="load(1)"
        />
        <el-button type="primary" @click="load(1)">查询</el-button>
        <el-button @click="reset">重置</el-button>
      </div>

      <el-table :data="list" empty-text="暂无日志">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column label="时间" width="170">
          <template #default="{ row }">{{ fmtTime(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="模块" width="110">
          <template #default="{ row }">
            <el-tag size="small" effect="plain">{{
              moduleText(row.module)
            }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="动作" width="150">
          <template #default="{ row }">
            <span :class="row.status === 1 ? '' : 'log-fail'">{{
              row.action
            }}</span>
          </template>
        </el-table-column>
        <el-table-column
          prop="detail"
          label="详情"
          min-width="260"
          show-overflow-tooltip
        />
        <el-table-column prop="username" label="操作人" width="110" />
        <el-table-column prop="ip" label="IP" width="130" />
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag
              size="small"
              :type="row.status === 1 ? 'success' : 'danger'"
              effect="light"
            >
              {{ row.status === 1 ? "成功" : "失败" }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="80" fixed="right">
          <template #default="{ row }">
            <el-button
              link
              type="primary"
              @click="
                detailRow = row;
                detailVisible = true;
              "
            >
              详情
            </el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <el-pagination
          background
          layout="total, prev, pager, next, sizes"
          :total="total"
          :page-sizes="[30, 50, 100, 200]"
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

    <el-drawer v-model="detailVisible" title="日志详情" size="440px">
      <div v-if="detailRow" class="detail-list">
        <div
          v-for="col in [
            ['ID', detailRow.id],
            ['时间', fmtTime(detailRow.created_at)],
            ['模块', moduleText(detailRow.module)],
            ['动作', detailRow.action],
            ['状态', detailRow.status === 1 ? '成功' : '失败/拦截'],
            ['操作人', detailRow.username + '（#' + detailRow.user_id + '）'],
            ['IP', detailRow.ip],
            ['User-Agent', detailRow.user_agent]
          ]"
          :key="col[0]"
          class="detail-item"
        >
          <div class="detail-label">{{ col[0] }}</div>
          <div class="detail-value">{{ col[1] || "—" }}</div>
        </div>
        <div class="detail-item">
          <div class="detail-label">详情</div>
          <div class="detail-value">{{ detailRow.detail || "—" }}</div>
        </div>
        <template v-if="detailRow.request || detailRow.response">
          <div class="detail-item">
            <div class="detail-label">请求报文</div>
            <pre class="payload-pre">{{ detailRow.request || "—" }}</pre>
          </div>
          <div class="detail-item">
            <div class="detail-label">响应报文</div>
            <pre class="payload-pre">{{ detailRow.response || "—" }}</pre>
          </div>
          <div class="detail-item">
            <div class="detail-label">耗时</div>
            <div class="detail-value">{{ detailRow.duration || 0 }} ms</div>
          </div>
        </template>
      </div>
    </el-drawer>
  </div>
</template>

<style scoped lang="scss">
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;
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

.log-fail {
  color: var(--el-color-danger);
}

.detail-item {
  padding: 10px 0;
  border-bottom: 1px dashed #f0f0f0;
}

.detail-label {
  color: #909399;
  font-size: 12px;
  margin-bottom: 4px;
}

.detail-value {
  font-size: 14px;
  line-height: 1.6;
  word-break: break-all;
}
</style>

<style scoped lang="scss">
.payload-pre {
  margin: 0;
  padding: 10px;
  background: #f5f7fa;
  border-radius: 6px;
  font-size: 12px;
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-all;
  max-height: 300px;
  overflow: auto;
  font-family: Menlo, Consolas, monospace;
}
</style>
