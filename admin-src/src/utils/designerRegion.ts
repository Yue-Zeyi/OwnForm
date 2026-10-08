/**
 * 设计器「省市区」基础组件
 *
 * 本质是一个带 __region 标记的级联选择器：渲染层（formRule.ts 的 patchRule、
 * form-embed.js、填写页）见到该标记就会注入内置的全国区划数据。
 * 这里只负责把它注册成「基础组件」面板里可直接拖入的组件。
 */

/** 画布展示用示例数据：真实区划数据由渲染层在渲染前整份覆盖注入 */
const SAMPLE_OPTIONS = [
  {
    value: "广东省",
    label: "广东省",
    children: [
      {
        value: "深圳市",
        label: "深圳市",
        children: [{ value: "南山区", label: "南山区" }]
      }
    ]
  }
];

function newRegionField(): string {
  return "region_" + Math.random().toString(36).slice(2, 8);
}

export function registerRegionComponent(FcDesigner: any): void {
  const addDragRule = FcDesigner?.addDragRule ?? FcDesigner?.default?.addDragRule;
  if (typeof addDragRule !== "function") {
    throw new Error("addDragRule API 不可用");
  }
  addDragRule({
    // 基础组件面板
    menu: "main",
    // 设计器内置字体图标
    icon: "icon-city",
    label: "省市区",
    name: "region",
    input: true,
    event: ["change"],
    validate: ["array"],
    rule() {
      return {
        // 底层就是级联选择器，填写页/内嵌/预览的渲染管线全部复用
        type: "cascader",
        field: newRegionField(),
        title: "省市区",
        info: "全国省 / 市 / 区三级联动，数据随系统内置",
        effect: { required: false },
        $required: false,
        props: {
          // __region：渲染层据此注入全国区划数据（勿删）
          __region: true,
          placeholder: "选择省 / 市 / 区",
          clearable: true,
          props: { emitPath: true },
          options: JSON.parse(JSON.stringify(SAMPLE_OPTIONS))
        }
      };
    },
    props() {
      return [
        { type: "input", field: "placeholder", title: "占位文本" },
        { type: "switch", field: "clearable", title: "可清空" },
        { type: "switch", field: "disabled", title: "禁用" }
      ];
    }
  });

  // 「提交按钮」：拖到哪里，提交按钮就渲染到哪里。
  // 画布上的按钮只是位置标记，填写页渲染的是页面自身的提交按钮
  // （带验证码/成功文案/跳转等完整逻辑）。
  addDragRule({
    menu: "aide",
    icon: "icon-send",
    label: "提交按钮",
    name: "ofSubmit",
    mask: true,
    rule() {
      return {
        type: "elButton",
        props: {
          // __submit：渲染层据此确定提交按钮的位置（勿删）
          __submit: true,
          type: "primary",
          size: "large",
          style: { width: "100%" }
        },
        children: ["提 交"]
      };
    },
    props() {
      return [
        {
          type: "el-alert",
          field: "_ofSubmitNote",
          props: {
            title:
              "这是提交按钮的位置标记：填写页在此处渲染真正的提交按钮。删除本组件时按钮回落到表单末尾。",
            type: "info",
            closable: false,
            showIcon: true
          }
        }
      ];
    }
  });
}
