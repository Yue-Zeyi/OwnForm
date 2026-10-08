<script setup lang="ts">
/**
 * 富文本编辑器（wangEditor v5）
 *
 * 用 @wangeditor/editor v5 + @wangeditor/editor-for-vue v5：
 * v5 与旧版 4.x 不同，CSS 随包发布（dist/css/style.css），
 * 组件化接入、工具栏与编辑区分离布局、上传可定制。
 *
 * 对外只暴露 modelValue 与 getEditorEl()，
 * 「插入表单/页面」的占位标记由父组件通过光标定位写入。
 *
 * 安全说明：粘贴与输出均不信任外部 HTML ——
 * 编辑内容保存时由后端 HtmlSanitizer 统一消毒，
 * 前端预览亦有同等过滤，不存在存储型 XSS。
 */
import { ref, shallowRef, onBeforeUnmount, watch } from "vue";
import { Editor, Toolbar } from "@wangeditor/editor-for-vue";
import type {
  IDomEditor,
  IEditorConfig,
  IToolbarConfig
} from "@wangeditor/editor";
import "@wangeditor/editor/dist/css/style.css";
import { message } from "@/utils/message";

const props = defineProps<{
  modelValue: string;
  placeholder?: string;
  /** 编辑器所在页签是否处于激活状态：非激活时跳过外部内容同步（防回写环） */
  active?: boolean;
}>();
const emit = defineEmits<{ (e: "update:modelValue", v: string): void }>();

const editorRef = shallowRef<IDomEditor>();
const html = ref(props.modelValue || "");

const toolbarConfig: Partial<IToolbarConfig> = {
  /* 排除用不到或后端不保留的能力，避免「编辑器有、发布后被过滤」的落差 */
  excludeKeys: ["group-video", "fullScreen"]
};

const editorConfig: Partial<IEditorConfig> = {
  placeholder: props.placeholder || "请输入页面内容…",
  MENU_CONF: {
    // 图片走本地上传接口，与表单附件同一套白名单/配额/存储
    uploadImage: {
      server: "/api/upload",
      fieldName: "file",
      maxFileSize: 5 * 1024 * 1024,
      // 默认 10s 超时对 5MB 图片弱网必失败
      timeout: 60 * 1000,
      // 后端返回 { code, msg, data: { url } }，转换成 wangEditor 需要的格式；
      // 失败必须提示——静默 return 会让用户以为「点了没反应」
      customInsert(
        res: any,
        insertFn: (url: string, alt: string, href: string) => void
      ) {
        if (res && res.code === 0 && res.data && res.data.url) {
          insertFn(res.data.url, res.data.name || "", res.data.url);
        } else {
          message((res && res.msg) || "图片上传失败", { type: "error" });
        }
      },
      onError(_editor: unknown, _file: unknown, err: unknown) {
        message("图片上传失败，请检查网络后重试", { type: "error" });
        console.error("[upload]", err);
      }
    }
  }
};

const handleCreated = (editor: IDomEditor) => {
  editorRef.value = editor;
};

/**
 * 外部内容变化时同步进编辑器
 *
 * 只在组件激活（所在页签可见）时同步：「HTML 源码」视图与富文本
 * 编辑器共用同一份 form.content，若隐藏时也双向同步，用户在源码里
 * 每敲一键都会触发 setHtml → onChange → 回抛规范化 HTML 的循环，
 * 造成字面输入被改写、未闭合标签被丢弃、光标跳位。
 * 切回富文本页签时由父组件 switchTab 显式调用 reloadFromModel()。
 */
watch(
  [() => props.modelValue, () => props.active],
  ([v]) => {
    if (!props.active) return;
    const editor = editorRef.value;
    if (!editor) return;
    if (v === editor.getHtml()) return;
    editor.setHtml(v || "");
  },
  { immediate: true }
);

/** 供父组件在切回本页签时强制从 model 重建内容 */
function reloadFromModel() {
  const editor = editorRef.value;
  if (!editor) return;
  if (props.modelValue === editor.getHtml()) return;
  editor.setHtml(props.modelValue || "");
}

/* 防抖回抛：输入高频触发，直接 emit 会让父组件频繁重算 */
let emitTimer: ReturnType<typeof setTimeout> | null = null;

const handleChange = (editor: IDomEditor) => {
  if (emitTimer) clearTimeout(emitTimer);
  emitTimer = setTimeout(() => {
    const h = editor.isEmpty() ? "" : editor.getHtml();
    emit("update:modelValue", h);
  }, 150);
};

