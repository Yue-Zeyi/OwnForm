<script setup lang="ts">
/**
 * 自定义页面编辑器
 *
 * 三种编辑模式共享同一份 content 字段：
 *   富文本(1)   — 自建 RichTextEditor（contentEditable），输出 HTML
 *   Markdown(2) — textarea + 实时预览，访客端由后端渲染
 *   HTML(3)     — textarea 源码模式
 *
 * 内容保存后一律由后端统一消毒，前端不拦截（避免前后端规则不一致），
 * 但预览需要就地渲染，因此对预览结果做了同等强度的过滤，
 * 保证编辑期也不会因为历史脏数据而执行脚本。
 */
import {
  ref,
  reactive,
  computed,
  onMounted,
  onBeforeUnmount,
  nextTick
} from "vue";
import { useRoute, useRouter } from "vue-router";
import { message } from "@/utils/message";
import { ElMessageBox } from "element-plus";
import {
  getPage,
  updatePage,
  setPageStatus,
  getPages,
  aiGeneratePage
} from "@/api/pages";
import { getForms } from "@/api/ownform";
import {
  ArrowLeft,
  Plus,
  Search,
  MagicStick,
  Loading
} from "@element-plus/icons-vue";
import RichTextEditor from "./RichTextEditor.vue";

const route = useRoute();
const router = useRouter();
const id = String(route.params.id || "");

const loading = ref(false);
const saving = ref(false);
/** 富文本编辑器组件实例：插入标记时需要拿到其内部 DOM 来定位光标 */
const richEditorRef = ref<InstanceType<typeof RichTextEditor> | null>(null);
/** HTML 源码视图的输入框实例：插入标记时需要光标位置 */
const sourceInputRef = ref();

/**
 * 内容类型 —— 固定为 1（富文本 / HTML）。
 *
 * 页面只有一种编辑器：富文本，外加它的「HTML 源码」辅助视图
 * （两者操作同一份 content，切换不改类型）。
 * 早期版本提供过 Markdown，三种编辑器共享同一份内容导致
 * 「在富文本里写的内容被当 Markdown 渲染」的类型错乱，已移除。
 * contentType 字段保留是为了兼容后端 API 契约。
 */
const CONTENT_HTML = 1;

/** 两个页签：富文本是主编辑界面，源码视图供精细调整 */
const TAB_RICH = 1;
const TAB_SOURCE = 2;

const form = reactive<any>({
  id: 0,
  title: "",
  description: "",
  slug: "",
  contentType: CONTENT_HTML,
  content: "",
  status: 0,
  settings: {
    theme: "#409eff",
    template: "clean",
    footerText: "",
    allowIndex: true,
    accessPassword: "",
    seo: { title: "", description: "", keywords: "" }
  }
});

const tab = ref<number>(TAB_RICH);

/* ---------- 预览（管理端内嵌） ---------- */
const previewVisible = ref(false);

/** 可选的表单与页面，供插入面板使用 */
const formOptions = ref<any[]>([]);
const pageOptions = ref<any[]>([]);
const insertVisible = ref(false);
const insertLoading = ref(false);
/** 插入面板内的搜索词（表单 / 页面各自独立） */
const formKeyword = ref("");
const pageKeyword = ref("");
/** 插入面板当前激活的页签 */
const insertTab = ref("form");

/**
 * 预览用的 HTML
 *
 * 内容是 HTML（与访客端一致直接渲染），额外做两件事：
 *   1. 把内容中的插入标记转成可辨识的占位块，让作者确认插入位置
 *   2. 剔除 script/iframe/on* 事件——编辑期同样不应执行页面内容
 */
/** AI 结果预览：消毒后展示 */
const aiPreviewHtml = computed(() => sanitizePreview(aiResult.value));

const previewHtml = computed(() => {
  let html = String(form.content || "");
  html = html.replace(
    /\{\{\s*(form|page|url)\s*:\s*([^}\s]+)\s*\}\}/gi,
    (_m, type, target) => {
      const label =
        type === "form"
          ? "内嵌表单"
          : type === "page"
            ? "内嵌页面"
            : "外部链接";
      return `<div class="ph-block">[${label}] ${escapeHtml(target)}</div>`;
    }
  );
  return sanitizePreview(html);
});

