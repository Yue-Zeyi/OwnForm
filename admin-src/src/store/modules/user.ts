import { defineStore } from "pinia";
import {
  type userType,
  store,
  router,
  resetRouter,
  routerArrays,
  storageLocal
} from "../utils";
import { getLogin, getMe, doLogout } from "@/api/user";
import { useMultiTagsStoreHook } from "./multiTags";
import {
  type DataInfo,
  setToken,
  removeToken,
  setCsrfToken,
  userKey
} from "@/utils/auth";

export const useUserStore = defineStore("pure-user", {
  state: (): userType => ({
    avatar: storageLocal().getItem<DataInfo<number>>(userKey)?.avatar ?? "",
    username: storageLocal().getItem<DataInfo<number>>(userKey)?.username ?? "",
    nickname: storageLocal().getItem<DataInfo<number>>(userKey)?.nickname ?? "",
    roles: storageLocal().getItem<DataInfo<number>>(userKey)?.roles ?? [],
    permissions:
      storageLocal().getItem<DataInfo<number>>(userKey)?.permissions ?? [],
    isRemembered: false,
    loginDay: 7
  }),
  actions: {
    SET_AVATAR(avatar: string) {
      this.avatar = avatar;
    },
    SET_USERNAME(username: string) {
      this.username = username;
    },
    SET_NICKNAME(nickname: string) {
      this.nickname = nickname;
    },
    SET_ROLES(roles: Array<string>) {
      this.roles = roles;
    },
    SET_PERMS(permissions: Array<string>) {
      this.permissions = permissions;
    },
    SET_ISREMEMBERED(bool: boolean) {
      this.isRemembered = bool;
    },
    SET_LOGINDAY(value: number) {
      this.loginDay = Number(value);
    },
    /** 登入（PHP Session Cookie 认证） */
    async loginByUsername(data) {
      return new Promise((resolve, reject) => {
        getLogin(data)
          .then(async (result: any) => {
            const role = result?.role === "admin" ? "admin" : "member";
            // 权限与 CSRF 令牌一律以服务端 /auth/me 为准，
            // 不在前端硬编码（早期版本固定写入 *:*:*，使按钮级权限形同虚设）
            const me = await this.refreshMe();
            setToken({
              accessToken: "session",
              refreshToken: "session",
              expires: Date.now() + 12 * 3600 * 1000,
              username: me?.username ?? result?.username ?? data.username,
              nickname: me?.username ?? result?.username ?? data.username,
              roles: [role],
              permissions: me?.permissions ?? [],
              avatar: ""
            } as unknown as DataInfo<Date>);
            resolve(result);
          })
          .catch(error => {
            reject(error);
          });
      });
    },
    /** 拉取当前用户（刷新会话角色、权限与 CSRF 令牌） */
    async refreshMe() {
      try {
        const me = await getMe();
        const role = me?.role === "admin" ? "admin" : "member";
        this.SET_USERNAME(me.username);
        this.SET_ROLES([role]);
        this.SET_PERMS(me.permissions ?? []);
        // 写回本地缓存，保证刷新页面后 hasPerms 仍能读到真实权限
        const cached = storageLocal().getItem<DataInfo<number>>(userKey);
        if (cached) {
          storageLocal().setItem(userKey, {
            ...cached,
            username: me.username,
            roles: [role],
            permissions: me.permissions ?? []
          });
        }
        if (me.csrfToken) {
          setCsrfToken(me.csrfToken);
        }
        return me;
      } catch {
        // 会话失效时静默降级，由路由守卫与响应拦截器兜底跳转登录
        return null;
      }
    },
    /** 前端登出（调用后端销毁会话） */
    logOut() {
      doLogout().catch(() => {});
      this.username = "";
      this.roles = [];
      this.permissions = [];
      removeToken();
      useMultiTagsStoreHook().handleTags("equal", [...routerArrays]);
      resetRouter();
      router.push("/login");
    },
    resetUserInfo() {
      this.username = "";
      this.roles = [];
      this.permissions = [];
    }
  }
});

export function useUserStoreHook() {
  return useUserStore(store);
}
