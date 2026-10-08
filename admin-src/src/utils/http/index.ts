import Axios, {
  type AxiosInstance,
  type AxiosRequestConfig,
  type CustomParamsSerializer
} from "axios";
import type {
  PureHttpError,
  RequestMethods,
  PureHttpResponse,
  PureHttpRequestConfig
} from "./types.d";
import { stringify } from "qs";
import { removeToken, getCsrfToken } from "@/utils/auth";

// OwnForm 后端接口约定：返回 { code, msg, data }，code=0 成功
// 认证：PHP Session Cookie（same-origin 自动携带）
const defaultConfig: AxiosRequestConfig = {
  baseURL: "/api",
  timeout: 20000,
  withCredentials: true,
  headers: {
    Accept: "application/json, text/plain, */*",
    "Content-Type": "application/json",
    "X-Requested-With": "XMLHttpRequest"
  },
  paramsSerializer: {
    serialize: stringify as unknown as CustomParamsSerializer
  }
};

class PureHttp {
  /** 保存当前`Axios`实例对象 */
  private static axiosInstance: AxiosInstance = Axios.create(defaultConfig);

  /** 请求拦截 */
  private httpInterceptorsRequest(): void {
    PureHttp.axiosInstance.interceptors.request.use(
      (config: PureHttpRequestConfig): any => {
        // CSRF：后端基于 Session Cookie 认证，写操作必须回传令牌，
        // 否则恶意页面可诱导已登录管理员发起跨站请求
        const method = (config.method || "get").toUpperCase();
        if (!["GET", "HEAD", "OPTIONS"].includes(method)) {
          const token = getCsrfToken();
          if (token) {
            config.headers = config.headers || {};
            (config.headers as Record<string, string>)["X-CSRF-TOKEN"] = token;
          }
        }
        if (typeof config.beforeRequestCallback === "function") {
          config.beforeRequestCallback(config);
        }
        if (PureHttp.initConfig.beforeRequestCallback) {
          PureHttp.initConfig.beforeRequestCallback(config);
        }
        return config;
      },
      error => {
        return Promise.reject(error);
      }
    );
  }

  /** 响应拦截：解包 { code, msg, data } 信封 */
  private httpInterceptorsResponse(): void {
    const instance = PureHttp.axiosInstance;
    instance.interceptors.response.use(
      (response: PureHttpResponse) => {
        const $config = response.config;
        const body = response.data;
        // 业务信封 { code, msg, data }
        const isEnvelope =
          body &&
          typeof body === "object" &&
          typeof (body as Record<string, unknown>).code !== "undefined";

        if (isEnvelope) {
          const envelope = body as Record<string, any>;
          // 回调统一收到完整信封，与解包后的分支保持同一语义
          if (typeof $config.beforeResponseCallback === "function") {
            $config.beforeResponseCallback(response);
          }
          if (PureHttp.initConfig.beforeResponseCallback) {
            PureHttp.initConfig.beforeResponseCallback(response);
          }
          if (envelope.code === 0) {
            return envelope.data;
          }
          if (envelope.code === 401) {
            removeToken();
            if (!location.hash.startsWith("#/login")) {
              location.hash = "#/login";
            }
            return Promise.reject(new Error(envelope.msg || "请先登录"));
          }
          return Promise.reject(new Error(envelope.msg || "请求失败"));
        }

        if (typeof $config.beforeResponseCallback === "function") {
          $config.beforeResponseCallback(response);
        }
        if (PureHttp.initConfig.beforeResponseCallback) {
          PureHttp.initConfig.beforeResponseCallback(response);
        }
        return body;
      },
      (error: PureHttpError) => {
        $handleError(error);
        return Promise.reject(error);
      }
    );
  }

  /** 初始化配置对象 */
  private static initConfig: PureHttpRequestConfig = {};

  constructor() {
    this.httpInterceptorsRequest();
    this.httpInterceptorsResponse();
  }

  /** 通用请求工具函数 */
  public request<T>(
    method: RequestMethods,
    url: string,
    param?: AxiosRequestConfig,
    axiosConfig?: PureHttpRequestConfig
  ): Promise<T> {
    const config = {
      method,
      url,
      ...param,
      ...axiosConfig
    } as PureHttpRequestConfig;

    return new Promise((resolve, reject) => {
      PureHttp.axiosInstance
        .request(config)
        .then((response: undefined) => {
          resolve(response);
        })
        .catch(error => {
          reject(error);
        });
    });
  }

  /** 单独抽离的`post`工具函数 */
  public post<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T> {
    return this.request<T>("post", url, params, config);
  }

  /** 单独抽离的`get`工具函数 */
  public get<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T> {
    return this.request<T>("get", url, params, config);
  }

  /** `put`工具函数 */
  public put<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T> {
    return this.request<T>("put", url, params, config);
  }

  /** `delete`工具函数 */
  public delete<T, P>(
    url: string,
    params?: AxiosRequestConfig<P>,
    config?: PureHttpRequestConfig
  ): Promise<T> {
    return this.request<T>("delete", url, params, config);
  }
}

function $handleError(error: PureHttpError) {
  const status = error?.response?.status;
  if (status === 401) {
    removeToken();
    if (!location.hash.startsWith("#/login")) {
      location.hash = "#/login";
    }
  }
}

export const http = new PureHttp();