function escapeHtml(s: string): string {
  return String(s == null ? "" : s).replace(
    /[&<>"']/g,
    c =>
      ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;"
      })[c] as string
  );
}

/**
 * 编辑期预览的安全过滤（编辑期兜底）
 *
 * 后端保存/输出时都会消毒，这里再过滤一次是为了保证
 * 预览不会因为历史脏数据而执行脚本。
 */
function sanitizePreview(html: string): string {
  return String(html)
    .replace(
      /<\s*(script|style|iframe|object|embed|svg|math|form|base|link|meta)\b[^>]*>[\s\S]*?<\s*\/\s*\1\s*>/gi,
      ""
    )
    .replace(
      /<\s*(script|style|iframe|object|embed|svg|math|form|base|link|meta)\b[^>]*>/gi,
      ""
    )
    .replace(/<!--[\s\S]*?-->/g, "")
    .replace(/\son[a-z]+\s*=\s*("[^"]*"|'[^']*'|[^\s>]*)/gi, "")
    .replace(/[\u0000-\u0020]*(&colon;|&#58;|&#x3a;)?/g, "");
}

/* ---------- 编辑器初始化 ---------- */
async function load() {
  loading.value = true;
  try {
    const d = await getPage(id);
    Object.assign(form, {
      id: d.id,
      title: d.title,
      description: d.description,
      slug: d.slug,
      contentType: d.contentType,
      content: d.content || "",
      status: d.status,
      settings: Object.assign(
        {
          theme: "#409eff",
          template: "clean",
          footerText: "",
          allowIndex: true,
          accessPassword: "",
          seo: { title: "", description: "", keywords: "" }
        },
        d.settings || {},
        {
          seo: Object.assign(
            { title: "", description: "", keywords: "" },
            (d.settings || {}).seo || {}
          )
        }
      )
    });
    tab.value = TAB_RICH;
    await nextTick();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  loading.value = false;
}

/**
 * 富文本内容由 RichTextEditor 通过 v-model 直接双向绑定，
 * 每次 input / 工具栏操作都会立即回写 form.content，
 * 因此这里无需再从编辑器取值。
 *
 * 保留这个空方法是为了在「插入组件」「切换页签」「保存」这些
 * 需要「确保内容已同步」的时机表达统一语义，
 * 避免以后有人误以为这些地方需要额外处理。
 */
function syncFromEditor() {
  // 富文本的回抛有 150ms 防抖窗口，保存/预览/插入前必须
  // 直接从编辑器取值，否则最后一次输入可能还没落进 form.content
  if (tab.value === TAB_RICH) {
    form.content = richEditorRef.value?.getHtml() ?? form.content;
  }
}

async function switchTab(next: number) {
  // element-plus 的 tabs 先 emit v-model 再 emit tab-change，
  // 进入本函数时 tab.value 已等于 next——不能用它做早退判断，
  // 必须在改动前自取旧值，否则下面两段保护永远不执行，
  // 快速切页签会丢掉防抖窗口内的最后一次输入。
  const prev = tab.value;
  if (prev === next) return;
  // 离开富文本前把编辑器内容立即落回 model（回抛有 150ms 防抖窗口）
  if (prev === TAB_RICH) {
    form.content = richEditorRef.value?.getHtml() ?? form.content;
  }
  tab.value = next;
  await nextTick();
  // 切回富文本：以 model 为准重建编辑器内容
  //（源码视图里可能改过内容，且隐藏期间编辑器不接收同步）
  if (next === TAB_RICH) {
    richEditorRef.value?.reloadFromModel();
  }
}

/* ---------- 插入组件 ---------- */
/**
 * 打开插入面板
 *
 * 只取「启用中」的表单与「已发布」的页面：
 *   - 表单 status=1 表示收集中，停收的表单访客无法填写，插入无意义
 *   - 页面 status=1 表示已发布，草稿/下线页面访客访问会提示不存在
 * size 取 200 覆盖绝大多数站点的量；超出时用户可用搜索缩小范围。
 */
async function openInsert() {
  insertLoading.value = true;
  try {
    const [fd, pd] = await Promise.all([
      getForms({ page: 1, size: 200, status: 1 }),
      getPages({ page: 1, size: 200, status: 1 })
    ]);
    formOptions.value = fd.list || [];
    // 不排除自身：编辑某个页面时，它往往就是列表里唯一的一条，
    // 排除后面板会显示空列表，用户会误以为功能出错。
    // 自我嵌套由后端兜底（嵌套页面只剥离一层标记，不会无限递归）。
    pageOptions.value = pd.list || [];
    formKeyword.value = "";
    pageKeyword.value = "";
    insertVisible.value = true;
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  insertLoading.value = false;
}

/** 按标题/分享码过滤（标题与分享码都是用户可能记住的信息） */
function matchKeyword(row: any, kw: string): boolean {
  const k = kw.trim().toLowerCase();
  if (!k) return true;
  return (
    String(row.title || "")
      .toLowerCase()
      .includes(k) ||
    String(row.slug || "")
      .toLowerCase()
      .includes(k)
  );
}

const filteredForms = computed(() =>
  formOptions.value.filter((f: any) => matchKeyword(f, formKeyword.value))
);
const filteredPages = computed(() =>
  pageOptions.value.filter((p: any) => matchKeyword(p, pageKeyword.value))
);

const linkUrl = ref("");

function insertForm(row: any) {
  syncFromEditor();
  const token = `{{form:${row.slug}}}`;
  insertToken(token);
  insertVisible.value = false;
  message(`已插入表单「${row.title}」，访客可在页面中直接填写`, {
    type: "success"
  });
}

function insertPage(row: any) {
  syncFromEditor();
  insertToken(`{{page:${row.slug}}}`);
  insertVisible.value = false;
  // 自我嵌套在功能上可用（后端只剥离一层标记，不会无限递归），
  // 但多半不是作者本意，明确提示一下
  if (row.id === form.id) {
    message(
      `已插入本页面的引用。嵌套内容不会再次展开其中的标记，如不需要可手动删除该段`,
      {
        type: "warning"
      }
    );
  }
}

function insertLink() {
  const url = String(linkUrl.value || "").trim();
  // 只允许 http/https，阻断 javascript: 等危险协议
  if (!/^https?:\/\//i.test(url)) {
    return message("请输入以 http:// 或 https:// 开头的链接", {
      type: "warning"
    });
  }
  syncFromEditor();
  insertToken(`{{url:${url}}}`);
  linkUrl.value = "";
  insertVisible.value = false;
}

/**
 * 插入占位标记
 *
 * 富文本模式下需要操作光标位置，否则会把标记追加到文末，
 * 用户还得手动挪到想要的位置。文本模式直接追加即可。
 */
/**
 * 插入占位标记到当前视图
 *
 * 富文本：插到光标所在段落（光标在编辑器外时落到末尾）
 * 源码视图：插到 textarea 光标处，独立成行便于后端识别
 */
function insertToken(token: string) {
  if (tab.value === TAB_SOURCE) {
    const el = sourceInputRef.value?.textarea as
      | HTMLTextAreaElement
      | undefined;
    if (!el) {
      form.content = (form.content || "") + `\n<p>${token}</p>\n`;
      return;
    }
    const start = el.selectionStart ?? el.value.length;
    const end = el.selectionEnd ?? start;
    const value = el.value;
    form.content =
      value.slice(0, start) + `\n<p>${token}</p>\n` + value.slice(end);
    nextTick(() => {
      const pos = start + token.length + 9;
      el.focus();
      el.setSelectionRange(pos, pos);
    });
    return;
  }

  const editor = richEditorRef.value;
  if (!editor || !editor.insertHtml(`<p>${token}</p>`)) {
    // 编辑器未就绪（极少见）：退化为追加，宁可位置不完美也不能丢内容
    form.content = (form.content || "") + `<p>${token}</p>`;
    return;
  }
  // wangEditor 的 onChange 会防抖回抛 form.content，这里同步一次
  // 取值，保证紧随其后的保存读到最新内容
  nextTick(() => {
    form.content = editor.getHtml();
  });
}

/* ---------- 保存 ---------- */
function validate(): boolean {
  syncFromEditor();
  if (!String(form.title || "").trim()) {
    message("请填写页面标题", { type: "warning" });
    return false;
  }
  if (!String(form.content || "").trim()) {
    message("页面内容不能为空", { type: "warning" });
    return false;
  }
  return true;
}

async function save(andPublish?: boolean) {
  if (!validate()) return;
  saving.value = true;
  try {
    await updatePage(form.id, {
      title: form.title,
      description: form.description,
      content: form.content,
      contentType: form.contentType,
      settings: form.settings
    });
    message("已保存", { type: "success" });
    if (andPublish) {
      await setPageStatus(form.id, 1);
      form.status = 1;
      message("页面已发布", { type: "success" });
    }
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  saving.value = false;
}

/* ---------- AI 生成 ---------- */
const aiVisible = ref(false);
const aiLoading = ref(false);
const aiPrompt = ref("");
const aiUseCurrent = ref(true);
const aiResult = ref("");

function openAi() {
  syncFromEditor();
  aiPrompt.value = "";
  aiResult.value = "";
  // 有内容时默认参考上下文；空页面该选项无意义
  aiUseCurrent.value = !!String(form.content || "").trim();
  aiVisible.value = true;
}

/**
 * 调用 AI 生成页面 HTML
 *
 * 生成的结果先在弹窗里预览，由作者决定「插入」还是「替换」，
 * 不直接写入正文 —— 生成质量参差，直接覆盖会误伤已编辑的内容。
 */
async function doAiGenerate() {
  const prompt = String(aiPrompt.value || "").trim();
  if (!prompt) {
    return message("请先描述你想要生成的页面内容", { type: "warning" });
  }
  aiLoading.value = true;
  aiResult.value = "";
  try {
    const d = await aiGeneratePage({
      prompt,
      current: aiUseCurrent.value ? String(form.content || "") : ""
    });
    aiResult.value = d.html || "";
    if (!aiResult.value) {
      message("AI 未返回有效内容", { type: "warning" });
    }
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  aiLoading.value = false;
}

/**
 * 把 AI 结果插入正文
 *
 * 不能走 insertToken：它会把内容包进 <p>（针对 {{form:x}} 单标记的设计），
 * AI 结果是含多个块级元素的完整片段，包 p 会产生非法嵌套。
 * 富文本用编辑器官方 insertHtml；源码视图独立成行直接追加。
 */
function aiInsert() {
  const html = aiResult.value;
  if (!html) return;
  if (tab.value === TAB_SOURCE) {
    form.content = (form.content || "") + "\n" + html + "\n";
  } else if (richEditorRef.value?.insertHtml(html)) {
    nextTick(() => {
      form.content = richEditorRef.value?.getHtml() ?? form.content;
    });
  } else {
    // 编辑器未就绪（极少见）：降级为追加，宁可位置不完美也不丢内容
    form.content = (form.content || "") + html;
  }
  aiVisible.value = false;
  message("已插入到正文", { type: "success" });
}

/** 用 AI 结果整体替换正文（覆盖前二次确认） */
async function aiReplace() {
  try {
    await ElMessageBox.confirm(
      "将用生成结果替换当前全部正文内容，替换前建议先保存现有内容。继续吗？",
      "替换正文",
      { type: "warning", confirmButtonText: "替换", cancelButtonText: "取消" }
    );
  } catch {
    return;
  }
  form.content = aiResult.value;
  aiVisible.value = false;
  message("已替换正文", { type: "success" });
}

function preview() {
  if (!String(form.content || "").trim()) {
    return message("页面内容为空", { type: "warning" });
  }
  syncFromEditor();
  previewVisible.value = true;
}

/** 在新标签页打开访客端真实页面 */
function openLive() {
  previewVisible.value = false;
  window.open("/p/" + form.slug, "_blank", "noopener");
}

/* ---------- 快捷键 Ctrl/Cmd+S ---------- */
function keyHandler(e: KeyboardEvent) {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "s") {
    e.preventDefault();
    if (!saving.value) save(false);
  }
}

onMounted(() => {
  window.addEventListener("keydown", keyHandler);
  load();
});

onBeforeUnmount(() => {
  // 富文本编辑器由组件自身在卸载时清理 contenteditable 与全局监听，
  // 这里只需解绑本页注册的快捷键
  window.removeEventListener("keydown", keyHandler);
});
</script>

<template>
  <div v-loading="loading" class="page-editor">
    <div class="ed-bar">
      <el-button :icon="ArrowLeft" @click="router.push('/pages/index')">
        返回
      </el-button>
      <el-input
        v-model="form.title"
        placeholder="页面标题"
        style="width: 260px"
        maxlength="100"
        show-word-limit
      />
      <el-input
        v-model="form.description"
        placeholder="页面摘要（选填）"
        style="width: 240px"
        maxlength="200"
        show-word-limit
      />
      <div class="ed-actions">
        <el-button type="primary" plain @click="openAi">
          <el-icon><MagicStick /></el-icon>
          AI 生成
        </el-button>
        <el-button @click="openInsert">
          <el-icon><Plus /></el-icon>
          插入表单/页面
        </el-button>
        <el-button @click="preview">预览</el-button>
        <el-button :loading="saving" @click="save(false)">保存</el-button>
        <el-button type="primary" :loading="saving" @click="save(true)">
          保存并发布
        </el-button>
      </div>
    </div>

    <el-tabs
      v-model="tab"
      class="ed-tabs"
      @tab-change="(t: any) => switchTab(t)"
    >
      <el-tab-pane label="富文本" :name="TAB_RICH" />
      <el-tab-pane label="HTML 源码" :name="TAB_SOURCE" />
    </el-tabs>

    <!-- 富文本 -->
    <div v-show="tab === TAB_RICH" class="ed-body ed-rich-body">
      <RichTextEditor
        ref="richEditorRef"
        v-model="form.content"
        :active="tab === TAB_RICH"
        placeholder="在这里编写页面内容，可用上方工具栏设置格式，或点击右上角插入表单 / 页面"
      />
    </div>

    <!-- HTML 源码：富文本的源码视图，两者操作同一份内容 -->
    <div v-show="tab === TAB_SOURCE" class="ed-body ed-src-body">
      <div class="ed-src-tip">
        直接编辑页面 HTML，与富文本视图共享内容。保存时仍会经过安全过滤：
        <code>&lt;script&gt;</code>、<code>on*</code> 事件属性与
        <code>javascript:</code> 协议会被自动移除。
      </div>
      <el-input
        ref="sourceInputRef"
        v-model="form.content"
        type="textarea"
        class="ed-src"
        resize="none"
        spellcheck="false"
        placeholder="直接编写 HTML，例如：<h2>标题</h2><p>段落内容</p>"
      />
    </div>

    <!-- 页面设置 -->
    <el-collapse class="ed-settings">
      <el-collapse-item title="页面设置" name="settings">
        <div class="set-grid">
          <div class="set-item">
            <label>主题色</label>
            <el-color-picker v-model="form.settings.theme" />
          </div>
          <div class="set-item tpl-item">
            <label>展示模板</label>
            <el-radio-group v-model="form.settings.template" size="small">
              <el-radio-button value="clean">纯净</el-radio-button>
              <el-radio-button value="card">卡片</el-radio-button>
              <el-radio-button value="elegant">优雅</el-radio-button>
            </el-radio-group>
            <div class="tpl-tip">
              纯净＝白底平铺最干净；卡片＝主题色渲染底浮卡；优雅＝衬线标题排版
            </div>
          </div>
          <div class="set-item">
            <label>访问密码</label>
            <el-input
              v-model="form.settings.accessPassword"
              type="password"
              show-password
              placeholder="留空表示无需密码"
              style="width: 200px"
            />
          </div>
          <div class="set-item">
            <label>页脚文字</label>
            <el-input
              v-model="form.settings.footerText"
              placeholder="如：© 2026 版权所有"
              style="width: 240px"
            />
          </div>
          <div class="set-item">
            <label>允许搜索引擎收录</label>
            <el-switch v-model="form.settings.allowIndex" />
          </div>
        </div>

        <el-divider content-position="left">SEO 设置</el-divider>
        <div class="set-grid">
          <div class="set-item">
            <label>SEO 标题</label>
            <el-input
              v-model="form.settings.seo.title"
              placeholder="留空使用页面标题"
              style="width: 260px"
            />
          </div>
          <div class="set-item">
            <label>SEO 描述</label>
            <el-input
              v-model="form.settings.seo.description"
              placeholder="搜索结果中的摘要"
              style="width: 260px"
            />
          </div>
          <div class="set-item">
            <label>SEO 关键词</label>
            <el-input
              v-model="form.settings.seo.keywords"
              placeholder="多个词用英文逗号分隔"
              style="width: 260px"
            />
          </div>
        </div>
      </el-collapse-item>
    </el-collapse>

    <!-- AI 生成 -->
    <el-dialog
      v-model="aiVisible"
      title="AI 生成页面内容"
      width="680px"
      top="5vh"
      :close-on-click-modal="!aiLoading"
      :close-on-press-escape="!aiLoading"
    >
      <div class="ai-pane">
        <el-input
          v-model="aiPrompt"
          type="textarea"
          :rows="3"
          maxlength="2000"
          show-word-limit
          placeholder="描述你想要的页面内容，例如：生成一个春季活动介绍页面，包含活动时间、三大亮点、报名方式"
        />
        <div class="ai-options">
          <el-checkbox v-model="aiUseCurrent">参考当前正文内容</el-checkbox>
          <span class="ai-tip"
            >生成的 HTML
            经安全过滤后插入；内嵌表单可在生成后再用「插入表单/页面」添加</span
          >
        </div>

        <div v-if="aiLoading" class="ai-loading">
          <el-icon class="is-loading"><Loading /></el-icon>
          正在生成，通常需要 10-30 秒…
        </div>

        <template v-if="aiResult">
          <el-divider content-position="left">生成结果预览</el-divider>
          <div class="ai-preview">
            <div class="ph-content" v-html="aiPreviewHtml" />
          </div>
          <div class="ai-actions">
            <el-button @click="aiInsert">插入到正文</el-button>
            <el-button type="warning" @click="aiReplace"
              >替换全部正文</el-button
            >
          </div>
        </template>
      </div>
      <template #footer>
        <el-button :disabled="aiLoading" @click="aiVisible = false">
          关闭
        </el-button>
        <el-button
          type="primary"
          :loading="aiLoading"
          :disabled="!aiPrompt.trim()"
          @click="doAiGenerate"
        >
          {{ aiResult ? "重新生成" : "生成" }}
        </el-button>
      </template>
    </el-dialog>

    <!-- 插入组件 -->
    <el-dialog v-model="insertVisible" title="插入表单或页面" width="620px">
      <el-tabs v-model="insertTab">
        <!-- 内嵌表单：只列出处于「收集中」的表单 -->
        <el-tab-pane name="form">
          <template #label>
            <span>内嵌表单</span>
            <el-badge
              v-if="formOptions.length"
              :value="formOptions.length"
              class="ins-badge"
            />
          </template>

          <el-input
            v-model="formKeyword"
            class="ins-search"
            placeholder="搜索表单标题或分享码"
            clearable
          >
            <template #prefix
              ><el-icon><Search /></el-icon
            ></template>
          </el-input>

          <div v-loading="insertLoading" class="ins-list">
            <div
              v-for="f in filteredForms"
              :key="f.id"
              class="ins-item"
              @click="insertForm(f)"
            >
              <div class="ins-title">{{ f.title }}</div>
              <div class="ins-sub">
                分享码 {{ f.slug }} · 已收 {{ f.submit_count }} 份
              </div>
            </div>
            <el-empty
              v-if="!insertLoading && !filteredForms.length"
              :description="
                formKeyword
                  ? '没有匹配的表单'
                  : '还没有处于「收集中」的表单，先去表单管理发布一个'
              "
              :image-size="60"
            />
          </div>
        </el-tab-pane>

        <!-- 内嵌页面：只列出已发布的页面，且排除自身 -->
        <el-tab-pane name="page">
          <template #label>
            <span>内嵌页面</span>
            <el-badge
              v-if="pageOptions.length"
              :value="pageOptions.length"
              class="ins-badge"
            />
          </template>

          <el-input
            v-model="pageKeyword"
            class="ins-search"
            placeholder="搜索页面标题或分享码"
            clearable
          >
            <template #prefix
              ><el-icon><Search /></el-icon
            ></template>
          </el-input>

          <div v-loading="insertLoading" class="ins-list">
            <div
              v-for="p in filteredPages"
              :key="p.id"
              class="ins-item"
              :class="{ 'is-self': p.id === form.id }"
              @click="insertPage(p)"
            >
              <div class="ins-title">
                {{ p.title }}
                <el-tag v-if="p.id === form.id" size="small" type="info">
                  当前页
                </el-tag>
              </div>
              <div class="ins-sub">
                分享码 {{ p.slug }} · 浏览 {{ p.view_count }} 次
              </div>
            </div>
            <el-empty
              v-if="!insertLoading && !filteredPages.length"
              :description="
                pageKeyword
                  ? '没有匹配的页面'
                  : '没有可插入的已发布页面（草稿与已下线页面不展示）'
              "
              :image-size="60"
            />
          </div>
        </el-tab-pane>

        <el-tab-pane name="url" label="外部链接">
          <div class="ins-link">
            <el-input
              v-model="linkUrl"
              placeholder="https://example.com"
              @keyup.enter="insertLink"
            />
            <el-button type="primary" @click="insertLink"
              >插入链接按钮</el-button
            >
          </div>
          <div class="ins-tip">
            仅支持 http / https 链接，访客点击后在新标签页打开
          </div>
        </el-tab-pane>
      </el-tabs>
    </el-dialog>

    <!-- 预览 -->
    <el-dialog
      v-model="previewVisible"
      title="页面预览"
      width="720px"
      top="5vh"
    >
      <div class="pv-wrap">
        <div class="pv-title">{{ form.title }}</div>
        <div v-if="form.description" class="pv-desc">
          {{ form.description }}
        </div>
        <div class="ph-content" v-html="previewHtml" />
        <div v-if="form.settings.footerText" class="pv-foot">
          {{ form.settings.footerText }}
        </div>
      </div>
      <template #footer>
        <el-button @click="previewVisible = false">关闭</el-button>
        <el-button v-if="form.status === 1" type="primary" @click="openLive">
          在新窗口打开真实页面
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.page-editor {
  display: flex;
  flex-direction: column;
  /* 全屏路由下需显式撑满，否则子元素按内容收缩 */
  width: 100%;
  height: calc(100vh - 40px);
  padding: 12px;
  box-sizing: border-box;
}
.ed-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  padding-bottom: 10px;
}
.ed-actions {
  margin-left: auto;
  display: flex;
  gap: 8px;
}
.ed-tabs {
  margin-bottom: 8px;
}
.ed-body {
  flex: 1;
  min-height: 320px;
  overflow: auto;
  background: #fff;
  border: 1px solid #ebeef5;
  border-radius: 8px;
  padding: 12px;
  box-sizing: border-box;
}
/* 富文本容器本身带边框，内部编辑器不要再套一层 */
.ed-rich-body {
  padding: 0;
  display: flex;
  /* overflow 必须 visible：工具栏下拉面板会向下弹出越过本容器边界，
     hidden 会把它裁掉 */
  overflow: visible;
  border-radius: 8px;

  :deep(.rt-editor) {
    flex: 1;
    min-width: 0;
  }
}
/*
 * HTML 源码区
 *
 * Element Plus 的 el-textarea 外层 .el-textarea 是 inline-block，
 * 高度由内容决定，因此内部 textarea 的 height:100% 会解析为「继承父级」，
 * 而父级没有确定高度 —— 结果输入框塌成约 54px，几乎无法编辑。
 * 这里改用 flex 逐层撑满：容器 flex:1 → el-textarea flex:1 → inner 绝对铺满。
 */
.ed-src-body {
  display: flex;
  flex-direction: column;
  padding: 0;
  overflow: hidden;

  .ed-src {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
  }

  .ed-src :deep(.el-textarea) {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
  }

  .ed-src :deep(.el-textarea__inner) {
    flex: 1;
    min-height: 0;
    height: auto;
    border: none;
    padding: 14px 16px;
    font-family: "SF Mono", Menlo, Consolas, monospace;
    font-size: 13px;
    line-height: 1.75;
    resize: none;
  }
}

/* 源码区的安全提示条 */
.ed-src-tip {
  flex-shrink: 0;
  padding: 8px 14px;
  border-bottom: 1px solid #ebeef5;
  background: #fafafa;
  font-size: 12px;
  color: #909399;
  line-height: 1.6;

  code {
    background: #f0f2f5;
    padding: 1px 5px;
    border-radius: 3px;
    color: #c7254e;
  }
}
.ed-settings {
  margin-top: 10px;
}
.set-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 16px 28px;
}
.set-item {
  display: flex;
  align-items: center;
  gap: 8px;
}
.set-item label {
  font-size: 13px;
  color: #606266;
  white-space: nowrap;
}
/* 展示模板独占一行：radio 组不被其他设置项挤压换行 */
.tpl-item {
  flex-basis: 100%;
  flex-wrap: wrap;
}
.tpl-item .el-radio-group {
  flex-shrink: 0;
}
.tpl-tip {
  flex-basis: 100%;
  font-size: 12px;
  color: #909399;
  line-height: 1.6;
}
/* AI 生成弹窗 */
.ai-pane {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.ai-options {
  display: flex;
  align-items: center;
  gap: 12px;
}
.ai-tip {
  font-size: 12px;
  color: #909399;
}
.ai-loading {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 14px;
  background: #f4f8ff;
  border: 1px dashed #409eff;
  border-radius: 8px;
  color: #409eff;
  font-size: 14px;
}
.ai-preview {
  max-height: 320px;
  overflow: auto;
  border: 1px solid #ebeef5;
  border-radius: 8px;
  padding: 14px 18px;
}
.ai-actions {
  display: flex;
  gap: 10px;
}

.ins-list {
  max-height: 340px;
  overflow: auto;
  min-height: 80px;
}
/* 插入面板搜索框 */
.ins-search {
  margin-bottom: 10px;
}
.ins-badge {
  margin-left: 6px;
  vertical-align: top;
}
.ins-tip {
  font-size: 12px;
  color: #909399;
  margin-top: 10px;
}
.ins-item {
  padding: 10px 12px;
  border: 1px solid #ebeef5;
  border-radius: 8px;
  margin-bottom: 8px;
  cursor: pointer;
  transition: border-color 0.2s;
}
.ins-item:hover {
  border-color: #409eff;
  background: #f7faff;
}
/* 当前正在编辑的页面：允许选择，但视觉上区分出来 */
.ins-item.is-self {
  border-color: #dcdfe6;
  background: #fafafa;

  &:hover {
    border-color: #909399;
    background: #f4f4f5;
  }
}
.ins-title {
  font-weight: 600;
  font-size: 14px;
}
.ins-sub {
  font-size: 12px;
  color: #909399;
  margin-top: 3px;
}
.ins-link {
  display: flex;
  gap: 10px;
  padding: 8px 0;
}
.pv-wrap {
  max-height: 70vh;
  overflow: auto;
  padding: 4px 8px;
}
.pv-title {
  font-size: 22px;
  font-weight: 700;
  margin-bottom: 6px;
}
.pv-desc {
  color: #606266;
  margin-bottom: 16px;
  white-space: pre-wrap;
}
.pv-foot {
  margin-top: 24px;
  text-align: center;
  color: #909399;
  font-size: 13px;
}
</style>

<style>
/* 内容预览区（非 scoped：内容由 v-html 渲染，需要全局样式） */
.ph-content h1 {
  font-size: 24px;
  margin: 22px 0 12px;
}
.ph-content h2 {
  font-size: 20px;
  margin: 20px 0 10px;
}
.ph-content h3 {
  font-size: 17px;
  margin: 16px 0 8px;
}
.ph-content p {
  margin: 12px 0;
  line-height: 1.75;
}
.ph-content ul,
.ph-content ol {
  padding-left: 24px;
}
.ph-content img {
  max-width: 100%;
  border-radius: 6px;
}
.ph-content blockquote {
  margin: 12px 0;
  padding: 10px 14px;
  border-left: 3px solid #409eff;
  background: #f7f8fa;
  color: #606266;
}
.ph-content code {
  background: #f0f2f5;
  padding: 2px 5px;
  border-radius: 4px;
  color: #c7254e;
}
.ph-content table {
  border-collapse: collapse;
}
.ph-content th,
.ph-content td {
  border: 1px solid #ebeef5;
  padding: 6px 10px;
}
.ph-content hr {
  border: none;
  border-top: 1px solid #ebeef5;
  margin: 20px 0;
}
.ph-block {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 16px;
  margin: 14px 0;
  border: 1px dashed #409eff;
  border-radius: 8px;
  background: #f4f8ff;
  color: #409eff;
  font-size: 13px;
}
</style>
