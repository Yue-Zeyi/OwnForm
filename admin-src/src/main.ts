import App from "./App.vue";
import router from "./router";
import { setupStore } from "@/store";
import { getPlatformConfig } from "./config";
import { MotionPlugin } from "@vueuse/motion";
// import { useEcharts } from "@/plugins/echarts";
import { createApp, type Directive } from "vue";
import { useElementPlus } from "@/plugins/elementPlus";
import { injectResponsiveStorage } from "@/utils/responsive";

import Table from "@pureadmin/table";
// import PureDescriptions from "@pureadmin/descriptions";
// 设计器「省市区」基础组件注册
import { registerRegionComponent } from "./utils/designerRegion";

// 引入重置样式
import "./style/reset.scss";
// 导入公共样式
import "./style/index.scss";
// 一定要在main.ts中导入tailwind.css，防止vite每次hmr都会请求src/style/index.scss整体css文件导致热更新慢的问题
import "./style/tailwind.css";
import "element-plus/dist/index.css";
// 导入字体图标
import "./assets/iconfont/iconfont.js";
import "./assets/iconfont/iconfont.css";

const app = createApp(App);
app.config.errorHandler = (err, instance, info) => {
  // 限制条数并在超出时丢弃最旧的记录：
  // 堆栈里含内部路径与用户数据片段，无上限累积既占内存又扩大信息暴露面
  const buf: string[] = ((window as any).__vErrs =
    (window as any).__vErrs || []);
  buf.push(
    String((err as Error)?.stack || err).slice(0, 600) + " [" + info + "]"
  );
  if (buf.length > 20) buf.splice(0, buf.length - 20);
  console.error(err, info);
};

// form-create 的组件解析依赖全局 ElementPlus（UMD 时代约定）
import * as ElementPlusLib from "element-plus";
(window as any).ElementPlus = ElementPlusLib;

// 自定义指令
import * as directives from "@/directives";
Object.keys(directives).forEach(key => {
  app.directive(key, (directives as { [key: string]: Directive })[key]);
});

// 全局注册@iconify/vue图标库
import {
  IconifyIconOffline,
  IconifyIconOnline,
  FontIcon
} from "./components/ReIcon";
app.component("IconifyIconOffline", IconifyIconOffline);
app.component("IconifyIconOnline", IconifyIconOnline);
app.component("FontIcon", FontIcon);

// 全量注册 element-plus 图标集（菜单/页面所有 ep/* 图标）
import {
  addCollection,
  addIcon as addIconOffline
} from "@iconify/vue/dist/offline";
import epCollection from "@iconify/json/json/ep.json";
addCollection(epCollection as any);
// 兼容旧的按名引用
import odometerIcon from "@iconify-icons/ep/odometer";
import ticketsIcon from "@iconify-icons/ep/tickets";
import folderOpenedIcon from "@iconify-icons/ep/folder-opened";
import userIcon from "@iconify-icons/ep/user";
import settingIcon from "@iconify-icons/ep/setting";
import homeFilledIcon from "@iconify-icons/ep/home-filled";
import documentIcon from "@iconify-icons/ep/document";
import dataAnalysisIcon from "@iconify-icons/ep/data-analysis";
import videoPlayIcon from "@iconify-icons/ep/video-play";
import finishedIcon from "@iconify-icons/ep/finished";
import alarmClockIcon from "@iconify-icons/ep/alarm-clock";
import histogramIcon from "@iconify-icons/ep/histogram";
import listIcon from "@iconify-icons/ep/list";
import promotionIcon from "@iconify-icons/ep/promotion";
import infoFilledIcon from "@iconify-icons/ep/info-filled";
import monitorIcon from "@iconify-icons/ep/monitor";
[
  ["ep/odometer", odometerIcon],
  ["ep/tickets", ticketsIcon],
  ["ep/folder-opened", folderOpenedIcon],
  ["ep/user", userIcon],
  ["ep/setting", settingIcon],
  ["ep/home-filled", homeFilledIcon],
  ["ep/document", documentIcon],
  ["ep/data-analysis", dataAnalysisIcon],
  ["ep/video-play", videoPlayIcon],
  ["ep/finished", finishedIcon],
  ["ep/alarm-clock", alarmClockIcon],
  ["ep/histogram", histogramIcon],
  ["ep/list", listIcon],
  ["ep/promotion", promotionIcon],
  ["ep/info-filled", infoFilledIcon],
  ["ep/monitor", monitorIcon]
].forEach(entry => addIconOffline(entry[0] as string, entry[1] as any));

// 全局注册按钮级别权限组件
import { Auth } from "@/components/ReAuth";
import { Perms } from "@/components/RePerms";
app.component("Auth", Auth);
app.component("Perms", Perms);

// 全局注册vue-tippy
import "tippy.js/dist/tippy.css";
import "tippy.js/themes/light.css";
import VueTippy from "vue-tippy";
app.use(VueTippy);

// 先加载品牌配置（系统名/favicon），再挂载
import { loadBrand } from "@/utils/brand";

getPlatformConfig(app).then(async config => {
  await loadBrand();
  setupStore(app);
  app.use(router);
  await router.isReady();
  injectResponsiveStorage(app, config);
  app.use(MotionPlugin).use(useElementPlus).use(Table);

  // OwnForm：form-create 表单渲染与设计器
  const formCreate = (await import("@form-create/element-ui")).default;
  const FcDesigner = (await import("@form-create/designer")).default;
  const FcDesignerZhCn = (await import("@form-create/designer/locale/zh-cn"))
    .default;
  (window as any).__OF_FORMCREATE__ = formCreate;
  app.use(formCreate);
  app.use(FcDesigner);
  try {
    FcDesigner.useLocale(FcDesignerZhCn);
  } catch (e) {
    console.warn("designer locale 初始化失败", e);
  }
  try {
    registerRegionComponent(FcDesigner);
  } catch (e) {
    console.warn("省市区组件注册失败", e);
  }

  // .use(PureDescriptions)
  // .use(useEcharts);
  app.mount("#app");
});
