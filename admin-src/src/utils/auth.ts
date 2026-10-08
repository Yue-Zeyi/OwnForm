import Cookies from "js-cookie";
import { storageLocal } from "@pureadmin/utils";

export interface DataInfo<T> {
  /** 会话标记（OwnForm 使用 PHP Session Cookie，此处仅作前端会话标记） */
  accessToken: string;
  /** 过期时间戳 */
  expires: T;
  refreshToken: string;
  /** 头像 */
  avatar?: string;
  /** 用户名 */
  username?: string;
  /** 昵称 */
  nickname?: string;
  /** 当前登录用户的角色 */
  roles?: Array<string>;
  /** 当前登录用户的按钮级别权限（由服务端 /auth/me 下发） */
  permissions?: Array<string>;
}

export const userKey = "user-info";
export const TokenKey = "authorized-token";
export const multipleTabsKey = "multiple-tabs";
/** CSRF 令牌：与后端 Session 绑定，登录后由 /auth/me 下发 */
export const csrfTokenKey = "csrf-token";

/**
 * 读取会话信息
 *
 * 注意：真正的登录态由 PHP Session Cookie 决定，这里读到的只是前端缓存，
 * 仅用于首屏渲染避免闪烁。任何鉴权判断都必须以服务端结果为准。
 */
export function getToken(): DataInfo<number> {
  return storageLocal().getItem<DataInfo<number>>(userKey);
}

/**
 * 登录成功后调用：写入会话标记 cookie + 用户信息 localStorage
 * expires 传毫秒时间戳（setToken 内部会转 Date）
 */
export function setToken(data: DataInfo<Date | number>) {
  const expires = new Date(data.expires).getTime();
  const cookieString = JSON.stringify({
    accessToken: data.accessToken,
    expires,
    refreshToken: data.refreshToken
  });
  Cookies.set(TokenKey, cookieString, {
    expires:
      expires > Date.now() ? (expires - Date.now()) / 86400000 : undefined,
    // 同站发送 + HTTPS 环境下禁止明文传输
    sameSite: "lax",
    secure: location.protocol === "https:"
  });
  Cookies.set(multipleTabsKey, "true", {
    sameSite: "lax",
    secure: location.protocol === "https:"
  });
  storageLocal().setItem(userKey, {
    refreshToken: data.refreshToken,
    expires,
    avatar: data.avatar,
    username: data.username,
    nickname: data.nickname,
    roles: data.roles,
    permissions: data.permissions
  });
}

/** 清除会话 */
export function removeToken() {
  Cookies.remove(TokenKey);
  Cookies.remove(multipleTabsKey);
  removeCsrfToken();
  storageLocal().removeItem(userKey);
}

/** 读取 CSRF 令牌 */
export function getCsrfToken(): string {
  return Cookies.get(csrfTokenKey) || "";
}

/**
 * 保存 CSRF 令牌
 *
 * 与 Session Cookie 绑定，服务端在登录/会话刷新后轮换，因此不能只存 localStorage：
 * 多标签页需要同步读到最新值。
 */
export function setCsrfToken(token: string) {
  if (!token) return;
  Cookies.set(csrfTokenKey, token, {
    // 会话级 Cookie：浏览器关闭即失效，由服务端会话生命周期决定有效期
    sameSite: "lax",
    secure: location.protocol === "https:"
  });
}

export function removeCsrfToken() {
  Cookies.remove(csrfTokenKey);
}

/** 获取本地用户信息 */
export function getUserInfo(): DataInfo<number> {
  return storageLocal().getItem<DataInfo<number>>(userKey);
}

/**
 * 按钮级别权限判断
 *
 * 采用 fail-closed：入参为空或权限列表缺失一律拒绝。
 * 早期版本在入参为空时 return true，且权限被硬编码为 *:*:*，
 * 导致整套按钮级权限形同虚设。
 *
 * 再次强调：这只是展示层，真正的鉴权在服务端
 * （AdminAuth 中间件 + forbidNonAdmin + formQuery 数据隔离）。
 */
export function hasPerms(value: string | Array<string>): boolean {
  if (!value) return false;
  const perms = getUserInfo()?.permissions ?? [];
  if (!perms?.length) return false;
  if (perms.includes("*:*:*")) return true;
  const values = Array.isArray(value) ? value : [value];
  return values.some(v => perms.includes(v));
}
