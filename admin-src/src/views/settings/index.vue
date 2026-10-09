<script setup lang="ts">
import { ref, reactive, onMounted, watch } from "vue";
import { message } from "@/utils/message";
import { ElMessageBox } from "element-plus";
import {
  getSysSettings,
  saveSysSettings,
  testNotify,
  testStorage,
  runCleanup
} from "@/api/ownform";
import { brand, loadBrand } from "@/utils/brand";
import { http } from "@/utils/http";

defineOptions({
  name: "SettingsPage"
});

const loading = ref(false);
const saving = ref(false);
const activeTab = ref("brand");
const aiPreset = ref("");
const aiTemp = ref(0.7);

const AI_PRESETS: Record<string, { url: string; model: string }> = {
  deepseek: { url: "https://api.deepseek.com/v1", model: "deepseek-chat" },
  zhipu: { url: "https://open.bigmodel.cn/api/paas/v4", model: "glm-4-flash" },
  qwen: {
    url: "https://dashscope.aliyuncs.com/compatible-mode/v1",
    model: "qwen-plus"
  },
  custom: { url: "", model: "" }
};

function applyPreset(name: string) {
  const p = AI_PRESETS[name];
  if (!p) return;
  sys.ai_base_url = p.url;
  sys.ai_model = p.model;
}

const sys = reactive<any>({
  sys_name: "OwnForm",
  sys_logo: "",
  login_subtitle: "自建表单收集系统",
  sys_copyright: "",
  sys_icp: "",
  webhook_url: "",
  webhook_format: "raw",
  site_domains: "",
  notify_on_submit: "0",
  // 数据保留天数（0=永久）：超过 N 天的提交每日自动清理
  retention_days: "0",
  // 每日汇总邮件
  digest_enabled: "0",
  digest_email: "",
  // 支付渠道（渠道勾选为 JSON 数组字符串，页面上用数组桥接）
  pay_channels: "[]",
  pay_wx_app_id: "",
  pay_wx_mch_id: "",
  pay_wx_serial_no: "",
  pay_wx_apiv3_key: "",
  pay_wx_mch_cert: "",
  pay_wx_mch_key: "",
  pay_ali_app_id: "",
  pay_ali_private_key: "",
  pay_ali_app_public_cert: "",
  pay_ali_public_cert: "",
  pay_transfer_name: "",
  pay_transfer_account: "",
  pay_transfer_qr: "",
  pay_transfer_tip: "",

  storage_type: "local",
  storage_domain: "",
  cos_region: "",
  cos_bucket: "",
  cos_secret_id: "",
  cos_secret_key: "",
  oss_access_key_id: "",
  oss_access_key_secret: "",
  oss_endpoint: "",
  oss_bucket: "",
  qiniu_access_key: "",
  qiniu_secret_key: "",
  qiniu_bucket: "",
  sms_provider: "",
  sms_access_key_id: "",
  sms_access_key_secret: "",
  sms_sign_name: "",
  sms_template_code: "",
  sms_sdk_app_id: "",
  sms_region: "ap-guangzhou",
  geetest_captcha_id: "",
  geetest_captcha_key: ""
});

const userInfo = JSON.parse(
  localStorage.getItem("user-info") || '{"username":"-"}'
);

/**
 * 需要「只写不读」的敏感字段
 *
 * 后端对它们只返回掩码（如 sk-a****xyz）并附带 `<key>_set` 标记。
 * 提交时若仍是掩码，说明用户没有改动这一项，必须剔除后再提交，
 * 否则会把掩码字符串当成新密钥存进去，导致真实密钥被覆盖丢失。
 */
const SECRET_FIELDS = [
  "cos_secret_id",
  "cos_secret_key",
  "oss_access_key_id",
  "oss_access_key_secret",
  "qiniu_access_key",
  "qiniu_secret_key",
  "sms_access_key_id",
  "sms_access_key_secret",
  "mail_pass",
  "geetest_captcha_key",
  "ai_key"
];

/** 判断一个值是否为后端返回的掩码（首尾少量字符 + 中间连续星号） */
const isMasked = (v: unknown) =>
  typeof v === "string" && v !== "" && /^[^*]{0,3}\*+.*[^*]{0,3}$/.test(v);

async function load() {
  loading.value = true;
  try {
    const d = await getSysSettings();
    Object.assign(sys, d);
    aiTemp.value = Number(sys.ai_temperature) || 0.7;
  } catch (e) {
    /* 非管理员忽略 */
  }
  loading.value = false;
}

const testMailTo = ref("");
const mailTesting = ref(false);

