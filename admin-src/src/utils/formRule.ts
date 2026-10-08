/**
 * form-create 规则兼容层：
 * 1. 设计器 3.5 的 textarea 类型在 form-create 3.2 独立版中不存在，
 *    渲染会降级为原生标签且值不进模型 —— 统一转换为 input + props.type=textarea
 * 2. 上传控件补默认提交地址，并在上传成功后把服务器地址写回 file.url
 *    （element-plus 的 file.url 上传成功后仍是本地 blob 预览地址）
 * 3. 省市区级联（props.__region）注入全国区划数据 —— 数据体量约 150KB，
 *    不能内联进字段规则（每次保存都会整份写入 fields_json），只能渲染时注入
 */
export interface FcRuleNode {
  type?: string;
  field?: string;
  title?: any;
  props?: any;
  children?: any[];
  [key: string]: any;
}

/** 全国省市区数据：预加载后缓存，patchRule 渲染时同步注入。
 *  不能异步后补 options —— form-create 挂载后对原始规则对象的赋值
 *  不会触发表单重渲染，级联面板会一直是空的。 */
let regionCache: any[] | null = null;
export function regionData(): any[] | null {
  return regionCache;
}
export async function preloadRegionData(): Promise<void> {
  if (regionCache) return;
  try {
    const r = await fetch("/static/vendor/region-data.json");
    if (r.ok) {
      const d = await r.json();
      if (Array.isArray(d) && d.length) regionCache = d;
    }
  } catch (e) {
    /* 数据缺失时级联无选项，不影响表单其余功能 */
  }
}

export function patchRule(
  rule: FcRuleNode[],
  opts?: { formId?: number },
  region?: any[] | null
) {
  // 提示信息节点：渲染在输入框正下方
  const hintNode = (text: string): FcRuleNode => ({
    type: "div",
    class: "of-field-hint",
    style: { color: "#909399", fontSize: "12px", lineHeight: "1.5", margin: "-14px 0 14px" },
    children: ["\u2139 " + text]
  });
  const walk = (nodes: FcRuleNode[]) => {
    // 倒序遍历：可在当前字段后原地插入提示节点
    for (let i = (nodes || []).length - 1; i >= 0; i--) {
      const n = nodes[i];
      if (!n || typeof n !== "object") continue;
      if (n.type === "upload") {
        n.props = n.props || {};
        // 预览与实际填写一致：上传地址无条件覆盖（历史占位值会导致 302 进后台）
        n.props.action =
          "/api/upload" + (opts?.formId ? "?formId=" + opts.formId : "");
        n.props.name = n.props.name || "file";
        // 后台登录态下预览/测试上传：带上 Cookie 里的 CSRF 令牌
        const m = document.cookie.match(/(?:^|;\s*)csrf-token=([^;]+)/);
        if (m) {
          try {
            n.props.headers = Object.assign({}, n.props.headers || {}, {
              "X-CSRF-TOKEN": decodeURIComponent(m[1])
            });
          } catch (e) {
            /* 忽略 */
          }
        }
        if (!n.props.onSuccess) {
          n.props.onSuccess = function (res: any, file: any) {
            const url = res && res.data && res.data.url;
            if (url && file) file.url = url;
            else if (file) file.status = "fail"; // 响应无有效地址 → 按失败处理
          };
        }
      }
      if (n.type === "textarea") {
        n.type = "input";
        n.props = n.props || {};
        n.props.type = "textarea";
      }
      // 提示信息：注入到输入框正下方的提示节点（与填写页一致）
      if (n.field && n.info) {
        const text = n.info;
        n.info = "";
        nodes.splice(i + 1, 0, hintNode(text));
      }
      if (n.type === "cascader" && n.props && n.props.__region) {
        n.props.props = Object.assign(
          { emitPath: true },
          n.props.props || {}
        );
        n.props.options =
          Array.isArray(region) && region.length ? region : [];
      }
      if (Array.isArray(n.children)) walk(n.children);
      if (n.props && Array.isArray(n.props.rule)) walk(n.props.rule);
    }
  };
  walk(rule);
  return rule;
}

/**
 * 从规则末尾拆出纯展示类辅助组件（提示/文字/分割线/图片等无字段节点），
 * 拆出的部分渲染在提交按钮下方——否则它们只能排在按钮上面。
 * 遇到第一个带字段的节点或布局容器即停止（布局容器里可能装着字段）。
 */
