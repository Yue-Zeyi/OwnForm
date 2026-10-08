<script setup lang="ts">
import { ref, reactive, computed, onMounted, onBeforeUnmount } from "vue";
import { useRouter } from "vue-router";
import { getDashboard } from "@/api/ownform";
import { message } from "@/utils/message";
import dayjs from "dayjs";
import * as echarts from "echarts";
import {
  Tickets,
  VideoPlay,
  Finished,
  AlarmClock,
  Calendar,
  Document,
  CaretTop,
  CaretBottom,
  FolderOpened,
  List,
  Plus,
  ArrowRight,
  Promotion
} from "@element-plus/icons-vue";

defineOptions({
  name: "Dashboard"
});

const router = useRouter();
const loading = ref(false);
const trendRange = ref<"7" | "30">("7");
const data = reactive<any>({
  formTotal: 0,
  activeTotal: 0,
  submitTotal: 0,
  today: 0,
  yesterday: 0,
  last7: 0,
  prev7: 0,
  pending: 0,
  avgPerDay: 0,
  uploadTotal: 0,
  trend: [],
  recent: [],
  topForms: [],
  promoTotal: 0,
  promoActive: 0,
  qrcodeTotal: 0,
  shortTotal: 0,
  promoClicks: 0,
  promoToday: 0,
  topPromos: []
});

const userInfo = (() => {
  try {
    return JSON.parse(localStorage.getItem("user-info") || '{"username":"-"}');
  } catch {
    // localStorage 被污染成非法 JSON 时降级，避免整页白屏
    return { username: "-" };
  }
})();

/** 日志管理仅 admin 可见（路由 roles 限制），member 点击会落 404 */
const isAdminUser = (userInfo?.roles || []).includes("admin");

/** 成员可被关闭"创建表单"能力（用户管理中配置），隐藏快捷入口 */
const canCreateForm =
  isAdminUser ||
  !(
    (userInfo?.permissions || []).length &&
    !(userInfo?.permissions || []).includes("form:create")
  );

const greetTime = computed(() => {
  const h = new Date().getHours();
  if (h < 6) return "夜深了";
  if (h < 9) return "早上好";
  if (h < 12) return "上午好";
  if (h < 14) return "中午好";
  if (h < 18) return "下午好";
  return "晚上好";
});
const todayText = dayjs().format("YYYY年M月D日 dddd");

/* 环比昨日 */
const todayDelta = computed(() => data.today - data.yesterday);

/* 周环比 */
const weekDelta = computed(() => {
  if (!data.prev7) return data.last7 > 0 ? 100 : 0;
  return Math.round(((data.last7 - data.prev7) / data.prev7) * 100);
});

/* 月环比 */
const monthDelta = computed(() => {
  if (!data.prevMonthTotal) return data.monthTotal > 0 ? 100 : 0;
  return Math.round(
    ((data.monthTotal - data.prevMonthTotal) / data.prevMonthTotal) * 100
  );
});

/**
 * 概览卡片：两行四列
 *
 * 行一「提交量」维度：从量级大到小——总量 / 本月 / 近 7 日 / 今日，
 * 每张卡带对应上一周期的环比，一眼看出趋势。
 * 行二「资源」维度：表单 / 页面 / 引流 / 附件（四种内容形态各一张）。
 * 固定 8 张正好铺满两行四列，不会出现孤儿模块；
 * 待审核数在顶部问候卡有醒目入口，不占卡片位。
 */
const cards = computed(() => [
  {
    label: "提交总量",
    value: data.submitTotal,
    color: "#409eff",
    bg: "#ecf5ff",
    icon: Finished,
    sub: `日均 ${data.avgPerDay}`,
    to: ""
  },
  {
    label: "本月提交",
    value: data.monthTotal,
    color: monthDelta.value >= 0 ? "#67c23a" : "#f56c6c",
    bg: monthDelta.value >= 0 ? "#f0f9eb" : "#fef0f0",
    icon: Calendar,
    sub: `上月 ${data.prevMonthTotal}`,
    delta: monthDelta.value,
    deltaIsPercent: true,
    to: ""
  },
  {
    label: "近 7 日提交",
    value: data.last7,
    color: weekDelta.value >= 0 ? "#67c23a" : "#f56c6c",
    bg: weekDelta.value >= 0 ? "#f0f9eb" : "#fef0f0",
    icon: VideoPlay,
    sub: `上 7 日 ${data.prev7}`,
    delta: weekDelta.value,
    deltaIsPercent: true,
    to: ""
  },
  {
    label: "今日提交",
    value: data.today,
    // 用 todayDelta.value（computed）：data 里没有 todayDelta 字段，
    // 误写 data.todayDelta 会让 undefined >= 0 恒 false → 图标恒红、角标消失
    color: todayDelta.value >= 0 ? "#67c23a" : "#f56c6c",
    bg: todayDelta.value >= 0 ? "#f0f9eb" : "#fef0f0",
    icon: AlarmClock,
    sub: `昨日 ${data.yesterday}`,
    delta: todayDelta.value,
    to: ""
  }
]);

