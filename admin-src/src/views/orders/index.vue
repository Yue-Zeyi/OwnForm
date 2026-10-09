<script setup lang="ts">
import { ref, reactive, onMounted } from "vue";
import { message } from "@/utils/message";
import { ElMessageBox } from "element-plus";
import {
  getOrders,
  getOrderStats,
  verifyOrder,
  refundOrder,
  cancelOrder
} from "@/api/ownform";

defineOptions({
  name: "Orders"
});

const loading = ref(false);
const list = ref<any[]>([]);
const total = ref(0);
const stats = ref<any>({});
const forms = ref<any[]>([]);
const query = reactive<any>({
  page: 1,
  size: 20,
  formId: "",
  status: "",
  keyword: "",
  range: []
});

const STATUS_META: Record<number, { text: string; type: string }> = {
  0: { text: "待支付", type: "info" },
  1: { text: "已支付", type: "success" },
  2: { text: "已取消", type: "info" },
  3: { text: "已退款", type: "warning" },
  4: { text: "待核销", type: "danger" }
};

const fmt = (s: string) => (s ? String(s).replace("T", " ").slice(0, 16) : "-");
const yuan = (n: any) => "¥" + Number(n || 0).toFixed(2);

async function load() {
  loading.value = true;
  try {
    const d = await getOrders({
      page: query.page,
      size: query.size,
      formId: query.formId || undefined,
      status: query.status === "" ? undefined : query.status,
      keyword: query.keyword || undefined,
      start: query.range?.[0] || undefined,
      end: query.range?.[1] || undefined
    });
    list.value = d.list || [];
    total.value = d.total || 0;
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  loading.value = false;
}

async function loadStats() {
  try {
    stats.value = await getOrderStats();
  } catch {
    /* 汇总失败不打断列表 */
  }
}

function search() {
  query.page = 1;
  load();
}

function reset() {
  query.formId = "";
  query.status = "";
  query.keyword = "";
  query.range = [];
  search();
}

async function doVerify(row: any) {
  try {
    await ElMessageBox.confirm(
      `确认已收到订单 ${row.order_no} 的转账 ${yuan(row.amount)}？核销后提交将正式生效。`,
      "转账核销",
      { type: "warning", confirmButtonText: "确认核销", cancelButtonText: "取消" }
    );
  } catch {
    return;
  }
  try {
    const d = await verifyOrder(row.id);
    message(d.msg || "已核销", { type: "success" });
    load();
    loadStats();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

async function doRefund(row: any) {
  let reason = "";
  try {
    const r = await ElMessageBox.prompt(
      `将向付款人原路退回 ${yuan(row.amount)}，请输入退款原因：`,
      "原路退款",
      {
        type: "warning",
        confirmButtonText: "确认退款",
        cancelButtonText: "取消",
        inputPlaceholder: "退款原因（必填）",
        inputValidator: (v: string) =>
          v && v.trim() ? true : "退款原因必填"
      }
    );
    reason = r.value;
  } catch {
    return;
  }
  try {
    const d = await refundOrder(row.id, reason.trim());
    message(d.msg || "已退款", { type: "success" });
    load();
    loadStats();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

async function doCancel(row: any) {
  try {
    await ElMessageBox.confirm(
      `取消订单 ${row.order_no}？未支付的暂存提交将一并作废。`,
      "取消订单",
      { type: "warning", confirmButtonText: "确认取消", cancelButtonText: "关闭" }
    );
  } catch {
    return;
  }
  try {
    await cancelOrder(row.id);
    message("已取消", { type: "success" });
    load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

/* 详情抽屉 */
const drawer = ref(false);
const detail = ref<any>(null);
async function openDetail(row: any) {
  drawer.value = true;
  try {
    detail.value = await getOrder(row.id);
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

onMounted(() => {
  load();
  loadStats();
});
</script>

<template>
  <div>
    <!-- 收入汇总卡片 -->
    <el-row :gutter="14" class="stat-row">
      <el-col :xs="12" :sm="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-num">{{ yuan(stats.paid_amount) }}</div>
          <div class="stat-label">累计收入（{{ stats.paid_count || 0 }} 笔）</div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-num">{{ yuan(stats.today_amount) }}</div>
          <div class="stat-label">今日收入（{{ stats.today_count || 0 }} 笔）</div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-num warn">{{ stats.wait_verify || 0 }}</div>
          <div class="stat-label">待核销（转账凭证）</div>
        </el-card>
      </el-col>
      <el-col :xs="12" :sm="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-num">{{ yuan(stats.refund_amount) }}</div>
          <div class="stat-label">累计退款</div>
        </el-card>
      </el-col>
    </el-row>

    <el-card shadow="never">
      <div class="filter-bar">
        <el-select
          v-model="query.status"
          placeholder="全部状态"
          clearable
          style="width: 130px"
          @change="search"
        >
          <el-option label="待支付" :value="0" />
          <el-option label="已支付" :value="1" />
          <el-option label="已取消" :value="2" />
          <el-option label="已退款" :value="3" />
          <el-option label="待核销" :value="4" />
        </el-select>
        <el-input
          v-model="query.keyword"
          placeholder="搜索订单号"
          clearable
          style="width: 200px"
          @keyup.enter="search"
        />
        <el-date-picker
          v-model="query.range"
          type="daterange"
          range-separator="-"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          value-format="YYYY-MM-DD"
          style="width: 240px"
        />
        <el-button type="primary" @click="search">查询</el-button>
        <el-button @click="reset">重置</el-button>
      </div>

      <el-table v-loading="loading" :data="list">
        <el-table-column label="订单号" width="210">
          <template #default="{ row }">
            <el-link type="primary" @click="openDetail(row)">
              {{ row.order_no }}
            </el-link>
          </template>
        </el-table-column>
        <el-table-column label="表单" min-width="140" show-overflow-tooltip>
          <template #default="{ row }">{{ row.form_title || "-" }}</template>
        </el-table-column>
        <el-table-column label="金额" width="100" align="right">
          <template #default="{ row }">
            <b :style="{ color: row.status === 1 ? '#67c23a' : '#606266' }">
              {{ yuan(row.amount) }}
            </b>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag
              size="small"
              :type="(STATUS_META[row.status]?.type || 'info') as any"
            >
              {{ STATUS_META[row.status]?.text || row.status }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="支付方式" width="130">
          <template #default="{ row }">{{ row.channel_name || "-" }}</template>
        </el-table-column>
        <el-table-column label="收款时间" width="150">
          <template #default="{ row }">{{ fmt(row.paid_at) }}</template>
        </el-table-column>
        <el-table-column label="创建时间" width="150">
          <template #default="{ row }">{{ fmt(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="170" fixed="right" class-name="op-nowrap">
          <template #default="{ row }">
            <el-button
              v-if="row.status === 4"
              link
              type="primary"
              @click="doVerify(row)"
            >
              核销
            </el-button>
            <el-button
              v-if="row.status === 1"
              link
              type="warning"
              @click="doRefund(row)"
            >
              退款
            </el-button>
            <el-button
              v-if="row.status === 0"
              link
              type="danger"
              @click="doCancel(row)"
            >
              取消
            </el-button>
            <el-button link type="primary" @click="openDetail(row)">
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
          :page-sizes="[20, 50, 100]"
          :page-size="query.size"
          :current-page="query.page"
          @current-change="(p: number) => load(p)"
          @size-change="(s: number) => ((query.size = s), load(1))"
        />
      </div>
    </el-card>

    <!-- 详情抽屉 -->
    <el-drawer v-model="drawer" title="订单详情" size="520px">
      <div v-if="detail" class="detail-list">
        <div class="d-item">
          <span class="d-label">订单号</span><span>{{ detail.order_no }}</span>
        </div>
        <div class="d-item">
          <span class="d-label">表单</span><span>{{ detail.form_title }}</span>
        </div>
        <div class="d-item">
          <span class="d-label">状态</span>
          <el-tag
            size="small"
            :type="(STATUS_META[detail.status]?.type || 'info') as any"
          >
            {{ STATUS_META[detail.status]?.text }}
          </el-tag>
        </div>
        <div class="d-item">
          <span class="d-label">金额</span><b>{{ yuan(detail.amount) }}</b>
        </div>
        <div class="d-item">
          <span class="d-label">支付方式</span><span>{{ detail.channel_name }}</span>
        </div>
        <div class="d-item">
          <span class="d-label">渠道交易号</span><span>{{ detail.trade_no || "-" }}</span>
        </div>
        <div class="d-item">
          <span class="d-label">收款时间</span><span>{{ fmt(detail.paid_at) }}</span>
        </div>
        <div class="d-item" v-if="detail.refund_no">
          <span class="d-label">退款单号</span><span>{{ detail.refund_no }}</span>
        </div>
        <div class="d-item" v-if="detail.remark">
          <span class="d-label">备注</span><span>{{ detail.remark }}</span>
        </div>
        <template v-if="detail.pay_type === 'transfer'">
          <div class="d-item">
            <span class="d-label">转账凭证</span>
            <el-image
              v-if="detail.voucher"
              :src="detail.voucher"
              :preview-src-list="[detail.voucher]"
              fit="cover"
              style="width: 120px; height: 120px; border-radius: 8px"
              preview-teleported
            />
            <span v-else>未上传</span>
          </div>
          <div class="d-item" v-if="detail.verify_at">
            <span class="d-label">核销时间</span><span>{{ fmt(detail.verify_at) }}</span>
          </div>
        </template>
        <el-divider>提交内容</el-divider>
        <div
          v-for="(v, k) in detail.submission?.data_json
            ? JSON.parse(detail.submission.data_json)
            : {}"
          :key="k"
          class="d-item"
        >
          <span class="d-label">{{ k }}</span>
          <span style="text-align: right; word-break: break-all">
            {{ Array.isArray(v) ? v.join("、") : v }}
          </span>
        </div>
        <div v-if="!detail.submission" class="d-empty">无关联提交</div>
      </div>
    </el-drawer>
  </div>
</template>

<style scoped>
.stat-row {
  margin-bottom: 14px;
}
.stat-card :deep(.el-card__body) {
  padding: 16px 18px;
}
.stat-num {
  font-size: 24px;
  font-weight: 700;
  color: var(--el-color-primary);
}
.stat-num.warn {
  color: var(--el-color-danger);
}
.stat-label {
  color: #909399;
  font-size: 13px;
  margin-top: 4px;
}
.filter-bar {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 14px;
}
.pager {
  margin-top: 14px;
  display: flex;
  justify-content: flex-end;
}
.detail-list .d-item {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 9px 2px;
  border-bottom: 1px dashed #f0f0f0;
  font-size: 14px;
}
.detail-list .d-label {
  color: #909399;
  flex-shrink: 0;
}
.d-empty {
  color: #c0c4cc;
  font-size: 13px;
  text-align: center;
  padding: 12px 0;
}
</style>
