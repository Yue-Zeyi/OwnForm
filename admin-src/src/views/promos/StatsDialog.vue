<script setup lang="ts">
/**
 * 推广统计弹窗：30 日趋势 / 条目分发占比 / 条目统计 / 设备占比 / 点击明细
 *
 * 图表在 el-dialog @opened 后初始化：弹窗打开动画期间 DOM 尺寸为 0，
 * 过早 init 会导致图表宽度塌陷。
 */
import { ref, onMounted, onBeforeUnmount } from "vue";
import * as echarts from "echarts";
import { getPromoStats } from "@/api/promos";
import { message } from "@/utils/message";

const props = defineProps<{ modelValue: boolean; promo: any }>();
const emit = defineEmits<{ (e: "update:modelValue", v: boolean): void }>();

const loading = ref(false);
const name = ref("");
const type = ref("qrcode");
const total = ref(0);
const today = ref(0);
const trend = ref<any[]>([]);
const itemStats = ref<any[]>([]);
const deviceStats = ref<any[]>([]);
const details = ref<any[]>([]);

const trendEl = ref<HTMLElement>();
const itemEl = ref<HTMLElement>();
const deviceEl = ref<HTMLElement>();
let trendChart: echarts.ECharts | null = null;
let itemChart: echarts.ECharts | null = null;
let deviceChart: echarts.ECharts | null = null;

const DEVICE_LABELS: Record<string, string> = {
  ios: "iOS",
  android: "安卓",
  pc: "电脑",
  other: "其他"
};

function renderTrend() {
  if (!trendEl.value) return;
  trendChart?.dispose();
  trendChart = echarts.init(trendEl.value);
  trendChart.setOption({
    grid: { left: 40, right: 20, top: 30, bottom: 30 },
    tooltip: {
      trigger: "axis",
      formatter: (params: any) =>
        `${params[0].axisValue}<br/>${type.value === "short" ? "点击" : "扫码"}：<b>${params[0].value}</b> 次`
    },
    xAxis: {
      type: "category",
      data: trend.value.map((x: any) => x.date.slice(5))
    },
    yAxis: { type: "value", minInterval: 1 },
    series: [
      {
        type: "line",
        data: trend.value.map((x: any) => x.count),
        smooth: true,
        areaStyle: { opacity: 0.12 },
        itemStyle: { color: "#409eff" }
      }
    ]
  });
}

function renderPie(
  el: HTMLElement | undefined,
  chart: echarts.ECharts | null,
  data: any[]
): echarts.ECharts | null {
  if (!el) return chart;
  chart?.dispose();
  const c = echarts.init(el);
  c.setOption({
    tooltip: { trigger: "item", formatter: "{b}<br/>{c} 次（{d}%）" },
    legend: { bottom: 0, type: "scroll" },
    series: [
      {
        type: "pie",
        radius: ["38%", "62%"],
        center: ["50%", "42%"],
        label: { show: false },
        data: data.map((x: any) => ({ name: x.label, value: x.count }))
      }
    ]
  });
  return c;
}

function disposeCharts() {
  trendChart?.dispose();
  itemChart?.dispose();
  deviceChart?.dispose();
  trendChart = itemChart = deviceChart = null;
}

function onResize() {
  trendChart?.resize();
  itemChart?.resize();
  deviceChart?.resize();
}

async function load() {
  if (!props.promo?.id) return;
  loading.value = true;
  try {
    const d = await getPromoStats(props.promo.id);
    name.value = d.name;
    type.value = d.type || "qrcode";
    total.value = d.total;
    today.value = d.today;
    trend.value = d.trend || [];
    itemStats.value = d.itemStats || [];
    deviceStats.value = (d.deviceStats || []).map((x: any) => ({
      ...x,
      label: DEVICE_LABELS[x.label] || x.label
    }));
    details.value = d.details || [];
    renderTrend();
    itemChart = renderPie(itemEl.value, itemChart, itemStats.value);
    deviceChart = renderPie(deviceEl.value, deviceChart, deviceStats.value);
  } catch (e: any) {
    // 不得静默吞错：越权/不存在时显示全 0 假统计会误导用户
    message(e.message || "加载失败", { type: "error" });
  }
  loading.value = false;
}

function fmtTime(s: string) {
  return s ? String(s).replace("T", " ").slice(0, 16) : "-";
}

/** 条目分发规则摘要：权重 · 设备 · 时段 */
function ruleText(row: any): string {
  const parts: string[] = [`权重 ${row.weight || 1}`];
  if (row.device && row.device !== "all") {
    parts.push(DEVICE_LABELS[row.device] || row.device);
  }
  if (row.timeFrom && row.timeTo) {
    parts.push(`${row.timeFrom}–${row.timeTo}`);
  }
  return parts.join(" · ");
}

