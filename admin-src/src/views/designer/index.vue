<script setup lang="ts">
import {
  ref,
  reactive,
  nextTick,
  onMounted,
  onBeforeUnmount,
  onErrorCaptured,
  watch
} from "vue";
import { useRoute, useRouter } from "vue-router";
import { message } from "@/utils/message";
import { ElMessageBox } from "element-plus";
import { getForm, createForm, updateForm, setFormStatus } from "@/api/ownform";
import { getPages } from "@/api/pages";
import {
  patchRule,
  preloadRegionData,
  regionData,
  splitBySubmit
} from "@/utils/formRule";
import { brand } from "@/utils/brand";
import {
  ArrowLeft,
  Tickets,
  View,
  Setting,
  Document,
  Check,
  Promotion,
  PriceTag,
  QuestionFilled
} from "@element-plus/icons-vue";
import * as ElementPlusIcons from "@element-plus/icons-vue";
import FcDesigner from "@form-create/designer";

defineOptions({
  name: "FormDesigner"
});

const route = useRoute();
const router = useRouter();
const designer = ref<any>();
const loading = ref(false);
const saving = ref(false);
const form = reactive<any>({
  id: 0,
  title: "",
  description: "",
  status: 0,
  slug: "",
  fields: [],
  settings: {}
});
const settingsVisible = ref(false);
const settings = ref<any>({});
const previewVisible = ref(false);
const previewApi = ref<any>(null);
const previewRule = ref<any[]>([]);
const previewTailApi = ref<any>(null);
const previewTailRule = ref<any[]>([]);
const previewOption = ref<any>({});
const previewEmpty = ref(false);
const previewKey = ref(0);

const formReady = ref(false);

const designerConfig = {
  showSaveBtn: false,
  fieldReadonly: false,
  // AI 助理：接口走后端代理，Token 由服务端持有，
  // 不再把 Token 下发到浏览器（否则任何页面脚本与 XSS 都能窃取）
  ai: {
    api: brand.aiApi
  }
};

/* 设置视图模型 */
function normalizeSettingsView(s: any) {
  return {
    submitText: s.submitText || "提 交",
    successText: s.successText || "提交成功，感谢您的填写！",
    theme: s.theme || "#409eff",
    accessPassword: s.accessPassword || "",
    endTime: s.endTime || "",
    maxCount: s.maxCount || 0,
    limitOnce: !!s.limitOnce,
    needReview: !!s.needReview,
    captcha: s.captcha || "none",
    smsPhoneField: s.smsPhoneField || "",
    service: Object.assign(
      { type: "none", link: "", qrcode: "" },
      s.service || {}
    ),
    afterSubmit: Object.assign(
      { type: "none", target: "", delay: 3 },
      s.afterSubmit || {}
    ),
    links: Array.isArray(s.links) ? s.links.map((l: any) => ({ ...l })) : [],
    formOption: s.formOption || {},
    payConfig: Object.assign(
      { enabled: false, mode: "fixed", amount: 0, optionField: "", optionPrices: {}, successText: "支付成功，感谢您的支持！" },
      s.payConfig || {}
    )
  };
}

/** 已发布页面列表（用于「提交后跳转」与关联入口的选择） */
const pageOptions = ref<any[]>([]);

async function loadPageOptions() {
  try {
    const d = await getPages({ page: 1, size: 100, status: 1 });
    pageOptions.value = d.list || [];
  } catch (e) {
    // 未登录或无权限时静默降级：该两项配置仍可手填分享码
    pageOptions.value = [];
  }
}

/* 收集设计器规则 + 选项 */
function collect() {
  const d = designer.value;
  if (!d) throw new Error("设计器尚未就绪");
  return {
    fields: JSON.stringify(d.getRule() || []),
    formOption: d.getOption() || {}
  };
}

/* 从设计器规则中收集文本字段（短信验证手机号候选） */
const phoneFields = ref<{ field: string; title: string }[]>([]);
/** 可定价字段（单选框/下拉，选项来自字段配置） */
const priceFields = ref<{ field: string; title: string; options: { label: string; value: string }[] }[]>([]);

/** 选项定价行：与 settings.payConfig.optionPrices（{选项: 价格}）双向同步 */
const payPriceRows = ref<{ label: string; price: number; fromField: boolean }[]>([]);
watch(
  () => settings.value.payConfig?.optionPrices,
  (prices) => {
    if (!settings.value.payConfig) return;
    const map = (prices as Record<string, number>) || {};
    const cur = payPriceRows.value;
    // 仅当外部整体替换（打开设置/切换字段）时重建，避免输入时抖动
    const keys = Object.keys(map);
    if (
      cur.length !== keys.length ||
      cur.some((r, i) => !keys[i] || keys[i] !== r.label)
    ) {
      payPriceRows.value = keys.map((k) => ({
        label: k,
        price: Number(map[k]) || 0,
        fromField: false
      }));
    }
  },
  { immediate: true }
);
watch(payPriceRows, (rows) => {
  if (!settings.value.payConfig) return;
  const map: Record<string, number> = {};
  rows.forEach((r) => {
    if (r.label.trim() !== "" && r.price > 0) map[r.label.trim()] = r.price;
  });
  settings.value.payConfig.optionPrices = map;
}, { deep: true });

