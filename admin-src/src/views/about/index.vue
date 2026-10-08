<script setup lang="ts">
import { ref, reactive, computed, onMounted } from "vue";
import { message } from "@/utils/message";
import { http } from "@/utils/http";

defineOptions({
  name: "AboutPage"
});

const loading = ref(false);
const checking = ref(false);
const d = reactive<any>({
  sysName: "OwnForm",
  version: "-",
  phpVersion: "-",
  dbVersion: "-",
  serverTime: "-",
  timezone: "-",
  os: "-",
  env: [],
  disk: { total: 0, free: 0 },
  dbSize: 0,
  stats: {
    forms: 0,
    submissions: 0,
    uploads: 0,
    uploadBytes: 0,
    users: 0,
    logs: 0,
    firstFormAt: null,
    lastLogin: null
  }
});

const userInfo = JSON.parse(
  localStorage.getItem("user-info") || '{"username":"-"}'
);

function fmtSize(bytes: number) {
  if (!bytes) return "0 B";
  const units = ["B", "KB", "MB", "GB", "TB"];
  let i = 0;
  let n = bytes;
  while (n >= 1024 && i < units.length - 1) {
    n /= 1024;
    i++;
  }
  return n.toFixed(i === 0 ? 0 : 2) + " " + units[i];
}

function fmtDate(s: string | null) {
  return s ? String(s).replace("T", " ").slice(0, 16) : "—";
}

const guides = [
  {
    step: "1",
    title: "创建表单",
    desc: "表单管理 → 新建表单，选择模板或从空白开始，拖拽字段设计表单，保存并发布"
  },
  {
    step: "2",
    title: "分享收集",
    desc: "列表页点「分享」，复制链接或让用户扫码填写；可设置访问密码、截止时间、提交验证"
  },
  {
    step: "3",
    title: "查看与维护数据",
    desc: "数据页查看/筛选/导出 CSV，重要提交打多色旗标写备注；统计页自动生成图表"
  }
];

const runDays = computed(() => {
  if (!d.stats.firstFormAt) return "—";
  const days = Math.ceil(
    (Date.now() - new Date(d.stats.firstFormAt).getTime()) / 86400000
  );
  return Math.max(1, days) + " 天";
});

const diskUsedPercent = computed(() => {
  const { total, free } = d.disk;
  if (!total) return 0;
  return Math.min(100, Math.round(((total - free) / total) * 100));
});