async function doTestMail() {
  if (!testMailTo.value || !testMailTo.value.includes("@")) {
    message("请先填写接收测试邮件的邮箱", { type: "warning" });
    return;
  }
  mailTesting.value = true;
  try {
    await save();
    const d = await http.post<any, any>("/sys/test-mail", {
      data: { to: testMailTo.value }
    });
    message(d.msg || "已发送", { type: "success" });
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  mailTesting.value = false;
}

async function save() {
  saving.value = true;
  try {
    sys.ai_temperature = String(aiTemp.value);
    // 剔除未改动的掩码密钥：留空表示「不修改」，避免把掩码当新值写入
    const payload: any = { ...sys };
    SECRET_FIELDS.forEach(k => {
      if (isMasked(payload[k])) delete payload[k];
    });
    await saveSysSettings(payload);
    await loadBrand();
    message("配置已保存", { type: "success" });
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  saving.value = false;
}

async function uploadLogo(opt: any) {
  const fd = new FormData();
  fd.append("file", opt.file);
  try {
    // 用 fetch 上传：FormData 的 boundary 必须由浏览器自动生成，
    // 手动设置 Content-Type 会丢 boundary 导致后端收不到文件
    const res = await fetch("/api/upload", {
      method: "POST",
      credentials: "same-origin",
      body: fd
    });
    const d = await res.json();
    if (d.code !== 0) throw new Error(d.msg || "上传失败");
    sys.sys_logo = d.data.url;
    message("Logo 已上传，保存后生效", { type: "success" });
  } catch (e: any) {
    message(e.message || "上传失败", { type: "error" });
  }
  opt.onSuccess?.();
}

async function doTestStorage() {
  try {
    await save();
    const d = await testStorage();
    message(d && d.url ? "上传成功：" + d.url : "上传成功", {
      type: "success"
    });
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

async function doTestNotify() {
  try {
    await save();
    await testNotify();
    message("测试消息已发送，请到对应群/接收端确认", { type: "success" });
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

/** 支付渠道勾选桥接：sys.pay_channels(JSON 字符串) ↔ 数组 */
const payChannelArr = ref<string[]>([]);
watch(payChannelArr, (v) => {
  sys.pay_channels = JSON.stringify(v);
});
watch(
  () => sys.pay_channels,
  (v) => {
    try {
      const arr = JSON.parse(v || "[]");
      if (JSON.stringify(arr) !== JSON.stringify(payChannelArr.value)) {
        payChannelArr.value = Array.isArray(arr) ? arr : [];
      }
    } catch {
      /* 忽略非法 JSON */
    }
  },
  { immediate: true }
);

/** 上传转账收款码（复用素材上传接口，管理员身份） */
async function uploadPayQr(opt: any) {
  const fd = new FormData();
  fd.append("file", opt.file);
  fd.append("formId", "0");
  try {
    const d = await http.post<any, any>("/upload", fd, {
      headers: { "Content-Type": "multipart/form-data" }
    });
    sys.pay_transfer_qr = d.url || d.path || "";
    message("收款码已上传，记得保存设置", { type: "success" });
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

/** 复制计划任务命令 */
async function copyCronCmd() {
  try {
    await navigator.clipboard.writeText("php 你的站点目录/think cleanup:run");
    message("已复制", { type: "success" });
  } catch {
    message("复制失败，请手动选择文本复制", { type: "warning" });
  }
}

/** 手动清理：范围与天数自行控制，二次确认后执行 */
type CleanScope = "expired" | "recycle" | "orphan";
const cleanScopes = ref<CleanScope[]>(["expired"]);
const cleanDays = reactive<Record<CleanScope, number>>({
  expired: 90,
  recycle: 30,
  orphan: 30
});
const scopeLabels: Record<CleanScope, string> = {
  expired: "过期提交",
  recycle: "回收站内容",
  orphan: "孤儿附件"
};
const cleaning = ref(false);

async function doCleanup() {
  if (!cleanScopes.value.length) {
    message("请先勾选要清理的范围", { type: "warning" });
    return;
  }
  const lines = cleanScopes.value.map(
    (k) => `· ${scopeLabels[k]}：超过 ${cleanDays[k]} 天`
  );
  try {
    await ElMessageBox.confirm(
      `将永久删除以下数据，不可恢复：<br />${lines.join("<br />")}`,
      "手动执行清理",
      {
        type: "warning",
        dangerouslyUseHTMLString: true,
        confirmButtonText: "立即清理",
        cancelButtonText: "取消"
      }
    );
  } catch {
    return;
  }
  cleaning.value = true;
  try {
    const d = await runCleanup(cleanScopes.value, cleanDays);
    const counts: Record<CleanScope, number> = {
      expired: d.expired || 0,
      recycle: d.recycle || 0,
      orphan: d.uploads || 0
    };
    const parts = cleanScopes.value.map(
      (k) => `${scopeLabels[k]} ${counts[k]} ${k === "orphan" ? "个" : "条"}`
    );
    const total = Object.values(counts).reduce((a, b) => a + b, 0);
    message((total ? "清理完成：" : "没有需要清理的内容：") + parts.join("，"), {
      type: "success"
    });
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  cleaning.value = false;
}

onMounted(() => load());
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never" :body-style="{ padding: '0 16px 16px' }">
      <el-tabs v-model="activeTab" class="settings-tabs">
        <!-- 基础与品牌 -->
        <el-tab-pane label="基础与品牌" name="brand">
          <div class="tab-head">
            <span class="form-tip"
              >系统名称 / Logo / 登录页欢迎语 / 页脚版权与备案，适合交付客户时白标</span
            >
          </div>
          <el-form label-width="110px" label-position="left" class="tab-form">
            <el-form-item label="系统名称">
              <el-input
                v-model="sys.sys_name"
                placeholder="OwnForm"
                maxlength="30"
              />
              <div class="form-tip">浏览器标题、侧栏、登录页显示</div>
            </el-form-item>
            <el-form-item label="品牌 Logo">
              <div class="logo-row">
                <el-image
                  v-if="sys.sys_logo"
                  :src="sys.sys_logo"
                  fit="contain"
                  class="logo-preview"
                >
                  <template #error>
                    <div class="logo-preview logo-empty">图片</div>
                  </template>
                </el-image>
                <div v-else class="logo-preview logo-empty">默认</div>
                <div class="logo-ops">
                  <el-upload
                    :show-file-list="false"
                    accept="image/png,image/jpeg,image/svg+xml,image/webp"
                    :http-request="uploadLogo"
                  >
                    <el-button size="small" type="primary" plain
                      >上传图片</el-button
                    >
                  </el-upload>
                  <el-button
                    v-if="sys.sys_logo"
                    size="small"
                    @click="sys.sys_logo = ''"
                  >
                    恢复默认
                  </el-button>
                </div>
              </div>
              <div class="form-tip">
                建议正方形透明底
                PNG/SVG，≥128×128；保存后浏览器标题与登录页同步生效
              </div>
            </el-form-item>
            <el-form-item label="登录页欢迎语">
              <el-input
                v-model="sys.login_subtitle"
                placeholder="自建表单收集系统"
              />
            </el-form-item>
            <el-form-item label="页脚版权">
              <el-input
                v-model="sys.sys_copyright"
                placeholder="留空则显示 © 年份 系统名"
              />
            </el-form-item>
            <el-form-item label="ICP 备案号">
              <el-input
                v-model="sys.sys_icp"
                placeholder="如：粤ICP备XXXXXXXX号"
              />
            </el-form-item>
            <el-button type="primary" :loading="saving" @click="save"
              >保存品牌设置</el-button
            >
          </el-form>
        </el-tab-pane>

        <!-- 通知：webhook / SMTP 邮件 / 每日汇总 -->
        <el-tab-pane label="通知" name="notify">
          <div class="form-tip sec-tip">新提交到达时，可通过群机器人 / 自定义接口 / 邮件提醒；汇总邮件依赖下方 SMTP 配置</div>

          <el-divider content-position="left">群机器人 / Webhook</el-divider>
          <el-form label-width="110px" label-position="left" class="tab-form">
            <el-form-item label="启用通知">
              <el-switch
                v-model="sys.notify_on_submit"
                active-value="1"
                inactive-value="0"
              />
            </el-form-item>
            <el-form-item label="Webhook">
              <el-input
                v-model="sys.webhook_url"
                placeholder="https://..."
                :disabled="sys.notify_on_submit !== '1'"
              />
              <div class="form-tip">
                支持钉钉 / 企业微信 / 飞书群机器人地址，或接收 POST 的自定义地址
              </div>
            </el-form-item>
            <el-form-item label="消息格式">
              <el-select
                v-model="sys.webhook_format"
                style="width: 100%"
                :disabled="sys.notify_on_submit !== '1'"
              >
                <el-option label="钉钉机器人" value="dingtalk" />
                <el-option label="企业微信机器人" value="wecom" />
                <el-option label="飞书机器人" value="feishu" />
                <el-option label="自定义 JSON（POST）" value="raw" />
              </el-select>
            </el-form-item>
            <el-button
              type="primary"
              :loading="saving"
              :disabled="sys.notify_on_submit !== '1'"
              @click="save"
            >
              保存通知配置
            </el-button>
            <el-button
              :disabled="sys.notify_on_submit !== '1'"
              @click="doTestNotify"
            >
              发送测试消息
            </el-button>
          </el-form>

          <el-divider content-position="left">邮件通知（SMTP）</el-divider>
          <div class="form-tip sec-tip">推荐 QQ / 163 / 企业邮箱的 SMTP，密码填授权码（非登录密码）</div>
          <el-form label-width="120px" label-position="left" class="tab-form">
            <el-form-item label="SMTP 服务器">
              <el-input
                v-model="sys.mail_host"
                placeholder="如 smtp.qq.com"
                style="width: 260px"
              />
            </el-form-item>
            <el-form-item label="端口">
              <el-input
                v-model="sys.mail_port"
                style="width: 120px"
                placeholder="465"
              />
            </el-form-item>
            <el-form-item label="加密方式">
              <el-radio-group v-model="sys.mail_secure">
                <el-radio value="ssl">SSL（465 端口）</el-radio>
                <el-radio value="tls">STARTTLS（587 端口）</el-radio>
                <el-radio value="none">不加密（25 端口）</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item label="发件账号">
              <el-input
                v-model="sys.mail_user"
                placeholder="发件邮箱地址"
                style="width: 260px"
              />
            </el-form-item>
            <el-form-item label="授权码/密码">
              <el-input
                v-model="sys.mail_pass"
                type="password"
                show-password
                placeholder="邮箱服务商生成的 SMTP 授权码"
                style="width: 260px"
              />
            </el-form-item>
            <el-form-item label="发件人名称">
              <el-input
                v-model="sys.mail_from_name"
                placeholder="默认 OwnForm"
                style="width: 260px"
              />
            </el-form-item>
            <el-form-item label="发送测试">
              <el-input
                v-model="testMailTo"
                placeholder="填你的邮箱，点右侧按钮试发"
                style="width: 260px"
              />
              <el-button class="ml" :loading="mailTesting" @click="doTestMail"
                >发送测试邮件</el-button
              >
            </el-form-item>
            <el-button type="primary" :loading="saving" @click="save"
              >保存</el-button
            >
          </el-form>

          <el-divider content-position="left">每日汇总邮件</el-divider>
          <el-form label-width="110px" label-position="left" class="tab-form">
            <el-form-item label="每日汇总">
              <el-switch
                v-model="sys.digest_enabled"
                active-value="1"
                inactive-value="0"
              />
              <span class="form-tip" style="margin-left: 10px">
                每天首次进后台时发送一封昨日数据摘要邮件
              </span>
            </el-form-item>
            <el-form-item label="汇总邮箱">
              <el-input
                v-model="sys.digest_email"
                placeholder="接收汇总的邮箱，多人用逗号分隔"
                :disabled="sys.digest_enabled !== '1'"
              />
            </el-form-item>
            <el-button type="primary" :loading="saving" @click="save"
              >保存汇总设置</el-button
            >
          </el-form>
        </el-tab-pane>

        <!-- 第三方服务：存储 / 短信 / 极验 / AI -->
        <el-tab-pane label="第三方服务" name="service">
          <el-divider content-position="left">文件存储</el-divider>
          <div class="form-tip sec-tip">表单上传的图片/附件存放位置，切换后新文件走新通道，历史文件不受影响</div>
          <el-form label-width="110px" label-position="left" class="tab-form">
            <el-form-item label="存储位置">
              <el-select v-model="sys.storage_type" style="width: 100%">
                <el-option label="本地存储（public/storage）" value="local" />
                <el-option label="腾讯云 COS" value="cos" />
                <el-option label="阿里云 OSS" value="oss" />
                <el-option label="七牛云 Kodo" value="qiniu" />
              </el-select>
            </el-form-item>

            <template v-if="sys.storage_type === 'cos'">
              <el-form-item label="SecretId">
                <el-input v-model="sys.cos_secret_id" />
              </el-form-item>
              <el-form-item label="SecretKey">
                <el-input
                  v-model="sys.cos_secret_key"
                  type="password"
                  show-password
                />
              </el-form-item>
              <el-form-item label="Bucket">
                <el-input
                  v-model="sys.cos_bucket"
                  placeholder="name-1250000000"
                />
              </el-form-item>
              <el-form-item label="地域">
                <el-input v-model="sys.cos_region" placeholder="ap-guangzhou" />
              </el-form-item>
              <el-form-item label="加速域名">
                <el-input
                  v-model="sys.storage_domain"
                  placeholder="选填，如 https://cdn.example.com"
                />
              </el-form-item>
            </template>

            <template v-else-if="sys.storage_type === 'oss'">
              <el-form-item label="AccessKey ID">
                <el-input v-model="sys.oss_access_key_id" />
              </el-form-item>
              <el-form-item label="AccessKey Secret">
                <el-input
                  v-model="sys.oss_access_key_secret"
                  type="password"
                  show-password
                />
              </el-form-item>
              <el-form-item label="Endpoint">
                <el-input
                  v-model="sys.oss_endpoint"
                  placeholder="oss-cn-hangzhou.aliyuncs.com"
                />
              </el-form-item>
              <el-form-item label="Bucket">
                <el-input v-model="sys.oss_bucket" />
              </el-form-item>
              <el-form-item label="加速域名">
                <el-input
                  v-model="sys.storage_domain"
                  placeholder="选填，如 https://cdn.example.com"
                />
              </el-form-item>
            </template>

            <template v-else-if="sys.storage_type === 'qiniu'">
              <el-form-item label="AccessKey">
                <el-input v-model="sys.qiniu_access_key" />
              </el-form-item>
              <el-form-item label="SecretKey">
                <el-input
                  v-model="sys.qiniu_secret_key"
                  type="password"
                  show-password
                />
              </el-form-item>
              <el-form-item label="Bucket">
                <el-input v-model="sys.qiniu_bucket" />
              </el-form-item>
              <el-form-item label="加速域名">
                <el-input
                  v-model="sys.storage_domain"
                  placeholder="必填，如 https://cdn.example.com"
                />
                <div class="form-tip">七牛空间需绑定外链域名后才能访问文件</div>
              </el-form-item>
            </template>

            <div
              v-if="sys.storage_type !== 'local'"
              class="form-tip"
              style="margin-bottom: 12px"
            >
              建议使用仅授予该存储桶读写权限的子账号密钥
            </div>
            <el-button type="primary" :loading="saving" @click="save">
              保存存储配置
            </el-button>
            <el-button
              v-if="sys.storage_type !== 'local'"
              @click="doTestStorage"
            >
              测试上传
            </el-button>
          </el-form>

          <el-divider content-position="left">短信服务</el-divider>
          <div class="form-tip sec-tip">为表单的"短信验证码"提供发送能力</div>
          <el-form label-width="110px" label-position="left" class="tab-form">
            <el-form-item label="服务商">
              <el-select
                v-model="sys.sms_provider"
                style="width: 100%"
                placeholder="未启用短信验证"
              >
                <el-option label="不启用" value="" />
                <el-option label="阿里云短信" value="aliyun" />
                <el-option label="腾讯云短信" value="tencent" />
              </el-select>
              <div class="form-tip">
                启用后可在各表单的"提交验证"中选择短信验证码
              </div>
            </el-form-item>
            <template v-if="sys.sms_provider">
              <el-form-item label="AccessKey">
                <el-input
                  v-model="sys.sms_access_key_id"
                  placeholder="阿里云 AccessKey ID / 腾讯云 SecretId"
                />
              </el-form-item>
              <el-form-item label="AccessSecret">
                <el-input
                  v-model="sys.sms_access_key_secret"
                  type="password"
                  show-password
                  placeholder="对应密钥"
                />
              </el-form-item>
              <el-form-item label="短信签名">
                <el-input
                  v-model="sys.sms_sign_name"
                  placeholder="已审核通过的签名，如：OwnForm"
                />
              </el-form-item>
              <el-form-item label="模板 ID">
                <el-input
                  v-model="sys.sms_template_code"
                  placeholder="阿里云 SMS_xxx / 腾讯云 1xxxx"
                />
              </el-form-item>
              <el-form-item
                v-if="sys.sms_provider === 'tencent'"
                label="应用 ID"
              >
                <el-input
                  v-model="sys.sms_sdk_app_id"
                  placeholder="腾讯云 SmsSdkAppId"
                />
              </el-form-item>
              <el-form-item v-if="sys.sms_provider === 'tencent'" label="地域">
                <el-input v-model="sys.sms_region" placeholder="ap-guangzhou" />
              </el-form-item>
              <el-alert
                type="info"
                :closable="false"
                title="短信模板需包含验证码变量：阿里云用 ${code}，腾讯云模板第一参数为验证码、第二参数为有效期（5 分钟）"
              />
              <div style="margin-top: 12px">
                <el-button type="primary" :loading="saving" @click="save">
                  保存短信配置
                </el-button>
              </div>
            </template>
          </el-form>

          <el-divider content-position="left">行为验证（极验）</el-divider>
          <div class="form-tip sec-tip">极验 v4 人机验证，为表单的"极验"验证方式提供后端校验；在 geetest.com 申请 v4 后填写</div>
          <el-form label-width="110px" label-position="left" class="tab-form">
            <el-form-item label="验证 ID">
              <el-input
                v-model="sys.geetest_captcha_id"
                placeholder="captcha_id"
              />
            </el-form-item>
            <el-form-item label="密钥">
              <el-input
                v-model="sys.geetest_captcha_key"
                type="password"
                show-password
                placeholder="captcha_key"
              />
            </el-form-item>
            <div class="form-tip" style="margin-bottom: 12px">
              在 geetest.com 申请 v4
              验证后填写，各表单即可在"提交验证"中选择极验
            </div>
            <el-button type="primary" :loading="saving" @click="save">
              保存配置
            </el-button>
          </el-form>

          <el-divider content-position="left">AI 助理</el-divider>
          <div class="form-tip sec-tip">表单设计器内置的 AI 助理（对话式生成/修改表单），支持 DeepSeek / 智谱 GLM / 通义千问等任意 OpenAI 兼容接口</div>
          <el-form label-width="110px" label-position="left" class="tab-form">
            <el-form-item label="服务商预设">
              <el-select
                v-model="aiPreset"
                style="width: 100%"
                placeholder="选择后自动填充接口地址与模型"
                @change="applyPreset"
              >
                <el-option label="DeepSeek（深度求索）" value="deepseek" />
                <el-option label="智谱 GLM" value="zhipu" />
                <el-option label="通义千问 Qwen" value="qwen" />
                <el-option label="自定义（OpenAI 兼容）" value="custom" />
              </el-select>
            </el-form-item>
            <el-form-item label="接口地址">
              <el-input
                v-model="sys.ai_base_url"
                placeholder="如 https://api.deepseek.com/v1"
              />
              <div class="form-tip">
                OpenAI 兼容的根地址（不含 /chat/completions）
              </div>
            </el-form-item>
            <el-form-item label="模型名称">
              <el-input
                v-model="sys.ai_model"
                placeholder="如 deepseek-chat / glm-4-flash / qwen-plus"
              />
            </el-form-item>
            <el-form-item label="API Key">
              <el-input
                v-model="sys.ai_key"
                type="password"
                show-password
                placeholder="模型服务商的 API Key"
              />
            </el-form-item>
            <el-form-item label="随机性">
              <el-slider
                v-model="aiTemp"
                :min="0"
                :max="2"
                :step="0.1"
                style="width: 100%"
                show-input
                :show-input-controls="false"
              />
              <div class="form-tip">
                越低越稳定严谨，越高越发散创造性，一般 0.7 即可
              </div>
            </el-form-item>
            <el-alert
              type="info"
              :closable="false"
              title="配置保存后，设计器的 AI 助理将通过本系统后端代理调用你的模型，API Key 不暴露到浏览器；成员账号也可使用"
            />
            <div style="margin-top: 12px">
              <el-button type="primary" :loading="saving" @click="save">
                保存 AI 配置
              </el-button>
            </div>
          </el-form>

          <el-divider content-position="left">支付（表单收费）</el-divider>
          <div class="form-tip sec-tip">
            勾选已具备的能力，表单设置里即可开启提交收费；商户密钥证书加密存储、仅回显掩码
          </div>
          <el-form label-width="140px" label-position="left" class="tab-form">
            <el-form-item label="启用渠道">
              <el-checkbox-group v-model="payChannelArr" style="width: 100%">
                <el-checkbox value="wxpay_native">微信扫码（PC）</el-checkbox>
                <el-checkbox value="wxpay_h5">微信 H5（手机浏览器）</el-checkbox>
                <el-checkbox value="alipay_page">支付宝电脑网站</el-checkbox>
                <el-checkbox value="alipay_fce">支付宝当面付（扫码）</el-checkbox>
                <el-checkbox value="alipay_wap">支付宝手机网站</el-checkbox>
                <el-checkbox value="transfer">转账 + 人工核销</el-checkbox>
              </el-checkbox-group>
              <div class="form-tip">
                仅勾选你在下方完成配置且已开通的能力；回调地址由系统按域名池自动生成
              </div>
            </el-form-item>
          </el-form>
          <template v-if="payChannelArr.includes('wxpay_native') || payChannelArr.includes('wxpay_h5')">
            <div class="pay-head">微信支付（商户号）</div>
            <el-form label-width="140px" label-position="left" class="tab-form">
              <el-form-item label="AppID（公众号/小程序）">
                <el-input v-model="sys.pay_wx_app_id" placeholder="wx 开头" style="max-width: 320px" />
              </el-form-item>
              <el-form-item label="商户号 mch_id">
                <el-input v-model="sys.pay_wx_mch_id" placeholder="16 开头数字" style="max-width: 320px" />
              </el-form-item>
              <el-form-item label="商户证书序列号">
                <el-input v-model="sys.pay_wx_serial_no" placeholder="API 证书序列号" style="max-width: 420px" />
              </el-form-item>
              <el-form-item label="APIv3 密钥">
                <el-input v-model="sys.pay_wx_apiv3_key" type="password" show-password placeholder="商户平台设置的 APIv3 密钥" style="max-width: 420px" />
              </el-form-item>
              <el-form-item label="商户证书内容">
                <el-input v-model="sys.pay_wx_mch_cert" type="textarea" :rows="3" placeholder="apiclient_cert.pem 的完整内容（-----BEGIN CERTIFICATE-----）" />
              </el-form-item>
              <el-form-item label="商户私钥内容">
                <el-input v-model="sys.pay_wx_mch_key" type="textarea" :rows="3" placeholder="apiclient_key.pem 的完整内容（-----BEGIN PRIVATE KEY-----）" />
              </el-form-item>
            </el-form>
          </template>
          <template v-if="payChannelArr.includes('alipay_page') || payChannelArr.includes('alipay_fce') || payChannelArr.includes('alipay_wap')">
            <div class="pay-head">支付宝（开放平台应用）</div>
            <el-form label-width="140px" label-position="left" class="tab-form">
              <el-form-item label="AppID">
                <el-input v-model="sys.pay_ali_app_id" placeholder="应用 APPID" style="max-width: 320px" />
              </el-form-item>
              <el-form-item label="应用私钥">
                <el-input v-model="sys.pay_ali_private_key" type="textarea" :rows="3" placeholder="应用私钥 PEM 全文" />
              </el-form-item>
              <el-form-item label="应用公钥证书">
                <el-input v-model="sys.pay_ali_app_public_cert" type="textarea" :rows="3" placeholder="应用公钥证书全文（-----BEGIN CERTIFICATE-----）" />
              </el-form-item>
              <el-form-item label="支付宝公钥证书">
                <el-input v-model="sys.pay_ali_public_cert" type="textarea" :rows="3" placeholder="支付宝公钥证书全文（-----BEGIN CERTIFICATE-----）" />
              </el-form-item>
            </el-form>
          </template>
          <template v-if="payChannelArr.includes('transfer')">
            <div class="pay-head">转账核销</div>
            <el-form label-width="140px" label-position="left" class="tab-form">
              <el-form-item label="收款人">
                <el-input v-model="sys.pay_transfer_name" placeholder="如：*先生 / 公司名" style="max-width: 320px" />
              </el-form-item>
              <el-form-item label="收款账号">
                <el-input v-model="sys.pay_transfer_account" placeholder="微信号 / 支付宝账号 / 银行卡号" style="max-width: 320px" />
              </el-form-item>
              <el-form-item label="收款码图片">
                <div style="display: flex; gap: 12px; align-items: flex-start; width: 100%">
                  <el-image v-if="sys.pay_transfer_qr" :src="sys.pay_transfer_qr" fit="contain" style="width: 96px; height: 96px; border: 1px solid #ebeef5; border-radius: 8px" />
                  <div>
                    <el-upload :show-file-list="false" accept="image/png,image/jpeg,image/webp" :http-request="uploadPayQr">
                      <el-button size="small" type="primary" plain>上传收款码</el-button>
                    </el-upload>
                    <el-button v-if="sys.pay_transfer_qr" size="small" style="margin-left: 8px" @click="sys.pay_transfer_qr = ''">清除</el-button>
                  </div>
                </div>
              </el-form-item>
              <el-form-item label="提示语">
                <el-input v-model="sys.pay_transfer_tip" type="textarea" :rows="2" placeholder="展示在收款码下方的说明，如：转账后点击「我已完成转账」" />
              </el-form-item>
            </el-form>
          </template>
          <div style="margin-top: 10px">
            <el-button type="primary" :loading="saving" @click="save">保存支付配置</el-button>
          </div>
        </el-tab-pane>

        <!-- 数据与合规 -->
        <el-tab-pane label="数据与合规" name="retention">
          <div class="tab-head">
            <span class="form-tip"
              >提交含个人信息时，建议开启保留期以满足数据最小化要求；清理每日自动执行且不可恢复</span
            >
          </div>
          <el-form label-width="110px" label-position="left" class="tab-form">
            <el-form-item label="数据保留天数">
              <el-input-number
                v-model.number="sys.retention_days"
                :min="0"
                :max="3650"
                style="width: 160px"
              />
              <span class="form-tip" style="margin-left: 10px">
                天（0 = 永久保留；超过天数的提交每日自动清除，含回收站）
              </span>
            </el-form-item>
            <el-button type="primary" :loading="saving" @click="save"
              >保存</el-button
            >
          </el-form>
          <el-divider content-position="left">每日自动清理</el-divider>
          <div class="form-tip sec-tip">
            每天首次打开「数据概览」时自动按上方保留天数清理一次；推荐在服务器挂计划任务每日执行（无人登录也照常清理并发送汇总邮件）：
          </div>
          <el-input
            :model-value="'php 你的站点目录/think cleanup:run'"
            readonly
            size="small"
            style="max-width: 340px; margin-bottom: 6px"
          >
            <template #append>
              <el-button @click="copyCronCmd">复制</el-button>
            </template>
          </el-input>
          <div class="form-tip" style="margin-bottom: 14px">
            宝塔：计划任务 → Shell 脚本 → 每天凌晨执行上述命令，详见部署说明
          </div>

          <el-divider content-position="left">手动清理</el-divider>
          <div class="form-tip sec-tip">
            不等每日自动清理，按下方范围与天数立即执行一次，全部永久删除、不可恢复
          </div>
          <el-checkbox-group v-model="cleanScopes" class="clean-rows">
            <div class="clean-row">
              <el-checkbox value="expired">过期提交</el-checkbox>
              <span class="clean-desc">提交时间超过</span>
              <el-input-number
                v-model="cleanDays.expired"
                :min="1"
                :max="3650"
                size="small"
              />
              <span class="clean-desc">天的未删除提交</span>
            </div>
            <div class="clean-row">
              <el-checkbox value="recycle">回收站</el-checkbox>
              <span class="clean-desc">进入回收站超过</span>
              <el-input-number
                v-model="cleanDays.recycle"
                :min="1"
                :max="3650"
                size="small"
              />
              <span class="clean-desc">天的内容</span>
            </div>
            <div class="clean-row">
              <el-checkbox value="orphan">孤儿附件</el-checkbox>
              <span class="clean-desc">所属表单已彻底删除且上传超过</span>
              <el-input-number
                v-model="cleanDays.orphan"
                :min="1"
                :max="3650"
                size="small"
              />
              <span class="clean-desc">天的文件</span>
            </div>
          </el-checkbox-group>
          <el-button
            type="danger"
            plain
            :loading="cleaning"
            :disabled="!cleanScopes.length"
            @click="doCleanup"
          >
            立即执行清理
          </el-button>
        </el-tab-pane>

        <!-- 运维与安全：部署 / 日志 -->
        <el-tab-pane label="运维与安全" name="ops">
          <el-divider content-position="left">部署与安全</el-divider>
          <div class="form-tip sec-tip">CDN / 反向代理场景下需选择正确的客户端 IP 头，否则限频与日志记录到的都是节点 IP</div>
          <el-form label-width="150px" label-position="left" class="tab-form">
            <el-form-item label="客户端 IP 获取方式">
              <el-select v-model="sys.client_ip_header" style="width: 260px">
                <el-option
                  label="自动（直连部署，取 REMOTE_ADDR）"
                  value="REMOTE_ADDR"
                />
                <el-option
                  label="X-Real-IP（Nginx 反代常用）"
                  value="X-Real-IP"
                />
                <el-option
                  label="X-Forwarded-For（CDN/WAF 常用）"
                  value="X-Forwarded-For"
                />
              </el-select>
              <div class="form-tip">
                不确定时可先保持自动；套了 CDN 后日志 IP 若全变成节点 IP，改这里
              </div>
            </el-form-item>
            <el-form-item label="站点域名池">
              <el-input
                v-model="sys.site_domains"
                placeholder="多个域名用英文逗号分隔，如 d1.com,d2.com"
                style="max-width: 420px"
              />
              <div class="form-tip">
                绑定到本站的域名都可填入（落地域名/备用域名策略）。
                表单、页面、引流中心的分享链接生成时可指定使用哪个域名。留空则仅使用当前域名。
              </div>
            </el-form-item>
            <el-form-item label="日志保留天数">
              <el-input-number
                :model-value="Number(sys.log_keep_days) || 0"
                :min="0"
                :max="3650"
                :step="30"
                @update:model-value="
                  (v: number) => (sys.log_keep_days = String(v))
                "
              />
              <div class="form-tip">
                0 = 永久保留；每天自动清理一次过期日志（默认 90 天）
              </div>
            </el-form-item>
            <el-form-item label="表单附件总容量">
              <el-input-number
                :model-value="Number(sys.upload_quota_mb) || 0"
                :min="0"
                :max="102400"
                :step="100"
                @update:model-value="
                  (v: number) => (sys.upload_quota_mb = String(v))
                "
              />
              <div class="form-tip">
                单个表单的附件总容量上限（MB），0 为不限，默认 500MB
              </div>
            </el-form-item>
            <el-button type="primary" :loading="saving" @click="save"
              >保存</el-button
            >
          </el-form>

          <el-divider content-position="left">日志调试</el-divider>
          <div class="form-tip sec-tip">开启后记录所有 API 请求/响应报文（脱敏、截断 6KB），仅管理员可查看；日常使用建议关闭</div>
          <el-form label-width="110px" label-position="left" class="tab-form">
            <el-form-item label="记录报文">
              <el-switch
                v-model="sys.log_debug"
                active-value="1"
                inactive-value="0"
              />
              <div class="form-tip">
                开启后可在 日志管理 → 操作日志 中查看每个接口的请求与响应报文
              </div>
            </el-form-item>
            <el-button type="primary" :loading="saving" @click="save"
              >保存</el-button
            >
          </el-form>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<style scoped lang="scss">
.page-head {
  margin-bottom: 14px;

  h2 {
    margin: 0;
    font-size: 18px;
  }
}

.tab-head {
  padding: 6px 0 2px;
  border-bottom: 1px dashed #f0f0f0;
  margin-bottom: 14px;
}

.tab-form {
  max-width: 560px;
  padding-top: 8px;
}

/* 分区说明（分区标题下的一行灰字） */
.sec-tip {
  margin: -4px 0 10px;
}

/* 支付分区小标题 */
.pay-head {
  font-size: 13.5px;
  font-weight: 600;
  margin: 14px 0 10px;
  padding-left: 8px;
  border-left: 3px solid var(--el-color-primary);
}

/* 手动清理：范围行 */
.clean-rows {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-bottom: 14px;
}

.clean-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;

  .clean-desc {
    color: #909399;
    font-size: 12px;
  }
}

.form-tip {
  color: #909399;
  font-size: 12px;
  line-height: 1.6;
}

.logo-row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.logo-preview {
  width: 56px;
  height: 56px;
  border-radius: 8px;
  border: 1px solid #ebeef5;
  background: #f5f7fa;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #909399;
  font-size: 12px;
}

.logo-ops {
  display: flex;
  align-items: center;
  gap: 8px;

  :deep(.el-upload) {
    display: inline-flex;
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
</style>