/** 选定定价字段后，用该字段的选项预填价格行（价格留 0 待填） */
function onPayFieldChange(field: string) {
  const f = priceFields.value.find((x) => x.field === field);
  const keep = settings.value.payConfig?.optionPrices || {};
  const rows: { label: string; price: number; fromField: boolean }[] = [];
  (f?.options || []).forEach((o) => {
    rows.push({
      label: o.label || o.value,
      price: Number(keep[o.label || o.value]) || 0,
      fromField: true
    });
  });
  payPriceRows.value = rows;
}
function addPayPriceRow() {
  payPriceRows.value.push({ label: "", price: 0, fromField: false });
}

function collectPhoneFields() {
  const out: { field: string; title: string }[] = [];
  priceFields.value = [];
  const seen: Record<string, number> = {};
  const pseen: Record<string, number> = {};
  let rule: any[] = [];
  try {
    rule = designer.value ? designer.value.getRule() : form.fields || [];
  } catch (e) {
    rule = form.fields || [];
  }
  const walk = (nodes: any[]) => {
    (nodes || []).forEach((n: any) => {
      if (!n || typeof n !== "object") return;
      if (
        n.field &&
        !seen[n.field] &&
        (n.type === "input" || n.type === "textarea")
      ) {
        seen[n.field] = 1;
        out.push({
          field: n.field,
          title: (typeof n.title === "string" ? n.title : n.field) || n.field
        });
      }
      // 收集单选/下拉字段及其选项（供选项定价使用）
      if (
        n.field &&
        !pseen[n.field] &&
        (n.type === "radio" || n.type === "select")
      ) {
        pseen[n.field] = 1;
        const opts: { label: string; value: string }[] = [];
        const po =
          n.props && (n.props.options || (n.props.formCreateChild || []
            ).length)
            ? n.props.options
            : null;
        const list = Array.isArray(po)
          ? po
          : Array.isArray(n.children)
            ? n.children
            : [];
        list.forEach((o: any) => {
          if (!o) return;
          if (o.type === "option" || (o.props && "value" in (o.props || {}))) {
            const lbl =
              (typeof o.title === "object" ? o.title?.() : o.title) ||
              (typeof o.props?.formCreateChild === "function"
                ? o.props.formCreateChild()
                : o.props?.formCreateChild);
            opts.push({
              label: String(lbl || o.value || ""),
              value: String(o.value ?? "")
            });
          } else if (typeof o === "object" && ("label" in o || "value" in o)) {
            opts.push({
              label: String(o.label ?? o.value ?? ""),
              value: String(o.value ?? "")
            });
          }
        });
        priceFields.value.push({
          field: n.field,
          title: (typeof n.title === "string" ? n.title : n.field) || n.field,
          options: opts
        });
      }
      if (Array.isArray(n.children)) walk(n.children);
      if (n.props && Array.isArray(n.props.rule)) walk(n.props.rule);
    });
  };
  walk(rule);
  phoneFields.value = out;
}

/* ---------- 字段图标 ----------
 * 图标名存放在规则的 __icon 上（JSON 可序列化，随字段定义一起保存），
 * 填写页/页面内嵌/预览通过 form-create 的 title 插槽渲染成 Element Plus 图标，
 * 字段标题保持纯文本，导出表头与提交通知不受图标影响。 */

/** 可选图标（分组，均为 @element-plus/icons-vue 的组件名） */
const ICON_GROUPS: { name: string; items: string[] }[] = [
  {
    name: "联系/人物",
    items: [
      "User",
      "UserFilled",
      "Avatar",
      "Iphone",
      "Phone",
      "Message",
      "ChatDotRound",
      "Bell",
      "Position",
      "Postcard"
    ]
  },
  {
    name: "地址/物流",
    items: [
      "Location",
      "HomeFilled",
      "House",
      "OfficeBuilding",
      "School",
      "Guide",
      "Van",
      "Box",
      "ShoppingCart",
      "Goods"
    ]
  },
  {
    name: "证件/财务",
    items: [
      "Tickets",
      "Document",
      "Wallet",
      "CreditCard",
      "Money",
      "Coin",
      "Camera",
      "CameraFilled",
      "Picture",
      "Collection"
    ]
  },
  {
    name: "时间/通用",
    items: [
      "Calendar",
      "Clock",
      "AlarmClock",
      "Star",
      "EditPen",
      "Edit",
      "List",
      "Notebook",
      "Files",
      "Link"
    ]
  }
];

/** 按图标名取组件（供动态渲染） */
function iconComp(name: string) {
  return (ElementPlusIcons as any)[name] || null;
}

/** 兼容迁移：剥离历史版本写在标题开头的 emoji 图标 */
const ICON_STRIP_RE =
  /^(?:\p{Extended_Pictographic}[\uFE0F\uFE0E]?(?:\u200D\p{Extended_Pictographic}[\uFE0F\uFE0E]?)?(?:[\u{1F3FB}-\u{1F3FF}])?(?:\u20E3)?[\s\u00A0]*)+/u;

function stripLegacyEmoji(title: string): string {
  return String(title ?? "").replace(ICON_STRIP_RE, "");
}

interface IconFieldRow {
  node: any;
  title: string;
  icon: string;
  field: string;
  type: string;
}

const iconVisible = ref(false);
const helpVisible = ref(false);
const iconRows = ref<IconFieldRow[]>([]);
/** 图标编辑在规则克隆上进行，完成时经 setRule 写回设计器 */
let iconSource: any[] = [];
let iconDirty = false;