/**
 * 把 HTML 片段插入正文（光标处）
 *
 * 必须用 wangEditor 的官方 API（dangerouslyInsertHtml）：
 * v5 基于 Slate 受控状态，直接 execCommand 改 DOM 会让编辑器
 * 内部状态与真实 DOM 脱节——实测会把 placeholder 层写入内容、
 * 产生重复与空段落。官方 API 由 Slate 自己维护插入语义。
 */
function insertHtml(fragment: string) {
  const editor = editorRef.value;
  if (!editor) return false;
  editor.focus();
  editor.dangerouslyInsertHtml(fragment);
  return true;
}

/** 当前编辑器内容（源码视图与保存前取值用） */
function getHtml(): string {
  const editor = editorRef.value;
  if (!editor) return "";
  return editor.isEmpty() ? "" : editor.getHtml();
}

defineExpose({ insertHtml, getHtml, reloadFromModel });

onBeforeUnmount(() => {
  if (emitTimer) clearTimeout(emitTimer);
  const editor = editorRef.value;
  if (editor) editor.destroy();
});
</script>

<template>
  <div class="rt-editor">
    <Toolbar
      class="rt-toolbar"
      :editor="editorRef"
      :default-config="toolbarConfig"
      mode="default"
    />
    <Editor
      v-model="html"
      class="rt-content"
      :default-config="editorConfig"
      mode="default"
      @on-created="handleCreated"
      @on-change="handleChange"
    />
  </div>
</template>

<style scoped lang="scss">
.rt-editor {
  border: 1px solid #dcdfe6;
  border-radius: 6px;
  /* 不能用 overflow:hidden：工具栏的字号/颜色等下拉面板是 absolute
     定位在工具栏项内向下弹出的，hidden 会把它们裁掉（表现为点开后
     只剩一条竖缝）。圆角由子区域自身的圆角保证。 */
  overflow: visible;
  background: #fff;
  display: flex;
  flex-direction: column;
  width: 100%;
  height: 100%;

  &:focus-within {
    border-color: #409eff;
  }
}

/* 工具栏与内容区的分隔贴合后台视觉，去掉 wangEditor 默认的双边框 */
.rt-toolbar {
  border-bottom: 1px solid #ebeef5;
  flex-shrink: 0;
  /* 不能加 overflow 滚动：下拉面板从工具栏项内向下弹出，加了就裁。
     窄屏时依赖 wangEditor 自带的 flex-wrap:wrap 自动换行。 */
}

.rt-content {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

/* 正文排版基准 —— 与访客端 .pg-content 保持一致（所见即所得） */
.rt-content :deep(h1) {
  font-size: 24px;
  margin: 24px 0 12px;
  font-weight: 700;
}
.rt-content :deep(h2) {
  font-size: 20px;
  margin: 22px 0 10px;
  font-weight: 650;
}
.rt-content :deep(h3) {
  font-size: 17px;
  margin: 18px 0 8px;
  font-weight: 650;
}
.rt-content :deep(h4),
.rt-content :deep(h5),
.rt-content :deep(h6) {
  font-size: 15px;
  margin: 16px 0 8px;
  font-weight: 650;
}
.rt-content :deep(p) {
  margin: 12px 0;
}
.rt-content :deep(ul),
.rt-content :deep(ol) {
  padding-left: 26px;
  margin: 12px 0;
}
.rt-content :deep(blockquote) {
  margin: 12px 0;
  padding: 10px 14px;
  border-left: 3px solid #409eff;
  background: #f7f8fa;
  color: #606266;
}
.rt-content :deep(img) {
  max-width: 100%;
  border-radius: 8px;
  display: block;
  margin: 12px 0;
}
.rt-content :deep(a) {
  color: #409eff;
  text-decoration: underline;
}
.rt-content :deep(hr) {
  border: none;
  border-top: 1px solid #dcdfe6;
  margin: 16px 0;
}
.rt-content :deep(table) {
  border-collapse: collapse;
}
.rt-content :deep(th),
.rt-content :deep(td) {
  border: 1px solid #dcdfe6;
  padding: 6px 10px;
}
.rt-content :deep(pre) {
  background: #f7f8fa;
  border: 1px solid #ebeef5;
  border-radius: 6px;
  padding: 12px 14px;
}
</style>
