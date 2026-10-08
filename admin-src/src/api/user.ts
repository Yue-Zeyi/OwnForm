import { http } from "@/utils/http";

export interface LoginResult {
  username: string;
  role: string;
}

/** 登录 */
export const getLogin = (data?: object) => {
  return http.post<LoginResult, any>("/auth/login", { data });
};

/** 当前登录用户 */
export interface MeResult {
  id: number;
  username: string;
  role: string;
  lastLogin: string;
  /** 服务端下发的真实权限点，供前端按钮级权限判断 */
  permissions: string[];
  /** CSRF 令牌：写操作需通过 X-CSRF-TOKEN 头回传 */
  csrfToken: string;
}

export const getMe = () => {
  return http.get<MeResult, any>("/auth/me");
};

/** 退出登录 */
export const doLogout = () => {
  return http.post<null, any>("/auth/logout", {});
};

/** 修改密码 */
export const changePassword = (data: {
  oldPassword: string;
  newPassword: string;
}) => {
  return http.post<null, any>("/auth/password", { data });
};
