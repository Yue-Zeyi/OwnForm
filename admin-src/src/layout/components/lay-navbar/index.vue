<script setup lang="ts">
import { useNav } from "@/layout/hooks/useNav";
import LaySearch from "../lay-search/index.vue";
import LayNotice from "../lay-notice/index.vue";
import LayNavMix from "../lay-sidebar/NavMix.vue";
import LaySidebarFullScreen from "../lay-sidebar/components/SidebarFullScreen.vue";
import LaySidebarBreadCrumb from "../lay-sidebar/components/SidebarBreadCrumb.vue";
import LaySidebarTopCollapse from "../lay-sidebar/components/SidebarTopCollapse.vue";

import LogoutCircleRLine from "~icons/ri/logout-circle-r-line";
import Setting from "~icons/ri/settings-3-line";
import { UserFilled, StarFilled } from "@element-plus/icons-vue";
import { useUserStoreHook } from "@/store/modules/user";
import Lock from "~icons/ri/lock-password-line";
import { ref, reactive, computed } from "vue";
import { changePassword } from "@/api/user";
import { message } from "@/utils/message";

const isAdmin = computed(() => useUserStoreHook()?.roles?.[0] === "admin");

const pwdVisible = ref(false);
const pwdSaving = ref(false);
const pwdForm = reactive({ oldPassword: "", newPassword: "", confirm: "" });

async function submitPwd() {
  if (pwdForm.newPassword.length < 6) {
    message("新密码至少 6 位", { type: "error" });
    return;
  }
  if (pwdForm.newPassword !== pwdForm.confirm) {
    message("两次输入的新密码不一致", { type: "error" });
    return;
  }
  pwdSaving.value = true;
  try {
    await changePassword({
      oldPassword: pwdForm.oldPassword,
      newPassword: pwdForm.newPassword
    });
    message("密码已修改", { type: "success" });
    pwdVisible.value = false;
    pwdForm.oldPassword = pwdForm.newPassword = pwdForm.confirm = "";
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  pwdSaving.value = false;
}

const {
  layout,
  device,
  logout,
  onPanel,
  pureApp,
  username,
  userAvatar,
  avatarsStyle,
  toggleSideBar
} = useNav();
</script>

<template>
  <div class="navbar bg-[#fff] shadow-xs shadow-[rgba(0,21,41,0.08)]">
    <LaySidebarTopCollapse
      v-if="device === 'mobile'"
      class="hamburger-container"
      :is-active="pureApp.sidebar.opened"
      @toggleClick="toggleSideBar"
    />

    <LaySidebarBreadCrumb
      v-if="layout !== 'mix' && device !== 'mobile'"
      class="breadcrumb-container"
    />

    <LayNavMix v-if="layout === 'mix'" />

    <div v-if="layout === 'vertical'" class="vertical-header-right">
      <!-- 菜单搜索 -->
      <LaySearch id="header-search" />
      <!-- 全屏 -->
      <LaySidebarFullScreen id="full-screen" />
      <!-- 消息通知 -->
      <LayNotice id="header-notice" />
      <!-- 退出登录 -->
      <el-dropdown trigger="click">
        <span class="el-dropdown-link navbar-bg-hover select-none navbar-user">
          <p v-if="username" class="dark:text-white">{{ username }}</p>
          <!--
            角色用小图标表达而不是 el-tag：
            标签块紧贴用户名的视觉噪音太大，像一个突兀的色块。
            图标 + tooltip 更轻，鼠标悬停才说明具体角色。
          -->
          <el-tooltip
            :content="
              isAdmin
                ? '管理员 · 可管理全部数据与系统设置'
                : '成员 · 仅可管理自己创建的内容'
            "
            placement="bottom"
          >
            <el-icon class="navbar-role-icon" :class="{ 'is-admin': isAdmin }">
              <StarFilled v-if="isAdmin" />
              <UserFilled v-else />
            </el-icon>
          </el-tooltip>
        </span>
        <template #dropdown>
          <el-dropdown-menu class="logout">
            <el-dropdown-item @click="pwdVisible = true">
              <IconifyIconOffline :icon="Lock" style="margin: 5px" />
              修改密码
            </el-dropdown-item>
            <el-dropdown-item divided @click="logout">
              <IconifyIconOffline
                :icon="LogoutCircleRLine"
                style="margin: 5px"
              />
              退出系统
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>

      <!-- 修改密码弹窗 -->
      <el-dialog
        v-model="pwdVisible"
        title="修改密码"
        width="420px"
        :close-on-click-modal="false"
        :append-to-body="true"
      >
        <el-form label-width="90px" label-position="left">
          <el-form-item label="原密码">
            <el-input
              v-model="pwdForm.oldPassword"
              type="password"
              show-password
            />
          </el-form-item>
          <el-form-item label="新密码">
            <el-input
              v-model="pwdForm.newPassword"
              type="password"
              show-password
              placeholder="至少 6 位"
            />
          </el-form-item>
          <el-form-item label="确认新密码">
            <el-input v-model="pwdForm.confirm" type="password" show-password />
          </el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="pwdVisible = false">取消</el-button>
          <el-button type="primary" :loading="pwdSaving" @click="submitPwd">
            保存
          </el-button>
        </template>
      </el-dialog>
      <span
        class="set-icon navbar-bg-hover"
        title="打开系统配置"
        @click="onPanel"
      >
        <IconifyIconOffline :icon="Setting" />
      </span>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.navbar {
  width: 100%;
  height: 48px;
  overflow: hidden;

  .hamburger-container {
    float: left;
    height: 100%;
    line-height: 48px;
    cursor: pointer;
  }

  .vertical-header-right {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    min-width: 280px;
    height: 48px;
    color: #000000d9;

    .el-dropdown-link {
      display: flex;
      align-items: center;
      justify-content: space-around;
      height: 48px;
      padding: 10px;
      color: #000000d9;
      cursor: pointer;

      p {
        font-size: 14px;
      }

      /* 角色图标：紧贴用户名右侧，垂直居中，悬停有 tooltip 说明 */
      .navbar-role-icon {
        font-size: 15px;
        margin-left: 5px;
        color: #909399;
        cursor: help;
        transition: color 0.2s;

        &.is-admin {
          color: #e6a23c;
        }

        &:hover {
          color: #409eff;
        }
      }

      img {
        width: 22px;
        height: 22px;
        border-radius: 50%;
      }
    }
  }

  .breadcrumb-container {
    float: left;
    margin-left: 16px;
  }
}

.logout {
  width: 120px;

  ::v-deep(.el-dropdown-menu__item) {
    display: inline-flex;
    flex-wrap: wrap;
    min-width: 100%;
  }
}
</style>

<style scoped lang="scss"></style>