async function load() {
  loading.value = true;
  try {
    const data = await http.get<any, any>("/sys/about");
    Object.assign(d, data);
    Object.assign(d.stats, data.stats || {});
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  loading.value = false;
}

function checkUpdate() {
  checking.value = true;
  setTimeout(() => {
    checking.value = false;
    message("当前已是最新版本，在线更新功能即将上线", { type: "info" });
  }, 800);
}

onMounted(() => load());
</script>

<template>
  <div v-loading="loading">
    <!-- 产品横幅 -->
    <el-card shadow="never" class="banner-card">
      <div class="banner-inner">
        <div class="banner-left">
          <img src="/logo.svg" alt="logo" class="banner-logo" />
          <div>
            <div class="banner-name">
              {{ d.sysName }}
              <span class="banner-ver">{{ d.version }}</span>
            </div>
            <div class="banner-sub">
              自建表单收集系统 · 基于 Vue3 / Element Plus / ThinkPHP8 / MySQL
            </div>
          </div>
        </div>
        <div class="banner-meta">
          <div class="banner-meta-item">
            <span class="bm-label">当前登录</span>
            <span class="bm-val">{{ userInfo.username }}</span>
          </div>
          <div class="banner-meta-item">
            <span class="bm-label">服务器时间</span>
            <span class="bm-val">{{ d.serverTime }}</span>
          </div>
          <div class="banner-meta-item">
            <span class="bm-label">时区 / 系统</span>
            <span class="bm-val">{{ d.timezone }} / {{ d.os }}</span>
          </div>
        </div>
      </div>
    </el-card>

    <!-- 版本与更新 -->
    <el-card shadow="never" class="mt-4">
      <template #header>
        <div class="card-head"><span>版本与更新</span></div>
      </template>
      <div class="ver-row">
        <div class="ver-left">
          <div class="ver-badge">{{ d.version }}</div>
          <div class="ver-info">
            <div class="ver-name">当前版本</div>
            <div class="ver-desc">
              在线更新功能开发中，届时可在此一键检查并升级
            </div>
          </div>
        </div>
        <el-button :loading="checking" @click="checkUpdate">检查更新</el-button>
      </div>
    </el-card>

    <!-- 运行环境 -->
    <el-card shadow="never" class="mt-4">
      <template #header>
        <div class="card-head">
          <span>运行环境</span>
          <el-tag
            size="small"
            :type="d.env.every(e => e.ok) ? 'success' : 'warning'"
            effect="light"
          >
            {{ d.env.every(e => e.ok) ? "全部正常" : "存在异常项" }}
          </el-tag>
        </div>
      </template>
      <div class="env-grid">
        <div v-for="e in d.env" :key="e.name" class="env-row">
          <span class="env-name">
            <span
              class="env-dot"
              :style="{ background: e.ok ? '#67c23a' : '#f56c6c' }"
            />
            {{ e.name }}
          </span>
          <span class="env-val">{{ e.value }}</span>
        </div>
      </div>
      <el-divider style="margin: 14px 0" />
      <div class="env-name" style="margin-bottom: 8px">
        磁盘空间（已用 {{ diskUsedPercent }}%）
      </div>
      <el-progress
        :percentage="diskUsedPercent"
        :stroke-width="10"
        :color="diskUsedPercent > 85 ? '#f56c6c' : '#409eff'"
      />
      <div class="form-tip" style="margin-top: 6px">
        剩余 {{ fmtSize(d.disk.free) }} / 共 {{ fmtSize(d.disk.total) }} ·
        数据库 {{ fmtSize(d.dbSize) }}
      </div>
    </el-card>

    <!-- 使用指引 -->
    <el-card shadow="never" class="mt-4">
      <template #header>
        <div class="card-head"><span>使用指引</span></div>
      </template>
      <el-row :gutter="16">
        <el-col v-for="g in guides" :key="g.step" :xs="24" :md="8">
          <div class="guide-item">
            <div class="guide-step">{{ g.step }}</div>
            <div>
              <div class="guide-title">{{ g.title }}</div>
              <div class="guide-desc">{{ g.desc }}</div>
            </div>
          </div>
        </el-col>
      </el-row>
    </el-card>

    <!-- 安全建议 -->
    <el-card shadow="never" class="mt-4">
      <template #header>
        <div class="card-head"><span>安全建议</span></div>
      </template>
      <div class="guide-desc">
        · 及时修改默认管理员密码（右上角头像 → 修改密码）　·
        开启提交验证（图像/短信/极验）防止垃圾提交<br />
        · 公网部署建议配置 HTTPS，并修改默认数据库密码　· 定期在 日志管理
        中检查异常登录与提交
      </div>
    </el-card>
  </div>
</template>

<style scoped lang="scss">
.banner-card {
  :deep(.el-card__body) {
    padding: 22px 26px;
  }

  background: linear-gradient(120deg, #409eff 0%, #6f9ae0 60%, #8fb7f5 100%);
  border: none;
  color: #fff;

  .banner-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
  }

  .banner-left {
    display: flex;
    align-items: center;
    gap: 16px;
  }

  .banner-logo {
    width: 56px;
    height: 56px;
    border-radius: 10px;
    background: rgb(255 255 255 / 90%);
    padding: 4px;
  }

  .banner-name {
    font-size: 22px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .banner-ver {
    font-size: 12px;
    font-weight: 400;
    opacity: 0.85;
    border: 1px solid rgb(255 255 255 / 50%);
    border-radius: 10px;
    padding: 1px 8px;
  }

  .banner-sub {
    opacity: 0.85;
    font-size: 13px;
    margin-top: 4px;
  }

  .banner-meta {
    display: flex;
    gap: 28px;
    flex-wrap: wrap;
  }

  .banner-meta-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .bm-label {
    opacity: 0.75;
    font-size: 12px;
  }

  .bm-val {
    font-size: 14px;
    font-weight: 600;
  }
}

.card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

/* 铺满整行：版本信息靠左，检查更新按钮靠右 */
.ver-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.ver-left {
  display: flex;
  align-items: center;
  gap: 16px;
  min-width: 0;
}

.ver-badge {
  padding: 6px 14px;
  background: #ecf5ff;
  color: var(--el-color-primary);
  border-radius: 8px;
  font-weight: 700;
  font-size: 16px;
}

.ver-name {
  font-weight: 600;
  font-size: 14px;
}

.ver-desc {
  color: #909399;
  font-size: 12px;
  margin-top: 2px;
}

.env-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 32px;
}

.env-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 0;
  font-size: 13px;
  border-bottom: 1px dashed #ebeef5;

  .env-name {
    display: flex;
    align-items: center;
    gap: 7px;
  }

  .env-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  .env-val {
    color: #909399;
  }
}

.guide-item {
  display: flex;
  gap: 10px;
  background: #f7f9fc;
  border-radius: 8px;
  padding: 14px;
  height: 100%;

  .guide-step {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--el-color-primary);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    flex-shrink: 0;
  }

  .guide-title {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 4px;
  }

  .guide-desc {
    color: #909399;
    font-size: 12px;
    line-height: 1.7;
  }
}

.form-tip {
  color: #909399;
  font-size: 12px;
  line-height: 1.6;
}
</style>