export function splitTrailingAux(
  rule: FcRuleNode[]
): [FcRuleNode[], FcRuleNode[]] {
  const LAYOUT = new Set([
    "col",
    "row",
    "card",
    "table",
    "grid",
    "tabs",
    "collapse",
    "collapseitem",
    "subform",
    "group",
    "tableform",
    "el-row",
    "el-col"
  ]);
  const main = [...(rule || [])];
  const tail: FcRuleNode[] = [];
  while (main.length) {
    const last = main[main.length - 1];
    const type = String((last && last.type) || "").toLowerCase();
    if (last && !last.field && !LAYOUT.has(type)) {
      tail.unshift(main.pop() as FcRuleNode);
    } else {
      break;
    }
  }
  return [main, tail];
}

/**
 * 按提交按钮标记切分规则。
 *
 * 用户可以从辅助组件面板把「提交按钮」（props.__submit 标记的按钮节点）
 * 拖到任意位置：标记之前是主表单，标记之后是提交按钮下方的内容；
 * 没有标记时保持默认行为——按钮在最后，末尾的纯展示辅助组件排到按钮下方。
 */
export function splitBySubmit(rule: FcRuleNode[]): {
  before: FcRuleNode[];
  after: FcRuleNode[];
  hasMarker: boolean;
} {
  const idx = (rule || []).findIndex(
    n => !!n && typeof n === "object" && n.props && n.props.__submit
  );
  if (idx === -1) {
    const [before, after] = splitTrailingAux(rule);
    return { before, after, hasMarker: false };
  }
  return {
    before: rule.slice(0, idx),
    after: rule.slice(idx + 1),
    hasMarker: true
  };
}

/** 提取设计器规则中的可填写字段（含必填信息），供数据页表头/统计页聚合 */
export interface ExtractedField {
  field: string;
  title: string;
  type: string;
  required: boolean;
}

export function extractFields(rule: FcRuleNode[]): ExtractedField[] {
  const layoutTypes = [
    "col",
    "row",
    "card",
    "collapseItem",
    "collapse",
    "table",
    "grid"
  ];
  const out: ExtractedField[] = [];
  const walk = (nodes: FcRuleNode[]) => {
    (nodes || []).forEach(n => {
      if (!n || typeof n !== "object") return;
      const type = String(n.type || "");
      const field = String(n.field || "");
      if (field && !layoutTypes.includes(type)) {
        let title = n.title;
        if (title && typeof title === "object") title = title.title;
        let required = false;
        if (n.effect?.required) required = !!n.effect.required;
        else if (Array.isArray(n.validate)) {
          required = n.validate.some((v: any) => v?.required === true);
        }
        out.push({
          field,
          title: String(title ?? field).replace(/<[^>]+>/g, ""),
          type,
          required
        });
        return;
      }
      if (Array.isArray(n.children)) walk(n.children);
      if (n.props && Array.isArray(n.props.rule)) walk(n.props.rule);
    });
  };
  walk(rule);
  return out;
}

/**
 * 上传字段值归一化为 URL 数组：兼容两种形态——
 *   数组（多文件上传）与单字符串（form-create 在 limit=1 时提交的是字符串）
 */
