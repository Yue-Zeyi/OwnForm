// 这里存放本地图标，在 src/layout/index.vue 文件中加载，避免在首启动加载
import { getSvgInfo } from "@pureadmin/utils";
import { addIcon } from "@iconify/vue/dist/offline";

// https://icon-sets.iconify.design/ep/?keyword=ep
import EpHomeFilled from "~icons/ep/home-filled?raw";

// https://icon-sets.iconify.design/ri/?keyword=ri
import RiSearchLine from "~icons/ri/search-line?raw";
import RiInformationLine from "~icons/ri/information-line?raw";

// 菜单/面包屑用到的 Element Plus 图标统一构建期内联（~icons?raw），
// 运行时不再依赖 iconify 在线 API（内网/网络不稳时菜单图标不缺失）
import EpOdometer from "~icons/ep/odometer?raw";
import EpUser from "~icons/ep/user?raw";
import EpTickets from "~icons/ep/tickets?raw";
import EpDocument from "~icons/ep/document?raw";
import EpMoney from "~icons/ep/money?raw";
import EpDataAnalysis from "~icons/ep/data-analysis?raw";
import EpPromotion from "~icons/ep/promotion?raw";
import EpFolderOpened from "~icons/ep/folder-opened?raw";
import EpList from "~icons/ep/list?raw";
import EpSetting from "~icons/ep/setting?raw";
import EpInfoFilled from "~icons/ep/info-filled?raw";

const icons = [
  // Element Plus Icon: https://github.com/element-plus/element-plus-icons
  ["ep/home-filled", EpHomeFilled],
  ["ep/odometer", EpOdometer],
  ["ep/user", EpUser],
  ["ep/tickets", EpTickets],
  ["ep/document", EpDocument],
  ["ep/money", EpMoney],
  ["ep/data-analysis", EpDataAnalysis],
  ["ep/promotion", EpPromotion],
  ["ep/folder-opened", EpFolderOpened],
  ["ep/list", EpList],
  ["ep/setting", EpSetting],
  ["ep/info-filled", EpInfoFilled],
  // Remix Icon: https://github.com/Remix-Design/RemixIcon
  ["ri/search-line", RiSearchLine],
  ["ri/information-line", RiInformationLine]
];

// 本地菜单图标，后端在路由的 icon 中返回对应的图标字符串并且前端在此处使用 addIcon 添加即可渲染菜单图标
icons.forEach(([name, icon]) => {
  addIcon(name as string, getSvgInfo(icon as string));
});