function collectIconFields() {
  let rule: any[] = [];
  try {
    // getRule() 返回的是序列化副本，直接改不影响设计器；
    // 因此整树克隆后编辑，完成时 setRule 写回
    rule = JSON.parse(
      JSON.stringify(designer.value ? designer.value.getRule() : [])
    );
  } catch (e) {
    rule = [];
  }
  iconSource = rule;
  iconDirty = false;
  const rows: IconFieldRow[] = [];
  const walk = (nodes: any[]) => {
    (nodes || []).forEach((n: any) => {
      if (!n || typeof n !== "object") return;
      if (n.field && typeof n.title === "string") {
        rows.push({
          node: n,
          title: n.title,
          icon: String((n.props && n.props.__icon) || n.__icon || ""),
          field: n.field,
          type: String(n.type || "")
        });
        return; // 叶子字段不再下钻
      }
      if (Array.isArray(n.children)) walk(n.children);
      if (n.props && Array.isArray(n.props.rule)) walk(n.props.rule);
    });
  };
  walk(rule);
  iconRows.value = rows;
}

function openIcons() {
  collectIconFields();
  iconVisible.value = true;
}

function applyIcon(row: IconFieldRow, name: string) {
  // 必须放 props 里：设计器保存时会剥离顶层自定义键，props 才能完整保留
  row.node.props = row.node.props || {};
  row.node.props.__icon = name;
  // 迁移：清掉历史版本写在标题里的 emoji 前缀
  const clean = stripLegacyEmoji(String(row.node.title ?? ""));
  if (clean !== row.node.title) row.node.title = clean;
  row.title = clean;
  row.icon = name;
  iconDirty = true;
}

function clearIcon(row: IconFieldRow) {
  if (row.node.props) delete row.node.props.__icon;
  delete row.node.__icon;
  row.icon = "";
  iconDirty = true;
}

/** 完成：把带图标的规则写回设计器画布 */
function commitIcons() {
  iconVisible.value = false;
  if (!iconDirty || !designer.value) return;
  try {
    designer.value.setRule(JSON.parse(JSON.stringify(iconSource)));
    iconDirty = false;
    message("图标已应用到画布", { type: "success" });
  } catch (e: any) {
    message("图标应用失败：" + e.message, { type: "error" });
  }
}

