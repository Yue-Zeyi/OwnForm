<script setup lang="ts">
import { ref, reactive, computed, onMounted } from "vue";
import { message } from "@/utils/message";
import { ElMessageBox } from "element-plus";
import { http } from "@/utils/http";
import {
  getLicenseStatus,
  activateLicense,
  verifyLicense,
  checkUpdate as checkUpdateApi,
  applyUpdate
} from "@/api/ownform";

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

/* ---------- 授权与在线更新 ---------- */
const lic = reactive<any>({
  server: "",
  license: "",
  domain: "",
  activated: false,
  expire: "",
  configured: false
});
const licenseInput = ref("");
const activating = ref(false);
const applying = ref(false);
const upd = ref<any>({});

async function loadLicense() {
  try {
    const st = await getLicenseStatus();
    d.version = st.version;
    lic.server = st.server || "";
    lic.license = st.license || "";
    lic.domain = st.domain || "";
    lic.activated = !!st.activated;
    lic.expire = st.expire || "";
    lic.configured = !!st.configured;
  } catch {
    /* 忽略 */
  }
}

async function doActivate() {
  if (!lic.server) return message("请先填写授权服务器地址", { type: "warning" });
  if (!licenseInput.value) return message("请输入授权码", { type: "warning" });
  activating.value = true;
  try {
    await http.post("/sys/settings", {
      data: { update_server_url: lic.server, license_code: licenseInput.value }
    });
    const res = await activateLicense(licenseInput.value);
    message(res.msg || "激活成功", { type: "success" });
    await loadLicense();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  activating.value = false;
}

async function doVerify() {
  try {
    const res = await verifyLicense();
    if (res.ok) {
      lic.expire = res.expire || lic.expire;
      message("授权有效" + (res.expire ? "，有效期至 " + res.expire : ""), { type: "success" });
    } else {
      message(res.msg || "授权校验未通过", { type: "error" });
    }
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

async function checkUpdate() {
  checking.value = true;
  try {
    upd.value = await checkUpdateApi();
    if (!upd.value.ok) {
      message(upd.value.msg || "检查失败，请确认已填写授权服务器并激活", { type: "error" });
    } else if (!upd.value.update) {
      message("已是最新版本 v" + upd.value.current, { type: "success" });
    }
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  checking.value = false;
}

async function doApply() {
  const i = upd.value;
  try {
    await ElMessageBox.confirm(
      `将在线升级至 v${i.version}：自动下载并覆盖系统文件${i.notes ? "（" + i.notes + "）" : ""}。更新前请确认已备份站点与数据库。继续？`,
      "在线热更新",
      { type: "warning", confirmButtonText: "开始更新", cancelButtonText: "取消" }
    );
  } catch {
    return;
  }
  applying.value = true;
  try {
    const res = await applyUpdate({ version: i.version, url: i.url, sha256: i.sha256 });
    message(`已升级至 v${res.version}（覆盖 ${res.copied} 个文件）`, { type: "success" });
    await loadLicense();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  applying.value = false;
}

onMounted(() => {
  load();
  loadLicense();
});
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
              <template v-if="lic.activated">
                <el-tag size="small" type="success">已授权</el-tag>
                {{ lic.license }}
                <template v-if="lic.expire">· 有效期至 {{ lic.expire }}</template>
                <el-button link type="primary" size="small" @click="doVerify">
                  校验
                </el-button>
              </template>
              <template v-else>未激活——填写授权服务器与授权码后可在线更新</template>
            </div>
          </div>
        </div>
        <el-button :loading="checking" @click="checkUpdate">检查更新</el-button>
      </div>

      <el-divider style="margin: 16px 0" />
      <el-form label-width="110px" label-position="left" style="max-width: 460px">
        <el-form-item label="授权服务器">
          <el-input
            v-model="lic.server"
            placeholder="由系统提供方分配"
            :disabled="lic.activated"
          />
        </el-form-item>
        <el-form-item v-if="!lic.activated" label="授权码">
          <div style="display: flex; gap: 8px; width: 100%">
            <el-input v-model="licenseInput" placeholder="如 OF-XXXX-XXXX" />
            <el-button :loading="activating" @click="doActivate">激活</el-button>
          </div>
        </el-form-item>
      </el-form>

      <div v-if="upd.ok && upd.update" class="update-box">
        <div class="update-ver">
          发现新版本 v{{ upd.version }}
          <span class="form-tip" style="margin-left: 8px">
            当前 v{{ upd.current }}
          </span>
        </div>
        <div class="form-tip" style="margin: 6px 0 12px">{{ upd.notes }}</div>
        <el-button type="primary" :loading="applying" @click="doApply">
          立即更新
        </el-button>
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
.update-box {
  margin-top: 14px;
  padding: 14px 16px;
  border: 1px solid var(--el-color-primary-light-5);
  background: var(--el-color-primary-light-9);
  border-radius: 8px;
}
.update-ver {
  font-size: 15px;
  font-weight: 600;
}
</style>