const infoCards = computed(() => [
  {
    label: "表单",
    value: data.formTotal,
    color: "#409eff",
    bg: "#ecf5ff",
    icon: Tickets,
    sub: `${data.activeTotal} 个收集中`,
    to: "/forms/index"
  },
  {
    label: "页面",
    value: data.pagePublished,
    color: "#e6a23c",
    bg: "#fdf6ec",
    icon: Document,
    sub: `共 ${data.pageTotal} 个 · 浏览 ${formatNum(data.pageViews)}`,
    to: "/pages/index"
  },
  {
    label: "附件",
    value: data.uploadTotal,
    color: "#909399",
    bg: "#f4f4f5",
    icon: FolderOpened,
    sub: "表单上传的图片与文档素材",
    to: "/uploads/index"
  },
  {
    label: "引流",
    value: data.promoTotal,
    color: "#7b5cf5",
    bg: "#f3f0ff",
    icon: Promotion,
    sub: `活码 ${data.qrcodeTotal} · 短链 ${data.shortTotal} · 今日 ${data.promoToday}`,
    to: "/promos/index"
  }
]);

/** 浏览量缩写：1.2万 / 3400 */
function formatNum(n: number): string {
  if (n >= 10000) return (n / 10000).toFixed(1).replace(/\.0$/, "") + "万";
  return String(n ?? 0);
}

const trendEl = ref<HTMLElement>();
const topEl = ref<HTMLElement>();
let trendChart: echarts.ECharts | null = null;
let topChart: echarts.ECharts | null = null;

function openPage(url: string) {
  if (url) window.open(url, "_blank", "noopener");
}

function renderTrend() {
  if (!trendEl.value) return;
  trendChart?.dispose();
  trendChart = echarts.init(trendEl.value);
  const n = trendRange.value === "7" ? 7 : 30;
  const d = (data.trend || []).slice(-n);
  trendChart.setOption({
    grid: { left: 40, right: 20, top: 30, bottom: 30 },
    tooltip: {
      trigger: "axis",
      formatter: (params: any) => {
        const p = params[0];
        return `${p.axisValue}<br/>提交：<b>${p.value}</b> 份`;
      }
    },
    xAxis: { type: "category", data: d.map((x: any) => x.date.slice(5)) },
    yAxis: { type: "value", minInterval: 1 },
    series: [
      {
        type: "line",
        data: d.map((x: any) => x.count),
        smooth: trendRange.value === "30",
        areaStyle: { opacity: 0.12 },
        itemStyle: { color: "#409eff" }
      }
    ]
  });
}

function renderTop() {
  if (!topEl.value) return;
  topChart?.dispose();
  topChart = echarts.init(topEl.value);
  const forms = [...(data.topForms || [])].reverse();
  topChart.setOption({
    grid: { left: 8, right: 44, top: 8, bottom: 8, containLabel: true },
    tooltip: { trigger: "axis" },
    xAxis: { type: "value", minInterval: 1 },
    yAxis: {
      type: "category",
      data: forms.map((f: any) => f.title),
      axisLabel: {
        width: 110,
        overflow: "truncate",
        onClick: (v: string) => {
          const f = (data.topForms || []).find((x: any) => x.title === v);
          if (f) router.push("/forms/data/" + f.id);
        }
      },
      triggerEvent: true
    },
    series: [
      {
        type: "bar",
        data: forms.map((f: any) => f.submit_count),
        itemStyle: { color: "#409eff", borderRadius: 4 },
        barMaxWidth: 18,
        label: { show: true, position: "right" }
      }
    ]
  });
}

/** 图表渲染的延时句柄：等 DOM 更新完成，卸载时必须清理 */
let renderTimer: ReturnType<typeof setTimeout> | null = null;

async function load() {
  loading.value = true;
  try {
    const d = await getDashboard();
    Object.assign(data, d);
    if (renderTimer) clearTimeout(renderTimer);
    renderTimer = setTimeout(() => {
      renderTrend();
      renderTop();
    }, 30);
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  loading.value = false;
}

const fmtTime = (s: string) =>
  s ? String(s).replace("T", " ").slice(0, 16) : "-";

function go(path: string) {
  if (path) router.push(path);
}

function onResize() {
  trendChart?.resize();
  topChart?.resize();
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
  trendChart?.dispose();
  topChart?.dispose();
});
</script>