async function load() {
  const id = route.params.id as string;
  if (!id || id === "0") {
    formReady.value = true; // 新建表单无历史规则，画布就绪即可预览
    return;
  }
  loading.value = true;
  try {
    const d = await getForm(id);
    Object.assign(form, d);
    applyRuleToDesigner();
    formReady.value = true;
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  loading.value = false;
}

/** 定时器句柄，组件卸载时必须清理 */
let ruleTimer: ReturnType<typeof setTimeout> | null = null;

/**
 * 把已加载的表单定义灌入设计器
 *
 * 不能用固定 setTimeout(200)：FcDesigner 是第三方组件，挂载时机在慢网/低端机上
 * 可能远超 200ms，届时 designer.value 仍为 null，旧代码会静默 return，
 * 用户看到空白设计器，一旦保存就把已有字段全部清空。
 * 改为轮询等待就绪，超时后明确报错而不是静默丢弃。
 */
function applyRuleToDesigner(maxWait = 5000) {
  if (ruleTimer) {
    clearTimeout(ruleTimer);
    ruleTimer = null;
  }
  const startedAt = Date.now();
  const tryApply = () => {
    const dd = designer.value;
    if (!dd) {
      if (Date.now() - startedAt < maxWait) {
        ruleTimer = setTimeout(tryApply, 100);
      } else {
        message("设计器加载超时，表单字段未能载入，请刷新页面重试", {
          type: "error"
        });
      }
      return;
    }
    try {
      if (Array.isArray(form.fields) && form.fields.length) {
        dd.setRule(JSON.parse(JSON.stringify(form.fields)));
      }
      if (
        form.settings?.formOption &&
        Object.keys(form.settings.formOption).length
      ) {
        try {
          dd.setOption(JSON.parse(JSON.stringify(form.settings.formOption)));
        } catch (e) {
          /* 忽略选项恢复失败 */
        }
      }
    } catch (e: any) {
      message("表单定义载入失败：" + e.message, { type: "error" });
    }
  };
  tryApply();
}

async function save(andPublish: boolean) {
  if (!(form.title || "").trim()) {
    message("请先填写表单标题", { type: "error" });
    return;
  }
  let payload: any;
  try {
    payload = collect();
  } catch (e: any) {
    message(e.message, { type: "error" });
    return;
  }
  const rule = JSON.parse(payload.fields);
  if (andPublish && !rule.length) {
    message("表单还没有任何字段，无法发布", { type: "error" });
    andPublish = false;
  }
  saving.value = true;
  try {
    const body = {
      title: form.title,
      description: form.description,
      fields: payload.fields,
      settings: Object.assign({}, settings.value, {
        formOption: payload.formOption
      })
    };
    if (form.id) {
      await updateForm(form.id, body);
    } else {
      const d = await createForm(body);
      form.id = d.id;
      form.slug = d.slug;
      window.history.replaceState(null, "", "#/design/" + d.id);
    }
    message("已保存", { type: "success" });
    if (andPublish) {
      await setFormStatus(form.id, 1);
      form.status = 1;
      message("表单已开始收集", { type: "success" });
    }
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  saving.value = false;
}

async function stopCollect() {
  const ok = await ElMessageBox.confirm(
    "停止后填写页将无法继续提交，确定停止收集吗？",
    "提示",
    { confirmButtonText: "确定", cancelButtonText: "取消", type: "warning" }
  )
    .then(() => true)
    .catch(() => false);
  if (!ok) return;
  try {
    await setFormStatus(form.id, 2);
    form.status = 2;
    message("已停止收集", { type: "success" });
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

function openSettings() {
  settings.value = normalizeSettingsView(form.settings || {});
  collectPhoneFields();
  settingsVisible.value = true;
  // 「提交后跳转」与关联入口需要页面下拉数据，异步加载不阻塞抽屉显示
  if (!pageOptions.value.length) loadPageOptions();
}

async function applySettings() {
  if (!form.id) {
    settingsVisible.value = false;
    message("设置已暂存，保存表单时生效", { type: "success" });
    return;
  }
  try {
    let payload: any = null;
    try {
      payload = collect();
    } catch (e) {
      /* 设计器未就绪时忽略 */
    }
    await updateForm(form.id, {
      description: form.description,
      settings: Object.assign({}, settings.value, {
        formOption: payload ? payload.formOption : settings.value.formOption
      })
    });
    const fresh = await getForm(form.id);
    form.settings = fresh.settings;
    message("设置已保存", { type: "success" });
    settingsVisible.value = false;
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

/* 预览：不依赖 el-dialog 的 opened 事件（其依赖转场动画完成，
     个别环境动画被禁用时事件不触发，会出现空弹窗） */
function preview() {
  previewVisible.value = true;
  nextTick(() => setTimeout(() => renderPreview(), 260));
}

/** 预览弹窗关闭：复位预览状态，避免下次打开残留上一次的规则 */
function destroyPreview() {
  previewApi.value = null;
  previewRule.value = [];
  previewTailRule.value = [];
  previewOption.value = {};
  previewEmpty.value = false;
}

/** 预览弹窗内提交：仅做前端校验演示，不写入数据库 */
async function previewSubmit() {
  const api = previewApi.value;
  if (!api) {
    message("预览表单尚未就绪", { type: "error" });
    return;
  }
  try {
    const valid = await api.validate();
    if (!valid) {
      message("请填写完整的必填项", { type: "error" });
      return;
    }
    message("预览校验通过（预览不会真正提交数据）", { type: "success" });
  } catch (e: any) {
    message(e?.message || "预览校验失败", { type: "error" });
  }
}

async function renderPreview(maxWait = 5000) {
  // 设计器组件、表单规则都可能仍在加载：组件挂载后 getRule 短暂可抛错，
  // 因此把「取规则」一并纳入轮询，超时才提示，绝不静默给出空弹窗
  const startedAt = Date.now();
  let rule: any[] = [];
  let option: any = {};
  for (;;) {
    const waited = Date.now() - startedAt;
    if (!designer.value || (form.id && !formReady.value) || waited > maxWait) {
      message("设计器尚未就绪，请稍后重试", { type: "error" });
      return;
    }
    try {
      rule = JSON.parse(JSON.stringify(designer.value.getRule() || []));
      option = JSON.parse(JSON.stringify(designer.value.getOption() || {}));
      break;
    } catch (e) {
      await new Promise(r => setTimeout(r, 150));
    }
  }
  if (!rule.length) {
    previewEmpty.value = true;
    return;
  }
  previewEmpty.value = false;
  // 省市区级联的数据必须在渲染前就位，异步后补不会触发表单重渲染
  if (JSON.stringify(rule).includes("__region")) {
    await preloadRegionData();
  }
  patchRule(rule, { formId: form.id }, regionData());
  // 深拷贝后交给模板内声明的 form-create 渲染（共享主应用全部组件注册）
  // 设计器导出的 option 是嵌套结构（submitBtn: {show}），统一改为对象形式禁用内置按钮
  const clean: any = JSON.parse(JSON.stringify(option));
  clean.submitBtn = { show: false };
  clean.resetBtn = { show: false };
  option = clean;
  previewOption.value = option;
  // 提交按钮位置切分：拖入的「提交按钮」标记决定按钮位置；
  // 无标记时末尾的纯展示辅助组件排在按钮下方
  const parts = splitBySubmit(rule);
  previewRule.value = parts.before;
  previewTailRule.value = parts.after;
  previewOption.value = option;
  previewKey.value++;
}

/** 字段图标名：存于 props.__icon（顶层 __icon 兼容历史数据） */
function iconName(rule: any): string {
  if (!rule) return "";
  return String((rule.props && rule.props.__icon) || rule.__icon || "");
}

/** 字段标题纯文本（图标由 __icon 单独渲染，不混入标题） */
function titleText(rule: any): string {
  if (!rule) return "";
  const t = rule.title;
  if (typeof t === "string") return t;
  if (t && typeof t === "object" && typeof t.title === "string") return t.title;
  return String(rule.field || "");
}

/* 快捷键 Ctrl/Cmd+S 保存 */
function keyHandler(e: KeyboardEvent) {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "s") {
    e.preventDefault();
    if (!saving.value) save(false);
  }
}

/**
 * 捕获 form-create 内部的渲染错误
 *
 * 只放行已知的非致命错误；其余返回 true 让它继续上抛，
 * 否则保存失败等真实问题会被静默吞掉、既不进全局 errorHandler 也不提示用户。
 */
onErrorCaptured((err, _inst, info) => {
  console.warn("[designer]", err, info);
  const msg = String((err as Error)?.message ?? err);
  const benign =
    /ResizeObserver loop|Failed to execute 'getComputedStyle'/i.test(msg);
  if (benign) return false;
  message("设计器出现异常：" + msg, { type: "error" });
  return true;
});

onMounted(() => {
  window.addEventListener("keydown", keyHandler);
  load();
});
onBeforeUnmount(() => {
  window.removeEventListener("keydown", keyHandler);
  if (ruleTimer) {
    clearTimeout(ruleTimer);
    ruleTimer = null;
  }
});
</script>

<template>
  <div v-loading="loading" class="designer-page">
    <div class="designer-bar">
      <div class="bar-left">
        <el-button :icon="ArrowLeft" @click="router.push('/forms/index')">
          返回
        </el-button>
        <el-input
          v-model="form.title"
          class="designer-title"
          maxlength="100"
          placeholder="表单标题"
          :prefix-icon="Tickets"
        />
        <el-tag
          v-if="form.id"
          :type="
            form.status === 1
              ? 'success'
              : form.status === 2
                ? 'warning'
                : 'info'
          "
        >
          {{
            form.status === 1 ? "收集中" : form.status === 2 ? "已关闭" : "草稿"
          }}
        </el-tag>
      </div>
      <div class="bar-right">
        <el-tooltip
          content="给字段标题挑一个 emoji 图标，填写页/导出/通知同步显示"
          placement="bottom"
        >
          <el-button :icon="PriceTag" @click="openIcons">字段图标</el-button>
        </el-tooltip>
        <el-tooltip content="表单设计完整使用指南" placement="bottom">
          <el-button :icon="QuestionFilled" circle @click="helpVisible = true" />
        </el-tooltip>
        <el-button :icon="View" @click="preview">预览</el-button>
        <el-button :icon="Setting" @click="openSettings">表单设置</el-button>
        <el-button
          v-if="form.id"
          :icon="Document"
          @click="router.push('/forms/data/' + form.id)"
        >
          数据
        </el-button>
        <el-button
          type="primary"
          :loading="saving"
          :icon="Check"
          @click="save(false)"
        >
          保存
        </el-button>
        <el-button
          v-if="form.status !== 1"
          type="success"
          :loading="saving"
          :icon="Promotion"
          @click="save(true)"
        >
          保存并发布
        </el-button>
        <el-button v-if="form.status === 1" type="warning" @click="stopCollect">
          停止收集
        </el-button>
      </div>
    </div>

    <FcDesigner ref="designer" :config="designerConfig" class="designer-body" />

    <!-- 表单设置抽屉 -->
    <el-drawer
      v-model="settingsVisible"
      title="表单设置"
      size="500px"
      :destroy-on-close="false"
    >
      <el-form label-width="110px" label-position="left">
        <el-form-item label="表单说明">
          <el-input
            v-model="form.description"
            type="textarea"
            :rows="3"
            placeholder="显示在填写页标题下方"
          />
        </el-form-item>
        <el-form-item label="提交按钮文案">
          <el-input v-model="settings.submitText" placeholder="提 交" />
        </el-form-item>
        <el-form-item label="成功提示文案">
          <el-input
            v-model="settings.successText"
            placeholder="提交成功，感谢您的填写！"
          />
        </el-form-item>
        <el-form-item label="主题色">
          <el-color-picker v-model="settings.theme" />
        </el-form-item>
        <el-divider>收集限制</el-divider>
        <el-form-item label="访问密码">
          <el-input
            v-model="settings.accessPassword"
            placeholder="留空则无需密码"
            clearable
          />
        </el-form-item>
        <el-form-item label="截止时间">
          <el-date-picker
            v-model="settings.endTime"
            type="datetime"
            format="YYYY-MM-DD HH:mm"
            value-format="YYYY-MM-DD HH:mm"
            placeholder="留空则不限制"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="提交数量上限">
          <el-input-number
            v-model="settings.maxCount"
            :min="0"
            :step="50"
            style="width: 100%"
          />
          <div class="form-tip">0 表示不限制</div>
        </el-form-item>
        <el-form-item label="仅可提交一次">
          <el-switch v-model="settings.limitOnce" />
          <div class="form-tip">
            按 IP + 设备判断，开启后同一环境仅能提交一次
          </div>
        </el-form-item>
        <el-form-item label="提交需审核">
          <el-switch v-model="settings.needReview" />
          <div class="form-tip">
            开启后新提交进入待审核，需在数据页手动通过后计入统计
          </div>
        </el-form-item>
        <el-divider>提交验证</el-divider>
        <el-form-item label="验证方式">
          <el-radio-group v-model="settings.captcha">
            <el-radio value="none">不验证</el-radio>
            <el-radio value="image">图像验证码</el-radio>
            <el-radio value="sms">短信验证码</el-radio>
            <el-radio value="geetest">极验</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="通知邮箱">
          <el-input
            v-model="settings.notify_emails"
            placeholder="新提交通知邮箱，多个用英文逗号分隔，留空不发"
            clearable
          />
          <div class="form-tip">
            发件邮箱需先在 系统设置-邮件通知
            中配置；每个表单可设置不同的接收邮箱
          </div>
        </el-form-item>
        <el-form-item v-if="settings.captcha === 'sms'" label="手机号字段">
          <el-select
            v-model="settings.smsPhoneField"
            placeholder="选择表单中的手机号字段"
            style="width: 100%"
          >
            <el-option
              v-for="f in phoneFields"
              :key="f.field"
              :label="f.title"
              :value="f.field"
            />
          </el-select>
          <div class="form-tip">验证码将发送到访客在此字段填写的手机号</div>
        </el-form-item>
        <div
          v-if="settings.captcha === 'sms' || settings.captcha === 'geetest'"
          class="form-tip"
          style="margin: -8px 0 12px"
        >
          {{
            settings.captcha === "sms"
              ? "使用前请在 系统设置-短信服务 中完成服务商配置"
              : "使用前请在 系统设置-极验配置 中填写验证 ID 与密钥"
          }}
        </div>
        <el-divider>在线客服</el-divider>
        <el-form-item label="客服方式">
          <el-radio-group v-model="settings.service.type">
            <el-radio value="none">不显示</el-radio>
            <el-radio value="link">客服链接</el-radio>
            <el-radio value="qrcode">客服二维码</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="settings.service.type === 'link'" label="客服链接">
          <el-input
            v-model="settings.service.link"
            placeholder="https://work.weixin.qq.com/kfid/..."
          />
          <div class="form-tip">填写页右下角浮窗跳转</div>
        </el-form-item>
        <el-form-item
          v-if="settings.service.type === 'qrcode'"
          label="客服二维码"
        >
          <el-input
            v-model="settings.service.qrcode"
            placeholder="二维码图片地址 https://..."
          />
          <div class="form-tip">可先上传到 附件管理 后复制链接填入</div>
        </el-form-item>
        <el-divider>提交后跳转</el-divider>
        <el-form-item label="跳转方式">
          <el-radio-group v-model="settings.afterSubmit.type">
            <el-radio value="none">不跳转</el-radio>
            <el-radio value="page">跳转到页面</el-radio>
            <el-radio value="url">跳转到链接</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item
          v-if="settings.afterSubmit.type === 'page'"
          label="目标页面"
        >
          <el-select
            v-model="settings.afterSubmit.target"
            filterable
            placeholder="选择一个已发布的页面"
            style="width: 100%"
          >
            <el-option
              v-for="p in pageOptions"
              :key="p.slug"
              :label="p.title"
              :value="p.slug"
            />
          </el-select>
          <div class="form-tip">
            提交成功后自动打开该页面；页面分享码为
            {{ settings.afterSubmit.target || "（未选择）" }}
          </div>
        </el-form-item>
        <el-form-item
          v-if="settings.afterSubmit.type === 'url'"
          label="目标链接"
        >
          <el-input
            v-model="settings.afterSubmit.target"
            placeholder="https://example.com/thanks"
          />
          <div class="form-tip">仅支持 http / https 链接</div>
        </el-form-item>
        <el-form-item
          v-if="settings.afterSubmit.type !== 'none'"
          label="跳转延迟"
        >
          <el-input-number
            v-model="settings.afterSubmit.delay"
            :min="0"
            :max="60"
            :step="1"
          />
          <span class="form-tip" style="margin-left: 8px">
            秒（0 表示立即跳转）
          </span>
        </el-form-item>

        <el-divider>关联页面入口</el-divider>
        <div class="form-tip" style="margin-bottom: 8px">
          在填写页表单下方展示的按钮，点击后在新标签页打开
        </div>
        <div v-for="(l, i) in settings.links" :key="i" class="link-row">
          <el-input
            v-model="l.title"
            placeholder="按钮文字"
            style="width: 140px"
          />
          <el-radio-group v-model="l.type" size="small">
            <el-radio-button value="page">页面</el-radio-button>
            <el-radio-button value="url">链接</el-radio-button>
          </el-radio-group>
          <el-input
            v-if="l.type === 'page'"
            v-model="l.target"
            placeholder="页面分享码"
            style="flex: 1"
          />
          <el-input
            v-else
            v-model="l.target"
            placeholder="https://example.com"
            style="flex: 1"
          />
          <el-button link type="danger" @click="settings.links.splice(i, 1)">
            删除
          </el-button>
        </div>
        <el-button
          size="small"
          :disabled="settings.links.length >= 10"
          @click="
            settings.links.push({
              type: 'page',
              title: '',
              target: ''
            })
          "
        >
          添加入口
        </el-button>
        <div v-if="settings.links.length >= 10" class="form-tip">
          最多添加 10 个入口
        </div>
      </el-form>
      <template #footer>
        <el-button @click="settingsVisible = false">取消</el-button>
        <el-button type="primary" @click="applySettings">应用</el-button>
      </template>
    </el-drawer>

    <!-- 预览弹窗 -->
    <el-dialog
      v-model="previewVisible"
      title="表单预览"
      width="640px"
      @closed="destroyPreview"
    >
      <div class="preview-body">
        <div class="preview-head">
          <div class="preview-title">{{ form.title || "未命名表单" }}</div>
          <div v-if="form.description" class="preview-desc">
            {{ form.description }}
          </div>
        </div>
        <form-create
          v-if="previewRule.length"
          :key="previewKey"
          v-model:api="previewApi"
          :rule="previewRule"
          :option="previewOption"
        >
          <!-- 字段图标：渲染规则 __icon 上的 Element Plus 图标 -->
          <template #title="{ rule }">
            <el-icon
              v-if="rule && iconComp(iconName(rule))"
              :size="15"
              style="vertical-align: -2.5px; margin-right: 4px"
            >
              <component :is="iconComp(iconName(rule))" />
            </el-icon>{{ titleText(rule) }}
          </template>
        </form-create>
        <div
          v-if="previewEmpty"
          style="color: #909399; text-align: center; padding: 24px 0"
        >
          表单还没有字段
        </div>
        <el-button
          type="primary"
          size="large"
          style="width: 100%; margin-top: 22px"
          :style="{ background: settings.theme, borderColor: settings.theme }"
          @click="previewSubmit"
        >
          {{ settings.submitText || "提 交" }}
        </el-button>
        <!-- 提交按钮下方的尾部辅助组件（提示/文字/分割线/图片等） -->
        <form-create
          v-if="previewTailRule.length"
          :key="previewKey + '-tail'"
          v-model:api="previewTailApi"
          :rule="previewTailRule"
          :option="previewOption"
        >
          <template #title="{ rule }">
            <el-icon
              v-if="rule && iconComp(iconName(rule))"
              :size="15"
              style="vertical-align: -2.5px; margin-right: 4px"
            >
              <component :is="iconComp(iconName(rule))" />
            </el-icon>{{ titleText(rule) }}
          </template>
        </form-create>
      </div>
    </el-dialog>

    <!-- 字段图标设置 -->
    <el-dialog v-model="iconVisible" title="字段图标" width="560px">
      <div class="form-tip" style="margin-bottom: 10px">
        图标显示在字段标题前（填写页与页面内嵌），字段标题保持纯文本，
        导出与提交通知不受影响；随时可回来更换或清除。
      </div>
      <el-empty
        v-if="!iconRows.length"
        description="还没有可设置的字段，先在左侧画布添加字段"
        :image-size="72"
      />
      <div v-else class="fi-rows">
        <div v-for="row in iconRows" :key="row.field" class="fi-row">
          <span class="fi-now">
            <el-icon v-if="row.icon" :size="18">
              <component :is="iconComp(row.icon)" />
            </el-icon>
            <span v-else class="fi-none">—</span>
          </span>
          <span class="fi-title">{{ row.title }}</span>
          <el-tag size="small" type="info" class="fi-field">{{ row.field }}</el-tag>
          <span class="fi-acts">
            <el-popover
              placement="bottom-end"
              :width="360"
              trigger="click"
              :persistent="false"
            >
              <template #reference>
                <el-button size="small" link type="primary">
                  {{ row.icon ? "更换" : "选择图标" }}
                </el-button>
              </template>
              <div
                v-for="g in ICON_GROUPS"
                :key="g.name"
                class="fi-group"
              >
                <div class="fi-group-name">{{ g.name }}</div>
                <div class="fi-grid">
                  <button
                    v-for="em in g.items"
                    :key="em"
                    type="button"
                    class="fi-cell"
                    :class="{ active: row.icon === em }"
                    :title="em"
                    @click="applyIcon(row, em)"
                  >
                    <el-icon :size="17">
                      <component :is="iconComp(em)" />
                    </el-icon>
                  </button>
                </div>
              </div>
            </el-popover>
            <el-button
              v-if="row.icon"
              size="small"
              link
              type="danger"
              @click="clearIcon(row)"
            >
              清除
            </el-button>
          </span>
        </div>
      </div>
      <template #footer>
        <el-button type="primary" @click="commitIcons">完成并应用</el-button>
      </template>
    </el-dialog>

    <!-- 使用指南 -->
    <el-dialog v-model="helpVisible" title="表单设计使用指南" width="680px">
      <div class="help-guide">
        <div class="guide-sec">
          <div class="guide-title">一、快速开始</div>
          <ol>
            <li>表单管理 → 新建表单：选择<b>模板</b>（联系信息/活动报名/号卡办理等 11 套）或从空白开始。</li>
            <li>在中间画布设计字段，右上角「保存」或「保存并发布」。</li>
            <li>发布后点列表里的「分享」拿到填写链接 <code>/s/{slug}</code>，二维码可直接投放；也可在「页面管理」用 <code v-pre>&#123;&#123;form:表单分享码&#125;&#125;</code> 把表单嵌入图文页。</li>
          </ol>
        </div>
        <div class="guide-sec">
          <div class="guide-title">二、添加与配置字段</div>
          <ul>
            <li><b>添加</b>：左侧组件面板点击或拖拽到画布；支持输入框、选择器、上传、省市区、子表单等。</li>
            <li><b>字段名称（field）</b>：提交数据的存储键名，数据列表/导出/Webhook 都按它标识。建议英文（name、mobile），有历史数据后不要再改。</li>
            <li><b>类名（class）</b>：控件的自定义 CSS 类，深度定制样式才用，一般留空。</li>
            <li><b>必填与校验</b>：右侧面板「是否必填」开关 + 验证规则（手机号/身份证等用正则校验）。</li>
            <li><b>字段图标</b>：工具栏「字段图标」给标题配 Element Plus 图标，填写页与内嵌页展示。</li>
          </ul>
        </div>
        <div class="guide-sec">
          <div class="guide-title">三、特色能力</div>
          <ul>
            <li><b>省市区</b>：基础组件面板直接拖入，内置全国三级区划数据，无需维护。</li>
            <li><b>上传</b>：图片/文件上传，可限张数；配合表单附件容量上限管理。</li>
            <li><b>提交按钮的位置</b>：默认在所有字段之后。要调整位置，从<b>辅助组件</b>面板把「提交按钮」拖到目标处——填写页就在那里渲染真正的提交按钮（含验证码与跳转逻辑），按钮下方的内容（如活动须知）也会跟着排在其后。</li>
            <li><b>号卡办理等成套字段</b>：新建表单时选模板一键生成。</li>
          </ul>
        </div>
        <div class="guide-sec">
          <div class="guide-title">四、表单设置（工具栏「表单设置」）</div>
          <ul>
            <li><b>文案与主题</b>：提交按钮文案、成功提示、主题色。</li>
            <li><b>防刷</b>：图形/短信/极验验证码，限一次（同 IP+设备），截止时间，提交上限。</li>
            <li><b>审核</b>：开启后新提交进入待审核，管理员在通知与数据页审核。</li>
            <li><b>访问密码</b>：整表加密，访客凭密码进入。</li>
            <li><b>通知</b>：邮件通知收件箱 + 全局 Webhook（受系统设置「启用通知」总开关控制）。</li>
            <li><b>提交后跳转</b>：成功页跳指定页面或外链；「关联页面入口」在填写页底部展示按钮。</li>
          </ul>
        </div>
        <div class="guide-sec">
          <div class="guide-title">五、数据与统计</div>
          <ul>
            <li>列表「数据」：查看/备注/旗标/导出 CSV；删除进回收站可恢复。</li>
            <li>列表「统计」：字段聚合与趋势图。</li>
            <li>顶栏通知：新提交与待审核实时提醒。</li>
          </ul>
        </div>
        <div class="guide-sec">
          <div class="guide-title">六、常见问题</div>
          <ul>
            <li>改了字段名称，旧提交还能对上吗？—— 不能，字段名是数据的键，改名仅影响之后的新数据。</li>
            <li>预览会写数据吗？—— 不会，预览仅本地校验演示。</li>
            <li>停用收集后链接会失效吗？—— 填写页会提示「已停止收集」，已有数据不受影响。</li>
          </ul>
        </div>
      </div>
      <template #footer>
        <el-button type="primary" @click="helpVisible = false">我知道了</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.designer-page {
  background: #fff;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

/* 关联页面入口的每一行 */
.link-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

/* 使用指南 */
.help-guide {
  max-height: 62vh;
  overflow: auto;
  padding-right: 6px;
  font-size: 13px;
  line-height: 1.8;
  color: #606266;
  .guide-sec + .guide-sec {
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid #f0f2f5;
  }
  .guide-title {
    font-weight: 650;
    color: #303133;
    margin-bottom: 4px;
  }
  ul,
  ol {
    padding-left: 18px;
    li + li {
      margin-top: 2px;
    }
  }
  code {
    background: #f5f7fa;
    border-radius: 4px;
    padding: 0 5px;
    font-size: 12px;
    color: #c7254e;
  }
  b {
    color: #303133;
  }
}

/* 字段图标设置 */
.fi-rows {
  max-height: 52vh;
  overflow: auto;
}
.fi-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 7px 4px;
  border-bottom: 1px solid #f5f7fa;
  .fi-now {
    width: 30px;
    text-align: center;
    font-size: 17px;
    .fi-none {
      color: #c0c4cc;
    }
  }
  .fi-title {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 13.5px;
  }
  .fi-field {
    flex-shrink: 0;
  }
  .fi-acts {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: 4px;
  }
}
.fi-group {
  & + .fi-group {
    margin-top: 8px;
  }
  .fi-group-name {
    font-size: 12px;
    color: #909399;
    margin: 4px 0;
  }
}
.fi-grid {
  display: grid;
  grid-template-columns: repeat(10, 1fr);
  gap: 2px;
}
.fi-cell {
  border: 1px solid transparent;
  border-radius: 6px;
  background: none;
  font-size: 17px;
  line-height: 1;
  padding: 5px 0;
  cursor: pointer;
  &:hover {
    background: #f5f7fa;
  }
  &.active {
    border-color: var(--el-color-primary);
    background: var(--el-color-primary-light-9);
  }
}

.designer-bar {
  height: 56px;
  flex-shrink: 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0 14px;
  border-bottom: 1px solid #e4e7ed;
  background: #fff;
  gap: 10px;
  flex-wrap: wrap;

  .bar-left,
  .bar-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }

  .designer-title {
    width: 300px;
  }
}

.designer-body {
  flex: 1;
  min-height: 0;
}

.form-tip {
  color: #909399;
  font-size: 12px;
  line-height: 1.6;
}

.preview-body {
  max-height: 62vh;
  overflow: auto;
  padding: 4px 6px;
}

.preview-head {
  margin-bottom: 16px;
}

.preview-title {
  font-size: 19px;
  font-weight: 700;
  text-align: center;
}

.preview-desc {
  color: #909399;
  font-size: 13px;
  margin-top: 8px;
  white-space: pre-wrap;
}
</style>
