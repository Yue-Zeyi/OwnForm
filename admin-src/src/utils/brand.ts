import { reactive } from "vue";
import { setConfig } from "@/config";

/**
 * 白标品牌信息：从后端加载，作用于
 * 浏览器标题 / 侧栏 Logo 与名称 / 登录页 / 页脚版权与备案
 */
export const brand = reactive({
  sysName: "OwnForm",
  sysLogo: "",
  loginSubtitle: "自建表单收集系统",
  copyright: "",
  icp: "",
  // AI 助理走后端代理接口，Token 由服务端持有，绝不下发到浏览器
  aiApi: "/api/ai/chat",
  loaded: false
});

export async function loadBrand() {
  try {
    const res = await fetch("/api/sys/brand", { credentials: "same-origin" });
    const d = await res.json();
    if (d.code === 0 && d.data) {
      Object.assign(brand, d.data);
    }
  } catch {
    /* 拉取失败用默认值 */
  }
  applyBrand();
  brand.loaded = true;
  return brand;
}

/** 应用品牌：站点标题基数 / favicon */
export function applyBrand() {
  // pure-admin 的页面标题拼接自 getConfig().Title
  try {
    setConfig({ Title: brand.sysName });
  } catch {
    /* config 未初始化时忽略 */
  }
  // favicon
  const href = brand.sysLogo || "/favicon.ico";
  let link = document.querySelector<HTMLLinkElement>("link[rel='icon']");
  if (!link) {
    link = document.createElement("link");
    link.rel = "icon";
    document.head.appendChild(link);
  }
  if (link.href !== new URL(href, location.origin).href) {
    link.href = href;
  }
}

/** 页脚版权文案（空则给默认） */
export function copyrightText() {
  return brand.copyright || `© ${new Date().getFullYear()} ${brand.sysName}`;
}
