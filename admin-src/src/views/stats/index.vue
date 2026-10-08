<script setup lang="ts">
import { ref, reactive, computed, onMounted, onBeforeUnmount } from "vue";
import { useRoute, useRouter } from "vue-router";
import { message } from "@/utils/message";
import { getStats } from "@/api/ownform";
import * as echarts from "echarts";
import { Document } from "@element-plus/icons-vue";

defineOptions({
  name: "FormStats"
});

const route = useRoute();
const router = useRouter();
/** 弹窗嵌入时可传 formId；路由模式从路径取 */
const props = defineProps<{ formId?: string }>();
const formId = (props.formId || (route.params.id as string)) + "";

const loading = ref(false);
const form = reactive<any>({});
const summary = reactive<any>({});
const fieldStats = ref<any[]>([]);
const truncated = ref(false);

const mobileRate = computed(() => {
  const d = summary.device || {};
  const t = (d.pc || 0) + (d.mobile || 0) + (d.other || 0);
  return t ? Math.round(((d.mobile || 0) / t) * 100) : 0;
});

const cards = computed(() => [
  {
    label: "总提交",
    value: summary.total ?? "-",
    color: "#409eff",
    bg: "#ecf5ff"
  },
  {
    label: "今日提交",
    value: summary.today ?? "-",
    color: "#67c23a",
    bg: "#f0f9eb"
  },
  {
    label: "独立 IP",
    value: summary.uniqueIp ?? "-",
    color: "#e6a23c",
    bg: "#fdf6ec"
  },
  {
    label: "待审核",
    value: summary.pending ?? 0,
    color: "#f56c6c",
    bg: "#fef0f0"
  }
]);

const charts: echarts.ECharts[] = [];

function typeName(t: string) {
  const map: Record<string, string> = {
    input: "单行文本",
    textarea: "多行文本",
    inputNumber: "数字",
    radio: "单选",
    checkbox: "多选",
    select: "下拉选择",
    datePicker: "日期",
    timePicker: "时间",
    rate: "评分",
    slider: "滑块",
    upload: "文件上传",
    cascader: "级联选择",
    switch: "开关",
    colorPicker: "颜色"
  };
  return map[t] || t;
}

function mk(el: HTMLElement | undefined | null) {
  if (!el) return null;
  const c = echarts.init(el);
  charts.push(c);
  return c;
}