export function toUploadUrls(v: any): string[] {
  const arr = Array.isArray(v) ? v : typeof v === "string" ? [v] : [];
  if (!arr.length) return [];
  const ok = arr.every(
    (x: any) =>
      typeof x === "string" &&
      (x.indexOf("/storage/") === 0 || /^https?:\/\//.test(x))
  );
  return ok ? arr : [];
}

/** 上传字段值：是否为服务器 URL（数组或单字符串） */
export function isUploadUrls(v: any): boolean {
  return toUploadUrls(v).length > 0;
}

/** 旗标预设色：0无 1红 2橙 3黄 4绿 5蓝 6紫 */
export const FLAG_COLORS: Record<number, { name: string; color: string }> = {
  0: { name: "无旗标", color: "#c0c4cc" },
  1: { name: "红旗标", color: "#f56c6c" },
  2: { name: "橙旗标", color: "#e6a23c" },
  3: { name: "黄旗标", color: "#f7ba2a" },
  4: { name: "绿旗标", color: "#67c23a" },
  5: { name: "蓝旗标", color: "#409eff" },
  6: { name: "紫旗标", color: "#9a6fe0" }
};

/** 旗标 SVG（内联小旗） */
export function flagSvgHtml(color: string, filled: boolean): string {
  const fill = filled ? color : "none";
  const stroke = filled ? color : "#c0c4cc";
  return `<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 3v18" stroke="${stroke}" stroke-width="2" stroke-linecap="round"/><path d="M5 4h13l-3.5 4L18 12H5" fill="${fill}" stroke="${stroke}" stroke-width="1.8" stroke-linejoin="round"/></svg>`;
}

/** 状态映射 */
export const FORM_STATUS: Record<number, { text: string; type: string }> = {
  0: { text: "草稿", type: "info" },
  1: { text: "收集中", type: "success" },
  2: { text: "已关闭", type: "warning" }
};

export function statusOf(s: number) {
  return FORM_STATUS[s] || FORM_STATUS[0];
}

/** 预置模板：form-create 规则 */
export const TEMPLATES = [
  {
    key: "blank",
    name: "空白表单",
    desc: "从零开始自由设计",
    icon: "Document",
    fields: [] as any[]
  },
  {
    key: "contact",
    name: "联系信息登记",
    desc: "姓名/手机/微信/邮箱/留言",
    icon: "Iphone",
    fields: [
      {
        type: "input",
        field: "name",
        title: "姓名",
        effect: { required: true }
      },
      {
        type: "input",
        field: "phone",
        title: "手机号",
        effect: { required: true },
        validate: [{ pattern: "^1[3-9]\\d{9}$", message: "手机号格式不正确" }]
      },
      { type: "input", field: "wechat", title: "微信号" },
      {
        type: "input",
        field: "email",
        title: "邮箱",
        validate: [{ type: "email", message: "邮箱格式不正确" }]
      },
      {
        type: "textarea",
        field: "message",
        title: "备注留言",
        props: { rows: 3 }
      }
    ]
  },
  {
    key: "signup",
    name: "活动报名",
    desc: "参加人/人数/场次/备注",
    icon: "Flag",
    fields: [
      {
        type: "input",
        field: "name",
        title: "姓名",
        effect: { required: true }
      },
      {
        type: "input",
        field: "phone",
        title: "手机号",
        effect: { required: true },
        validate: [{ pattern: "^1[3-9]\\d{9}$", message: "手机号格式不正确" }]
      },
      {
        type: "inputNumber",
        field: "people",
        title: "参加人数",
        props: { min: 1, max: 20 },
        effect: { required: true }
      },
      {
        type: "radio",
        field: "session",
        title: "参加场次",
        effect: { required: true },
        options: [
          { label: "上午场", value: "上午场" },
          { label: "下午场", value: "下午场" },
          { label: "晚场", value: "晚场" }
        ]
      },
      { type: "textarea", field: "remark", title: "备注" }
    ]
  },
  {
    key: "survey",
    name: "满意度问卷",
    desc: "评分/多选/建议",
    icon: "Star",
    fields: [
      {
        type: "rate",
        field: "score",
        title: "整体满意度",
        effect: { required: true },
        props: { allowHalf: false }
      },
      {
        type: "checkbox",
        field: "like",
        title: "您喜欢哪些方面（多选）",
        options: [
          { label: "产品设计", value: "产品设计" },
          { label: "使用体验", value: "使用体验" },
          { label: "服务质量", value: "服务质量" },
          { label: "性价比", value: "性价比" }
        ]
      },
      {
        type: "textarea",
        field: "advice",
        title: "您的宝贵建议",
        props: { rows: 4 },
        validate: [{ required: true, message: "请填写建议" }]
      }
    ]
  },
  {
    key: "feedback",
    name: "意见反馈",
    desc: "评分/问题类型/详细描述",
    icon: "ChatDotRound",
    fields: [
      {
        type: "rate",
        field: "score",
        title: "满意程度",
        effect: { required: true }
      },
      {
        type: "select",
        field: "type",
        title: "问题类型",
        effect: { required: true },
        options: [
          { label: "功能异常", value: "功能异常" },
          { label: "体验建议", value: "体验建议" },
          { label: "其他", value: "其他" }
        ]
      },
      {
        type: "textarea",
        field: "content",
        title: "详细描述",
        props: { rows: 4 },
        effect: { required: true }
      },
      { type: "input", field: "contact", title: "联系方式（选填）" }
    ]
  },
  {
    key: "recruit",
    name: "招聘/入职登记",
    desc: "学历/经历/期望薪资",
    icon: "Suitcase",
    fields: [
      {
        type: "input",
        field: "name",
        title: "姓名",
        effect: { required: true }
      },
      {
        type: "input",
        field: "phone",
        title: "手机号",
        effect: { required: true },
        validate: [{ pattern: "^1[3-9]\\d{9}$", message: "手机号格式不正确" }]
      },
      {
        type: "select",
        field: "edu",
        title: "最高学历",
        effect: { required: true },
        options: [
          { label: "大专", value: "大专" },
          { label: "本科", value: "本科" },
          { label: "硕士", value: "硕士" },
          { label: "博士", value: "博士" }
        ]
      },
      {
        type: "inputNumber",
        field: "salary",
        title: "期望薪资（K/月）",
        props: { min: 1, max: 200 }
      },
      {
        type: "textarea",
        field: "exp",
        title: "工作/项目经历",
        props: { rows: 4 }
      }
    ]
  },
  {
    key: "checkin",
    name: "活动签到",
    desc: "姓名/参与日期/同行人数",
    icon: "Calendar",
    fields: [
      {
        type: "input",
        field: "name",
        title: "姓名",
        effect: { required: true }
      },
      {
        type: "datePicker",
        field: "date",
        title: "参与日期",
        effect: { required: true },
        props: { type: "date", valueFormat: "YYYY-MM-DD" }
      },
      {
        type: "radio",
        field: "num",
        title: "同行人数",
        effect: { required: true },
        options: [
          { label: "1 人", value: "1" },
          { label: "2 人", value: "2" },
          { label: "3 人及以上", value: "3+" }
        ]
      },
      { type: "textarea", field: "remark", title: "备注" }
    ]
  },
  {
    key: "aftersale",
    name: "售后/工单登记",
    desc: "订单号/问题类型/凭证上传",
    icon: "Service",
    fields: [
      {
        type: "input",
        field: "order",
        title: "订单号",
        effect: { required: true }
      },
      {
        type: "radio",
        field: "issue",
        title: "问题类型",
        effect: { required: true },
        options: [
          { label: "质量问题", value: "质量问题" },
          { label: "物流问题", value: "物流问题" },
          { label: "退换货", value: "退换货" },
          { label: "其他", value: "其他" }
        ]
      },
      {
        type: "textarea",
        field: "desc",
        title: "问题描述",
        props: { rows: 3 },
        effect: { required: true }
      },
      {
        type: "upload",
        field: "proof",
        title: "凭证截图（最多 3 张）",
        props: {
          action: "/api/upload",
          name: "file",
          listType: "picture-card",
          limit: 3,
          accept: "image/*"
        }
      }
    ]
  },
  {
    key: "onboard",
    name: "员工入职登记",
    desc: "姓名/手机/身份证/学历/入职日期",
    icon: "Suitcase",
    fields: [
      {
        type: "input",
        field: "name",
        title: "姓名",
        effect: { required: true }
      },
      {
        type: "input",
        field: "phone",
        title: "手机号",
        effect: { required: true },
        validate: [{ pattern: "^1[3-9]\\d{9}$", message: "手机号格式不正确" }]
      },
      {
        type: "input",
        field: "idcard",
        title: "身份证号",
        effect: { required: true },
        validate: [
          {
            pattern:
              "^\\d{6}(19|20)\\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\\d|3[01])\\d{3}[\\dXx]$",
            message: "身份证号格式不正确"
          }
        ]
      },
      {
        type: "select",
        field: "edu",
        title: "最高学历",
        effect: { required: true },
        options: [
          { label: "高中及以下", value: "高中及以下" },
          { label: "大专", value: "大专" },
          { label: "本科", value: "本科" },
          { label: "硕士", value: "硕士" },
          { label: "博士", value: "博士" }
        ]
      },
      {
        type: "datePicker",
        field: "entry_date",
        title: "入职日期",
        props: { type: "date", valueFormat: "YYYY-MM-DD" },
        effect: { required: true }
      },
      { type: "textarea", field: "remark", title: "备注", props: { rows: 3 } }
    ]
  },
  {
    key: "nps",
    name: "NPS 推荐调研",
    desc: "0-10 分推荐值 + 归因多选 + 建议",
    icon: "ChatDotRound",
    fields: [
      {
        type: "slider",
        field: "nps_score",
        title: "您向朋友推荐我们的可能性（0-10 分）",
        props: { min: 0, max: 10, showInput: true },
        effect: { required: true }
      },
      {
        type: "checkbox",
        field: "nps_reason",
        title: "影响您评分的主要因素",
        options: [
          { label: "产品功能", value: "产品功能" },
          { label: "价格", value: "价格" },
          { label: "服务质量", value: "服务质量" },
          { label: "响应速度", value: "响应速度" },
          { label: "品牌口碑", value: "品牌口碑" }
        ]
      },
      {
        type: "textarea",
        field: "advice",
        title: "您还有什么建议",
        props: { rows: 3 }
      }
    ]
  },
  {
    key: "visit",
    name: "到访预约",
    desc: "预约人/单位/日期时段/事由",
    icon: "Calendar",
    fields: [
      {
        type: "input",
        field: "name",
        title: "预约人",
        effect: { required: true }
      },
      {
        type: "input",
        field: "phone",
        title: "手机号",
        effect: { required: true },
        validate: [{ pattern: "^1[3-9]\\d{9}$", message: "手机号格式不正确" }]
      },
      { type: "input", field: "company", title: "单位/公司" },
      {
        type: "datePicker",
        field: "visit_date",
        title: "到访日期",
        props: { type: "date", valueFormat: "YYYY-MM-DD" },
        effect: { required: true }
      },
      {
        type: "select",
        field: "visit_slot",
        title: "时段",
        effect: { required: true },
        options: [
          { label: "上午 9:00-12:00", value: "上午" },
          { label: "下午 14:00-18:00", value: "下午" }
        ]
      },
      {
        type: "textarea",
        field: "purpose",
        title: "来访事由",
        effect: { required: true },
        props: { rows: 3 }
      }
    ]
  },
  {
    key: "simcard",
    name: "号卡办理申请",
    desc: "姓名/手机/身份证/收货地址/证件照上传",
    icon: "Iphone",
    fields: [
      {
        type: "input",
        field: "name",
        title: "姓名",
        effect: { required: true },
        props: {
            __icon: "User", placeholder: "请输入收货人姓名", maxlength: 20 }
      },
      {
        type: "input",
        field: "mobile",
        title: "手机号",
        effect: { required: true },
        props: {
            __icon: "Iphone", placeholder: "请输入联系电话", maxlength: 11 },
        validate: [{ pattern: "^1[3-9]\\d{9}$", message: "手机号格式不正确" }]
      },
      {
        type: "input",
        field: "idcard",
        title: "身份证号",
        effect: { required: true },
        props: {
            __icon: "Postcard", placeholder: "请输入 18 位身份证号", maxlength: 18 },
        validate: [
          {
            pattern:
              "^\\d{6}(19|20)\\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\\d|3[01])\\d{3}[\\dXx]$",
            message: "身份证号格式不正确"
          }
        ]
      },
      {
        type: "cascader",
        field: "region",
        title: "收货地址（省市区）",
        effect: { required: true },
        props: {
            __icon: "Location",
          // __region：渲染时由 patchRule 注入全国省市区数据（见 formRule.ts）
          __region: true,
          placeholder: "选择省 / 市 / 区",
          clearable: true
        }
      },
      {
        type: "input",
        field: "address",
        title: "详细地址",
        effect: { required: true },
        props: {
            __icon: "OfficeBuilding", placeholder: "街道、门牌号、小区/楼栋/单元" }
      },
      {
        type: "upload",
        field: "id_front",
        title: "身份证正面（人像面）",
        effect: { required: true },
        props: {
            __icon: "Camera",
          action: "/api/upload",
          name: "file",
          listType: "picture-card",
          limit: 1,
          accept: "image/*"
        }
      },
      {
        type: "upload",
        field: "id_back",
        title: "身份证反面（国徽面）",
        effect: { required: true },
        props: {
            __icon: "Picture",
          action: "/api/upload",
          name: "file",
          listType: "picture-card",
          limit: 1,
          accept: "image/*"
        }
      },
      {
        type: "upload",
        field: "selfie",
        title: "自拍半身照",
        effect: { required: true },
        props: {
            __icon: "CameraFilled",
          action: "/api/upload",
          name: "file",
          listType: "picture-card",
          limit: 1,
          accept: "image/*"
        }
      },
      {
        type: "upload",
        field: "onecheck",
        title: "一证通查截图",
        effect: { required: true },
        props: {
            __icon: "Document",
          action: "/api/upload",
          name: "file",
          listType: "picture-card",
          limit: 1,
          accept: "image/*"
        }
      }
    ]
  }
];