<template>
  <div v-loading="loading">
    <!-- 问候卡 -->
    <el-card shadow="never" class="greet-card">
      <div class="greet-inner">
        <div>
          <div class="greet-title">
            {{ greetTime }}，{{ userInfo.username }}
          </div>
          <div class="greet-sub">
            {{
              data.activeTotal > 0
                ? `当前有 ${data.activeTotal} 个表单正在收集数据`
                : "还没有正在收集的表单，创建一个开始收集吧"
            }}
            <template v-if="data.pending > 0">
              ·
              <el-link
                style="color: #ffe58f; font-size: 13px"
                @click="router.push('/forms/index')"
              >
                {{ data.pending }} 条提交待审核
              </el-link>
            </template>
          </div>
        </div>
        <div class="greet-actions">
          <el-button
            :icon="FolderOpened"
            @click="router.push('/uploads/index')"
          >
            附件
          </el-button>
          <el-button
            v-if="isAdminUser"
            :icon="List"
            @click="router.push('/logs/fill')"
          >
            日志
          </el-button>
          <el-button
            v-if="canCreateForm"
            type="warning"
            :icon="Plus"
            @click="router.push('/forms/index')"
          >
            新建表单
          </el-button>
        </div>
      </div>
    </el-card>

    <!-- 统计卡片：第一行表单统计（4 张） -->
    <el-row :gutter="16" class="mt-4">
      <el-col v-for="c in cards" :key="c.label" :xs="12" :sm="6">
        <el-card
          shadow="hover"
          class="stat-card"
          :class="{ clickable: c.to }"
          @click="go(c.to)"
        >
          <div class="stat-inner">
            <div class="stat-icon" :style="{ background: c.bg }">
              <el-icon :size="26" :color="c.color">
                <component :is="c.icon" />
              </el-icon>
            </div>
            <div class="stat-text">
              <div class="stat-num">{{ c.value }}</div>
              <div class="stat-label">{{ c.label }}</div>
              <div class="stat-sub">
                {{ c.sub }}
                <span
                  v-if="c.delta !== undefined && c.delta !== 0"
                  class="delta"
                  :class="c.delta > 0 ? 'up' : 'down'"
                >
                  <el-icon :size="12">
                    <component :is="c.delta > 0 ? CaretTop : CaretBottom" />
                  </el-icon>
                  {{
                    c.deltaIsPercent
                      ? Math.abs(c.delta) + "%"
                      : Math.abs(c.delta)
                  }}
                </span>
              </div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 内容卡：第二行（表单/页面/附件/待审核，各占 1/4） -->
    <el-row :gutter="16" class="mt-4">
      <el-col v-for="c in infoCards" :key="c.label" :xs="12" :sm="6">
        <el-card
          shadow="hover"
          class="stat-card"
          :class="{ clickable: c.to }"
          @click="go(c.to)"
        >
          <div class="stat-inner">
            <div class="stat-icon" :style="{ background: c.bg }">
              <el-icon :size="26" :color="c.color">
                <component :is="c.icon" />
              </el-icon>
            </div>
            <div class="stat-text">
              <div class="stat-num">{{ c.value }}</div>
              <div class="stat-label">{{ c.label }}</div>
              <div class="stat-sub">{{ c.sub }}</div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="16" class="mt-4">
      <el-col :xs="24" :sm="15">
        <el-card shadow="never">
          <template #header>
            <div class="card-head">
              <span>提交趋势</span>
              <el-radio-group
                v-model="trendRange"
                size="small"
                @change="renderTrend"
              >
                <el-radio-button value="7">近 7 日</el-radio-button>
                <el-radio-button value="30">近 30 日</el-radio-button>
              </el-radio-group>
            </div>
          </template>
          <div ref="trendEl" style="height: 320px" />
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="9">
        <el-card shadow="never">
          <template #header>
            <div class="card-head">
              <span>表单提交排行</span>
            </div>
          </template>
          <div ref="topEl" style="height: 320px" />
          <el-empty
            v-if="!data.topForms?.length"
            description="暂无数据"
            :image-size="60"
            style="margin-top: -300px"
          />
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="16" class="mt-4">
      <el-col :span="24">
        <el-card shadow="never">
          <template #header>
            <div class="card-head">
              <span>最新提交</span>
              <el-link type="primary" @click="router.push('/forms/index')">
                全部表单
                <el-icon class="el-icon--right"><ArrowRight /></el-icon>
              </el-link>
            </div>
          </template>
          <el-table :data="data.recent" empty-text="暂无提交" size="default">
            <el-table-column prop="formTitle" label="表单" min-width="160">
              <template #default="{ row }">
                <el-link
                  type="primary"
                  @click="router.push('/forms/data/' + row.formId)"
                >
                  {{ row.formTitle }}
                </el-link>
              </template>
            </el-table-column>
            <el-table-column
              prop="preview"
              label="内容摘要"
              min-width="300"
              show-overflow-tooltip
            />
            <el-table-column prop="createdAt" label="时间" width="170">
              <template #default="{ row }">{{
                fmtTime(row.createdAt)
              }}</template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>
    </el-row>

    <!-- 热门页面 / 热门推广位：一行两列 -->
    <el-row :gutter="16" class="mt-4">
      <el-col :xs="24" :sm="12">
        <el-card shadow="never">
          <template #header>
            <div class="card-head">
              <span>热门页面</span>
              <el-link type="primary" @click="router.push('/pages/index')">
                页面管理
                <el-icon class="ml-1"><ArrowRight /></el-icon>
              </el-link>
            </div>
          </template>
          <el-table
            :data="data.topPages || []"
            size="small"
            :show-header="true"
          >
            <el-table-column label="页面" min-width="140">
              <template #default="{ row }">
                <el-link
                  type="primary"
                  :underline="false"
                  @click="router.push('/page/edit/' + row.id)"
                >
                  {{ row.title }}
                </el-link>
              </template>
            </el-table-column>
            <el-table-column label="浏览量" width="90" align="center">
              <template #default="{ row }">{{
                formatNum(row.view_count)
              }}</template>
            </el-table-column>
            <el-table-column label="访问" width="70" align="center">
              <template #default="{ row }">
                <el-link
                  type="primary"
                  :underline="false"
                  @click="openPage(row.shareUrl)"
                >
                  打开
                </el-link>
              </template>
            </el-table-column>
            <template #empty>
              <div style="color: #909399; padding: 20px 0; font-size: 13px">
                暂无已发布的页面
              </div>
            </template>
          </el-table>
        </el-card>
      </el-col>

      <el-col :xs="24" :sm="12">
        <el-card shadow="never">
          <template #header>
            <div class="card-head">
              <span>热门推广位</span>
              <el-link type="primary" @click="router.push('/promos/index')">
                引流中心
                <el-icon class="ml-1"><ArrowRight /></el-icon>
              </el-link>
            </div>
          </template>
          <el-table :data="data.topPromos || []" size="small">
            <el-table-column label="推广位" min-width="120">
              <template #default="{ row }">
                <el-link
                  type="primary"
                  :underline="false"
                  @click="router.push('/promos/index')"
                >
                  {{ row.name }}
                </el-link>
              </template>
            </el-table-column>
            <el-table-column label="类型" width="76" align="center">
              <template #default="{ row }">
                <el-tag
                  size="small"
                  effect="plain"
                  :type="row.type === 'short' ? 'info' : 'primary'"
                >
                  {{ row.type === "short" ? "短链" : "活码" }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="量" width="76" align="center">
              <template #default="{ row }">{{
                formatNum(row.click_count)
              }}</template>
            </el-table-column>
            <el-table-column label="访问" width="70" align="center">
              <template #default="{ row }">
                <el-link
                  type="primary"
                  :underline="false"
                  @click="openPage(row.shareUrl)"
                >
                  打开
                </el-link>
              </template>
            </el-table-column>
            <template #empty>
              <div style="color: #909399; padding: 20px 0; font-size: 13px">
                暂无推广位
              </div>
            </template>
          </el-table>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<style scoped lang="scss">
