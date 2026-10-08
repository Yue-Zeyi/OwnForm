<script setup lang="ts">
import { ref, reactive, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import { message } from "@/utils/message";
import { ElMessageBox } from "element-plus";
import {
  getForm,
  getSubmission,
  markSubmission,
  reviewSubmissions,
  deleteSubmission
} from "@/api/ownform";
import { ArrowLeft, Edit, DataAnalysis, Delete } from "@element-plus/icons-vue";
import { FLAG_COLORS, flagSvgHtml } from "@/utils/formRule";

defineOptions({
  name: "SubmissionDetail"
});

const route = useRoute();
const router = useRouter();
const formId = route.params.formId as string;
const id = route.params.id as string;

const loading = ref(false);
const form = ref<any>({});
const fields = ref<any[]>([]);
const row = ref<any>(null);
// 编辑权：仅创建者/管理员可标记/审核/删除（与数据列表口径一致）
const canEdit = ref(true);

const remarkVisible = ref(false);
const remarkText = ref("");
const saving = ref(false);

const fmtTime = (s: string) =>
  s ? String(s).replace("T", " ").slice(0, 19) : "-";
const isImage = (u: string) => /\.(png|jpe?g|gif|webp|bmp)$/i.test(u);

/**
 * 打开非图片附件
 *
 * 必须包一层函数：模板里直接写 window.open 会被编译成 _ctx.window.open，
 * 而 window 不在 Vue 的 globalProperties 白名单里，运行期为 undefined，
 * 点击即抛 TypeError。
 */
function openFile(u: string) {
  if (!u) return;
  window.open(u, "_blank", "noopener,noreferrer");
}

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

/** 上传字段值归一化：兼容 limit=1 时 form-create 提交的单字符串形态 */
function uploadUrlsOf(v: any): string[] {
  const arr = Array.isArray(v)
    ? v
    : typeof v === "string" && v
      ? [v]
      : [];
  return arr.filter(
    (u: any) =>
      typeof u === "string" &&
      (u.startsWith("/storage/") || /^https?:/.test(u))
  );
}

async function load() {
  loading.value = true;
  try {
    const f = await getForm(formId);
    form.value = f;
    fields.value = f.fields || [];
    canEdit.value = !f.auth || !!f.auth.canEdit;
    row.value = await getSubmission(formId, id);
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  loading.value = false;
}

function flagColor(flag: number) {
  return (FLAG_COLORS[flag] || FLAG_COLORS[0]).color;
}
function flagTitle(flag: number) {
  return (FLAG_COLORS[flag] || FLAG_COLORS[0]).name;
}

async function setFlag(flag: number) {
  try {
    const d = await markSubmission(formId, id, { flag });
    row.value.flag = d.flag;
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

function openRemark() {
  remarkText.value = row.value.remark || "";
  remarkVisible.value = true;
}

async function saveRemark() {
  saving.value = true;
  try {
    const d = await markSubmission(formId, id, { remark: remarkText.value });
    row.value.remark = d.remark;
    message("备注已保存", { type: "success" });
    remarkVisible.value = false;
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  saving.value = false;
}

async function review(status: number) {
  const tip =
    status === 1 ? "通过该条提交？" : "隐藏该条提交？隐藏后不计入统计。";
  const ok = await ElMessageBox.confirm(tip, "审核", {
    confirmButtonText: "确定",
    cancelButtonText: "取消",
    type: "warning"
  })
    .then(() => true)
    .catch(() => false);
  if (!ok) return;
  try {
    await reviewSubmissions(formId, [Number(id)], status);
    row.value.status = status;
    message(status === 1 ? "已通过" : "已隐藏", { type: "success" });
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

async function del() {
  const ok = await ElMessageBox.confirm(
    "确定删除这条提交数据吗？删除后不可恢复。",
    "提示",
    { confirmButtonText: "确定", cancelButtonText: "取消", type: "warning" }
  )
    .then(() => true)
    .catch(() => false);
  if (!ok) return;
  try {
    await deleteSubmission(formId, id);
    message("已删除", { type: "success" });
    router.push("/forms/data/" + formId);
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

onMounted(async () => {
  await load();
});
</script>

<template>
  <div v-loading="loading" class="detail-page">
    <div class="page-head">
      <div>
        <el-page-header @back="router.push('/forms/data/' + formId)">
          <template #content>
            <span class="head-title">
              提交详情 #{{ id }}
              <el-tag
                v-if="row && row.status === 0"
                size="small"
                type="warning"
                style="margin-left: 8px"
              >
                待审核
              </el-tag>
              <el-tag
                v-else-if="row && row.status === 1"
                size="small"
                type="success"
                style="margin-left: 8px"
              >
                已通过
              </el-tag>
              <el-tag
                v-if="row && row.oldVersion"
                size="small"
                type="info"
                effect="plain"
                style="margin-left: 8px"
              >
                旧版表单
              </el-tag>
            </span>
          </template>
        </el-page-header>
        <div class="page-sub">{{ form.title }}</div>
      </div>
      <div v-if="row && canEdit" class="head-ops">
        <el-popover
          placement="bottom"
          :width="260"
          trigger="click"
          popper-class="flag-popper"
        >
          <template #reference>
            <el-button
              :type="row.flag > 0 ? 'warning' : 'default'"
              :plain="row.flag === 0"
            >
              <span
                style="display: inline-flex; margin-right: 6px"
                v-html="flagSvgHtml(flagColor(row.flag), row.flag > 0)"
              />
              {{ row.flag > 0 ? flagTitle(row.flag) : "设置旗标" }}
            </el-button>
          </template>
          <div class="flag-picker-title">设置旗标</div>
          <div class="flag-colors">
            <span
              v-for="(c, k) in FLAG_COLORS"
              :key="k"
              class="flag-color-item"
              :class="{ active: row.flag === Number(k) }"
              :title="c.name"
              @click="setFlag(Number(k))"
              v-html="flagSvgHtml(c.color, Number(k) > 0)"
            />
          </div>
        </el-popover>
        <el-button :icon="Edit" @click="openRemark">备注</el-button>
        <el-button v-if="row.status !== 1" type="success" @click="review(1)">
          审核通过
        </el-button>
        <el-button v-else type="warning" plain @click="review(0)"
          >隐藏</el-button
        >
        <el-button type="danger" plain :icon="Delete" @click="del"
          >删除</el-button
        >
      </div>
    </div>

    <el-row v-if="row" :gutter="16">
      <el-col :xs="24" :md="16">
        <el-card shadow="never">
          <template #header>提交内容</template>
          <div v-for="f in fields" :key="f.field" class="field-item">
            <div class="field-label">
              {{ f.title || f.field }}
            </div>
            <div class="field-value">
              <template
                v-if="uploadUrlsOf(row.data[f.field]).length"
              >
                <div class="img-list">
                  <el-image
                    v-for="(u, i) in uploadUrlsOf(row.data[f.field])"
                    :key="i"
                    :src="isImage(u) ? u : ''"
                    :preview-src-list="isImage(u) ? [u] : []"
                    fit="cover"
                    class="field-img"
                    preview-teleported
                    @click="!isImage(u) && openFile(u)"
                  >
                    <template #error>
                      <el-link type="primary" :href="u" target="_blank">
                        下载附件
                      </el-link>
                    </template>
                  </el-image>
                </div>
              </template>
              <template v-else>
                {{ displayValue(row.data[f.field]) || "—" }}
              </template>
            </div>
          </div>
        </el-card>

        <el-card v-if="row.remark" shadow="never" class="mt-4">
          <template #header>
            <div class="card-head">
              <span>备注</span>
              <el-button
                v-if="canEdit"
                link
                type="primary"
                :icon="Edit"
                @click="openRemark"
              >
                编辑
              </el-button>
            </div>
          </template>
          <div class="remark-text">{{ row.remark }}</div>
        </el-card>
      </el-col>

      <el-col :xs="24" :md="8">
        <el-card shadow="never">
          <template #header>提交信息</template>
          <div class="sum-line">
            <span>提交 ID</span><b>#{{ row.id }}</b>
          </div>
          <div class="sum-line">
            <span>提交时间</span><b>{{ fmtTime(row.createdAt) }}</b>
          </div>
          <div class="sum-line">
            <span>设备</span>
            <b>{{
              row.device === "mobile"
                ? "手机"
                : row.device === "pc"
                  ? "电脑"
                  : "未知"
            }}</b>
          </div>
          <div class="sum-line">
            <span>渠道</span>
            <b>{{ row.channelName || "直接访问" }}</b>
          </div>
          <div class="sum-line">
            <span>IP</span><b>{{ row.ip || "—" }}</b>
          </div>
          <div class="sum-line">
            <span>旗标</span>
            <b
              :style="{
                color: row.flag > 0 ? flagColor(row.flag) : '#909399'
              }"
            >
              {{ row.flag > 0 ? flagTitle(row.flag) : "无" }}
            </b>
          </div>
          <div class="sum-line">
            <span>审核状态</span>
            <b>{{
              row.status === 1
                ? "已通过"
                : row.status === 0
                  ? "待审核"
                  : "已隐藏"
            }}</b>
          </div>
          <el-button
            class="mt-4"
            style="width: 100%"
            :icon="DataAnalysis"
            @click="router.push('/forms/stats/' + formId)"
          >
            查看该表单统计
          </el-button>
        </el-card>
      </el-col>
    </el-row>

    <!-- 备注弹窗 -->
    <el-dialog v-model="remarkVisible" title="提交备注" width="480px">
      <el-input
        v-model="remarkText"
        type="textarea"
        :rows="4"
        maxlength="500"
        show-word-limit
        placeholder="备注内容（500 字以内）"
      />
      <template #footer>
        <el-button @click="remarkVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveRemark">
          保存
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 14px;
  gap: 12px;
  flex-wrap: wrap;

  .head-title {
    font-size: 16px;
    font-weight: 600;
  }
}

.page-sub {
  color: #909399;
  font-size: 13px;
  margin-top: 4px;
  padding-left: 10px;
}

.head-ops {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.field-item {
  padding: 12px 0;
  border-bottom: 1px dashed #f0f0f0;

  &:last-child {
    border-bottom: none;
  }

  .field-label {
    color: #909399;
    font-size: 12px;
    margin-bottom: 8px;
  }

  .field-value {
    font-size: 14px;
    line-height: 1.7;
    word-break: break-all;
    white-space: pre-wrap;
  }

  .img-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .field-img {
    width: 96px;
    height: 96px;
    border-radius: 8px;
    border: 1px solid #ebeef5;
    display: flex;
    align-items: center;
    justify-content: center;
  }
}

.card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.remark-text {
  font-size: 14px;
  line-height: 1.8;
  color: var(--el-color-warning-dark-2, #b88230);
  white-space: pre-wrap;
}

.sum-line {
  display: flex;
  justify-content: space-between;
  padding: 10px 4px;
  border-bottom: 1px dashed #f0f0f0;
  font-size: 14px;

  &:last-of-type {
    border-bottom: none;
  }
}
</style>

<style lang="scss">
.flag-popper {
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

    &:hover {
      background: #f0f2f5;
    }

    &.active {
      border-color: var(--el-color-primary);
      background: #ecf5ff;
    }
  }
}
</style>