onMounted(() => {
  window.addEventListener("resize", onResize);
});
onBeforeUnmount(() => {
  window.removeEventListener("resize", onResize);
  disposeCharts();
});
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    :title="(promo?.name || '推广') + ' · 统计'"
    width="960px"
    top="4vh"
    @update:model-value="emit('update:modelValue', $event)"
    @opened="load"
    @closed="disposeCharts"
  >
    <div v-loading="loading" class="stats-body">
      <div class="ps-cards">
        <el-tag type="success" effect="plain">
          累计{{ type === "short" ? "点击" : "扫码" }} {{ total }} 次
        </el-tag>
        <el-tag effect="plain">今日 {{ today }} 次</el-tag>
      </div>

      <el-row :gutter="14">
        <el-col :xs="24" :md="14">
          <el-card shadow="never">
            <template #header>
              <b>近 30 日{{ type === "short" ? "点击" : "扫码" }}趋势</b>
            </template>
            <div ref="trendEl" style="height: 260px" />
          </el-card>
        </el-col>
        <el-col :xs="24" :md="10">
          <el-card shadow="never">
            <template #header><b>条目分发占比</b></template>
            <div ref="itemEl" style="height: 260px" />
            <el-empty
              v-if="!itemStats.length && !loading"
              description="暂无数据"
              :image-size="60"
              style="margin-top: -240px"
            />
          </el-card>
        </el-col>
      </el-row>

      <el-card shadow="never" class="mt-3">
        <template #header><b>条目统计</b></template>
        <el-table :data="itemStats" size="small">
          <el-table-column label="条目" min-width="140">
            <template #default="{ row }">{{ row.label }}</template>
          </el-table-column>
          <el-table-column label="内容" min-width="220">
            <template #default="{ row }">
              <span class="mono">{{ row.target || "-" }}</span>
            </template>
          </el-table-column>
          <el-table-column label="扫码上限" width="90" align="center">
            <template #default="{ row }">
              {{ row.scanLimit > 0 ? row.scanLimit : "不限" }}
            </template>
          </el-table-column>
          <el-table-column label="权重 / 规则" min-width="150">
            <template #default="{ row }">
              <span class="rule-txt">{{ ruleText(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="分发量" width="80" align="center">
            <template #default="{ row }">{{ row.count }}</template>
          </el-table-column>
          <el-table-column
            v-if="type !== 'short'"
            label="长按识别"
            width="90"
            align="center"
          >
            <template #default="{ row }">{{ row.longpress }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90" align="center">
            <template #default="{ row }">
              <el-tag
                v-if="row.exhausted"
                type="danger"
                size="small"
                effect="plain"
                >已满</el-tag
              >
              <el-tag v-else type="success" size="small" effect="plain"
                >分发中</el-tag
              >
            </template>
          </el-table-column>
          <template #empty>
            <div style="color: #909399; padding: 16px 0; font-size: 13px">
              暂无条目
            </div>
          </template>
        </el-table>
      </el-card>

      <el-row :gutter="14" class="mt-3">
        <el-col :xs="24" :md="10">
          <el-card shadow="never">
            <template #header><b>设备占比</b></template>
            <div ref="deviceEl" style="height: 220px" />
            <el-empty
              v-if="!deviceStats.length && !loading"
              description="暂无数据"
              :image-size="60"
              style="margin-top: -200px"
            />
          </el-card>
        </el-col>
        <el-col :xs="24" :md="14">
          <el-card shadow="never" class="ps-note-card">
            <template #header><b>说明</b></template>
            <div class="ps-note">
              <p>
                {{ type === "short" ? "点击量" : "扫码量" }}
                = 访问 /q/ 链接的次数（同 IP 60 秒内去重）。
              </p>
              <template v-if="type !== 'short'">
                <p>顺序轮询按访问依次分配各张二维码；随机则随机分配。</p>
                <p>
                  单张二维码达到「扫码上限」后自动停止分发、切换下一张；
                  全部满员时展示满员提示。
                </p>
                <p>
                  长按识别 =
                  手机端长按二维码图片的次数（到达识别动作的前一步）。
                </p>
              </template>
              <template v-else>
                <p>多条目标链接按分发方式轮询跳转（多域名轮换降低风险）。</p>
                <p>
                  中转引导模式先落本站引导页，用户点击「继续访问」后跳转目标。
                </p>
              </template>
            </div>
          </el-card>
        </el-col>
      </el-row>

      <el-card shadow="never" class="mt-3">
        <template #header><b>点击明细（最近 200 条）</b></template>
        <el-table :data="details" size="small">
          <el-table-column label="时间" width="140">
            <template #default="{ row }">{{
              fmtTime(row.created_at)
            }}</template>
          </el-table-column>
          <el-table-column label="设备" width="80" align="center">
            <template #default="{ row }">{{
              DEVICE_LABELS[row.device] || row.device
            }}</template>
          </el-table-column>
          <el-table-column label="IP" prop="ip" width="130" />
          <el-table-column label="命中条目" width="110">
            <template #default="{ row }">{{ row.item_label }}</template>
          </el-table-column>
          <el-table-column label="目标地址" min-width="200">
            <template #default="{ row }">
              <span class="mono">{{ row.target_url }}</span>
            </template>
          </el-table-column>
          <template #empty>
            <div style="color: #909399; padding: 16px 0; font-size: 13px">
              暂无数据
            </div>
          </template>
        </el-table>
      </el-card>
    </div>
  </el-dialog>
</template>

<style scoped lang="scss">
.stats-body {
  max-height: 76vh;
  overflow-y: auto;
  padding-right: 4px;
}
.ps-cards {
  display: flex;
  gap: 10px;
  margin-bottom: 14px;
}
.mt-3 {
  margin-top: 14px;
}
.ps-note-card {
  height: 100%;
}
.ps-note {
  font-size: 13px;
  color: #606266;
  line-height: 2;

  p {
    margin: 0 0 6px;
  }
}
.mono {
  font-family: "SF Mono", Menlo, Consolas, monospace;
  font-size: 12px;
  word-break: break-all;
}
.rule-txt {
  font-size: 12px;
  color: #606266;
}
</style>