.greet-card {
  :deep(.el-card__body) {
    padding: 22px 26px;
  }

  background: linear-gradient(120deg, #409eff 0%, #6f9ae0 60%, #8fb7f5 100%);
  border: none;
  color: #fff;

  .greet-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
  }

  .greet-title {
    font-size: 20px;
    font-weight: 700;
  }

  .greet-sub {
    opacity: 0.9;
    font-size: 13px;
    margin-top: 6px;
  }

  .greet-actions {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
  }
}

.stat-card {
  cursor: default;

  &.clickable {
    cursor: pointer;
  }

  :deep(.el-card__body) {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 20px;
  }

  .stat-icon {
    width: 52px;
    height: 52px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .stat-num {
    font-size: 26px;
    font-weight: 700;
    line-height: 1.2;
  }

  .stat-label {
    color: #909399;
    font-size: 13px;
    margin-top: 2px;
  }

  .stat-sub {
    color: #909399;
    font-size: 12px;
    margin-top: 2px;
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .delta {
    display: inline-flex;
    align-items: center;
    gap: 1px;
    font-weight: 600;

    &.up {
      color: var(--el-color-success);
    }

    &.down {
      color: var(--el-color-danger);
    }
  }
}

.card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.empty-line {
  color: #909399;
  text-align: center;
  padding: 24px 0;
  font-size: 13px;
}
</style>
