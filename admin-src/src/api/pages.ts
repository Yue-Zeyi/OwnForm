import { http } from "@/utils/http";

/** 页面管理 */
export const getPages = (params: any) =>
  http.get<{ list: any[]; total: number; page: number; size: number }, any>(
    "/pages",
    { params }
  );
export const getPage = (id: number | string) =>
  http.get<any, any>(`/pages/${id}`);
export const createPage = (data: any) =>
  http.post<any, any>("/pages", { data });
export const updatePage = (id: number | string, data: any) =>
  http.put<any, any>(`/pages/${id}`, { data });
export const deletePage = (id: number | string) =>
  http.delete<null, any>(`/pages/${id}`);
export const restorePage = (id: number | string) =>
  http.post<any, any>(`/pages/${id}/restore`, { data: {} });
export const purgePage = (id: number | string) =>
  http.delete<null, any>(`/pages/${id}/purge`);
export const setPageStatus = (id: number | string, status: number) =>
  http.post<any, any>(`/pages/${id}/status`, { data: { status } });

/** AI 生成页面内容（复用系统设置里的 AI 助理配置） */
export const aiGeneratePage = (data: { prompt: string; current?: string }) =>
  http.post<{ html: string }, any>("/pages/ai", {
    data,
    timeout: 120000
  });