function renderCharts() {
  charts.forEach(c => c.dispose());
  charts.length = 0;

  // 趋势
  const trendC = mk(document.querySelector(".trend-el") as HTMLElement);
  if (trendC) {
    const d = summary.trend || [];
    trendC.setOption({
      grid: { left: 40, right: 16, top: 24, bottom: 28 },
      tooltip: { trigger: "axis" },
      xAxis: { type: "category", data: d.map((x: any) => x.date.slice(5)) },
      yAxis: { type: "value", minInterval: 1 },
      series: [
        {
          type: "line",
          smooth: true,
          data: d.map((x: any) => x.count),
          itemStyle: { color: "#409eff" },
          areaStyle: { opacity: 0.12 }
        }
      ]
    });
  }
  // 设备
  const deviceC = mk(document.querySelector(".device-el") as HTMLElement);
  if (deviceC) {
    const d = summary.device || {};
    deviceC.setOption({
      tooltip: { trigger: "item" },
      series: [
        {
          type: "pie",
          radius: ["42%", "70%"],
          center: ["50%", "50%"],
          label: { formatter: "{b}\n{c}" },
          data: [
            { name: "电脑", value: d.pc || 0, itemStyle: { color: "#409eff" } },
            {
              name: "手机",
              value: d.mobile || 0,
              itemStyle: { color: "#67c23a" }
            },
            {
              name: "其他",
              value: d.other || 0,
              itemStyle: { color: "#909399" }
            }
          ].filter((x: any) => x.value > 0)
        }
      ]
    });
  }
  // 字段
  const colors = [
    "#409eff",
    "#67c23a",
    "#e6a23c",
    "#f56c6c",
    "#909399",
    "#9a6fe0",
    "#2bb5a0",
    "#e0916f",
    "#6f9ae0",
    "#b5cc2b"
  ];
  fieldStats.value.forEach((f, idx) => {
    const el = document.querySelector(
      `.field-chart[data-field="${CSS.escape(f.field)}"]`
    ) as HTMLElement;
    const c = mk(el);
    if (!c) return;
    if (f.chart === "pie") {
      c.setOption({
        tooltip: { trigger: "item", formatter: "{b}: {c} ({d}%)" },
        legend: { bottom: 0, type: "scroll" },
        color: colors,
        series: [
          {
            type: "pie",
            radius: ["34%", "62%"],
            center: ["50%", "44%"],
            data: f.distribution.map((x: any) => ({
              name: x.label,
              value: x.value
            }))
          }
        ]
      });
    } else if (f.chart === "number") {
      c.setOption({
        grid: { left: 90, right: 24, top: 16, bottom: 28 },
        tooltip: { trigger: "axis" },
        xAxis: { type: "value", minInterval: 1 },
        yAxis: {
          type: "category",
          data: f.distribution.map((x: any) => x.label),
          inverse: true
        },
        series: [
          {
            type: "bar",
            data: f.distribution.map((x: any) => x.value),
            itemStyle: { color: "#409eff", borderRadius: 4 },
            barMaxWidth: 22
          }
        ]
      });
    } else {
      const dist = f.distribution.slice(0, 10).reverse();
      c.setOption({
        grid: { left: 8, right: 40, top: 8, bottom: 8, containLabel: true },
        tooltip: { trigger: "axis" },
        xAxis: { type: "value", minInterval: 1 },
        yAxis: {
          type: "category",
          data: dist.map((x: any) => x.label),
          axisLabel: { width: 130, overflow: "truncate" }
        },
        series: [
          {
            type: "bar",
            data: dist.map((x: any) => x.value),
            itemStyle: { color: "#67c23a", borderRadius: 4 },
            barMaxWidth: 18
          }
        ]
      });
    }
  });
}

/** 图表渲染的延时句柄：等 DOM 更新完成，卸载时必须清理 */
let renderTimer: ReturnType<typeof setTimeout> | null = null;

