<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount } from "vue";
import { useRouter } from "vue-router";
import { http } from "@/utils/http";
import { message } from "@/utils/message";
import BellIcon from "~icons/ep/bell";

interface NoticeItem {
  id: number;
  formId: number;
  title: string;
  preview: string;
  createdAt: string;
  unread?: boolean;
}

defineOptions({
  name: "LayNotice"
});

const router = useRouter();
const noticesNum = ref(0);
/** 上次未读数：新增提交时触发桌面通知 */
let lastUnread = -1;

/** 桌面提醒授权状态：default=未询问（显示开启入口） */
const deskTip = ref(
  typeof Notification !== "undefined" && Notification.permission === "default"
);

function enableDesktop() {
  try {
    Notification.requestPermission().then(p => {
      if (p === "granted") {
        message("桌面提醒已开启", { type: "success" });
        deskTip.value = false;
      } else {
        message("浏览器未授权桌面通知，请在浏览器设置中开启", {
          type: "warning"
        });
      }
    });
  } catch (e) {
    message("当前环境不支持桌面通知", { type: "warning" });
  }
}
const activeKey = ref("notice");
const notices = ref<NoticeItem[]>([]);
const pending = ref<NoticeItem[]>([]);
const loading = ref(false);

const tabs = computed(() => [
  {
    key: "notice",
    name: `新提交${noticesNum.value > 0 ? `(${noticesNum.value})` : ""}`,
    list: notices.value,
    emptyText: "暂无新提交"
  },
  {
    key: "pending",
    name: `待审核${pending.value.length > 0 ? `(${pending.value.length})` : ""}`,
    list: pending.value,
    emptyText: "暂无待审核提交"
  }
]);

const fmtTime = (s: string) =>
  s ? String(s).replace("T", " ").slice(0, 16) : "-";

async function load(silent = true) {
  try {
    const d = await http.get<any, any>("/notice/list");
    notices.value = d.notices || [];
    pending.value = d.pending || [];
    noticesNum.value = d.unread || 0;
    // 桌面通知：未读数比上次轮询增加时弹出系统通知（已授权才弹）
    notifyDesktop(noticesNum.value);
    lastUnread = noticesNum.value;
  } catch (e) {
    if (!silent) message("通知加载失败", { type: "error" });
  }
}

function goItem(item: NoticeItem) {
  // 单条已读：本地立刻消红点，后台记录已读水位
  if (item.unread) {
    item.unread = false;
    noticesNum.value = Math.max(0, noticesNum.value - 1);
    http.post("/notice/read", { data: { id: item.id } }).catch(() => {});
  }
  router.push("/forms/data/" + item.formId);
}

async function readAll() {
  try {
    await http.post("/notice/read", { data: {} });
    notices.value = notices.value.map(n => ({ ...n, unread: false }));
    noticesNum.value = 0;
    message("已全部标记为已读", { type: "success" });
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}



function notifyDesktop(count: number) {
  if (!count || count <= lastUnread) return;
  const show = () => {
    try {
      const n = new Notification("表单收到新提交", {
        body: `有 ${count} 条提交待查看，点击进入通知中心`,
      });
      n.onclick = () => {
        window.focus();
        location.hash = "#/forms/index";
        n.close();
      };
    } catch (e) {
      /* 通知不可用时静默 */
    }
  };
  if (typeof Notification !== "undefined" && Notification.permission === "granted") {
    show();
  }
}

onMounted(() => {
  load();
  timer = window.setInterval(() => load(), 30000);
});
onBeforeUnmount(() => {
  if (timer) window.clearInterval(timer);
});
</script>

<template>
  <el-dropdown
    trigger="click"
    placement="bottom-end"
    @visible-change="(v: boolean) => v && load()"
  >
    <span
      :class="[
        'dropdown-badge',
        'navbar-bg-hover',
        'select-none',
        Number(noticesNum) !== 0 && 'mr-[10px]'
      ]"
    >
      <el-badge :value="Number(noticesNum) === 0 ? '' : noticesNum" :max="99">
        <span class="header-notice-icon">
          <IconifyIconOffline :icon="BellIcon" />
        </span>
      </el-badge>
    </span>
    <template #dropdown>
      <el-dropdown-menu class="notice-menu">
        <div class="notice-head">
          <span>通知</span>
          <span class="head-actions">
            <el-button
              v-if="deskTip"
              link
              type="primary"
              size="small"
              @click="enableDesktop"
            >
              开启桌面提醒
            </el-button>
            <el-button link type="primary" size="small" @click="readAll">
              全部已读
            </el-button>
          </span>
        </div>
        <el-tabs v-model="activeKey" :stretch="true" class="dropdown-tabs">
          <el-tab-pane
            v-for="item in tabs"
            :key="item.key"
            :label="item.name"
            :name="item.key"
          >
            <el-scrollbar max-height="330px">
              <div class="noticeList-container">
                <template v-if="item.list.length">
                  <div
                    v-for="n in item.list"
                    :key="item.key + n.id"
                    class="notice-item"
                    :class="{ unread: n.unread }"
                    @click="goItem(n)"
                  >
                    <div class="notice-item-title">
                      <span v-if="n.unread" class="unread-dot" />
                      {{ n.title }}
                    </div>
                    <div class="notice-item-preview">{{ n.preview }}</div>
                    <div class="notice-item-time">
                      {{ fmtTime(n.createdAt) }}
                    </div>
                  </div>
                </template>
                <el-empty
                  v-else
                  :description="item.emptyText"
                  :image-size="55"
                />
              </div>
            </el-scrollbar>
          </el-tab-pane>
        </el-tabs>
      </el-dropdown-menu>
    </template>
  </el-dropdown>
</template>

<style lang="scss" scoped>
.dropdown-badge {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 48px;
  cursor: pointer;

  .header-notice-icon {
    font-size: 18px;
  }
}

.notice-head {
  .head-actions {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 4px 14px 0;
}

.dropdown-tabs {
  .noticeList-container {
    padding: 10px 0 0;
  }

  :deep(.el-tabs__header) {
    margin: 0;
  }
}
</style>

<style lang="scss">
.notice-menu {
  width: 340px;

  .notice-item {
    padding: 9px 14px;
    cursor: pointer;
    border-bottom: 1px dashed #f0f0f0;

    &:hover {
      background: #f5f7fa;
    }

    &.unread .notice-item-title {
      font-weight: 600;
    }

    .unread-dot {
      display: inline-block;
      width: 7px;
      height: 7px;
      margin-right: 6px;
      border-radius: 50%;
      background: var(--el-color-danger);
      vertical-align: middle;
    }

    .notice-item-title {
      font-size: 13px;
      color: #303133;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .notice-item-preview {
      font-size: 12px;
      color: #909399;
      margin-top: 3px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .notice-item-time {
      font-size: 11px;
      color: #c0c4cc;
      margin-top: 3px;
    }
  }
}
</style>