async function load() {
  loading.value = true;
  try {
    const d = await getStats(formId);
    Object.assign(form, d.form);
    Object.assign(summary, d.summary);
    summary.trend = d.trend || [];
    fieldStats.value = d.fields;
    truncated.value = d.truncated;
    if (renderTimer) clearTimeout(renderTimer);
    renderTimer = setTimeout(renderCharts, 30);
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  loading.value = false;
}

function onResize() {
  charts.forEach(c => c.resize());
}

onMounted(() => {
  load();
  window.addEventListener("resize", onResize);
});
onBeforeUnmount(() => {
  window.removeEventListener("resize", onResize);
  if (renderTimer) {
    clearTimeout(renderTimer);
    renderTimer = null;
  }
  charts.forEach(c => c.dispose());
});
</script>

<template>
  <div v-loading="loading" :class="{ 'in-dialog': !!props.formId }">
    <div class="page-head">
      <div>
        <div class="ctx-title">统计分析 · {{ form.title }}</div>
        <div v-if="truncated" class="page-sub">
          数据量较大，仅统计最近 {{ summary.total }} 条提交
        </div>
      </div>
      <el-button
        v-if="!props.formId"
        :icon="Document"
        @click="router.push('/forms/data/' + formId)"
      >
        查看数据
      </el-button>
    </div>

    <el-row :gutter="16">
      <el-col v-for="c in cards" :key="c.label" :xs="12" :sm="6">
        <el-card shadow="never" class="stat-card">
          <div class="stat-inner">
            <span class="stat-num" :style="{ color: c.color }">{{
              c.value
            }}</span>
            <span class="stat-label">{{ c.label }}</span>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="16" class="mt-4">
      <el-col :xs="24" :sm="12">
        <el-card shadow="never">
          <template #header>近 14 日提交趋势</template>
          <div class="trend-el" style="height: 280px" />
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="6">
        <el-card shadow="never">
          <template #header>设备分布</template>
          <div class="device-el" style="height: 280px" />
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="6">
        <el-card shadow="never">
          <template #header>整体情况</template>
          <div class="sum-line">
            <span>总提交</span><b>{{ summary.total || 0 }}</b>
          </div>
          <div class="sum-line">
            <span>今日提交</span><b>{{ summary.today || 0 }}</b>
          </div>
          <div class="sum-line">
            <span>独立 IP</span><b>{{ summary.uniqueIp || 0 }}</b>
          </div>
          <div class="sum-line">
            <span>手机端占比</span><b>{{ mobileRate }}%</b>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <div v-for="f in fieldStats" :key="f.field" class="mt-4">
      <el-card shadow="never">
        <template #header>
          <span>{{ f.title }}</span>
          <el-tag size="small" effect="plain" style="margin-left: 8px">
            {{ typeName(f.type) }}
          </el-tag>
          <el-tag
            size="small"
            effect="plain"
            type="info"
            style="margin-left: 6px"
          >
            {{ f.answered }} 人作答
          </el-tag>
        </template>
        <div class="stats-chart-row">
          <div
            class="field-chart"
            :data-field="f.field"
            style="height: 260px; min-width: 0; flex: 1"
          />
          <div v-if="f.chart === 'number'" class="stats-side">
            <div class="sum-line">
              <span>平均</span><b>{{ f.avg }}</b>
            </div>
            <div class="sum-line">
              <span>最小</span><b>{{ f.min }}</b>
            </div>
            <div class="sum-line">
              <span>最大</span><b>{{ f.max }}</b>
            </div>
          </div>
          <div v-else-if="f.chart === 'rank'" class="stats-side">
            <div class="sum-line">
              <span>去重后</span><b>{{ f.unique }}</b>
            </div>
            <div class="stats-toplist">
              <div v-for="d in f.distribution" :key="d.label" class="top-row">
                <span class="top-label">{{ d.label }}</span>
                <span class="top-count">{{ d.value }}</span>
              </div>
            </div>
          </div>
        </div>
      </el-card>
    </div>

    <el-empty
      v-if="!loading && !fieldStats.length"
      description="该表单暂无可统计的字段"
    />
  </div>
</template>

<style scoped lang="scss">
.page-head {
  display: flex;
  /* 弹窗嵌入时隐藏内部页头（标题已在弹窗标题栏） */
  .in-dialog & {
    display: none;
  }
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
}

.stat-card {
  .stat-inner {
    display: flex;
    align-items: baseline;
    gap: 10px;
  }

  .stat-num {
    font-size: 26px;
    font-weight: 700;
  }

  .stat-label {
    color: #909399;
    font-size: 13px;
  }
}

.sum-line {
  display: flex;
  justify-content: space-between;
  padding: 10px 4px;
  border-bottom: 1px dashed #f0f0f0;
  font-size: 14px;

  &:last-child {
    border-bottom: none;
  }
}

.stats-chart-row {
  display: flex;
  gap: 16px;
}

.stats-side {
  width: 220px;
  flex-shrink: 0;
  border-left: 1px solid #f0f0f0;
  padding-left: 14px;
}

.stats-toplist {
  max-height: 200px;
  overflow: auto;
}

.top-row {
  display: flex;
  justify-content: space-between;
  padding: 6px 0;
  border-bottom: 1px dashed #f0f0f0;
  font-size: 13px;

  .top-label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 150px;
  }

  .top-count {
    color: #909399;
  }
}

@media (max-width: 900px) {
  .stats-chart-row {
    flex-direction: column;
  }

  .stats-side {
    width: auto;
    border-left: none;
    padding-left: 0;
  }
}
</style>
