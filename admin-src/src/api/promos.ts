import { http } from "@/utils/http";

/** 域名池（表单/页面/引流的分享链接可选域名） */
export const getDomains = () =>
  http.get<{ domains: string[]; current: string }, any>("/domains");

/** 推广位列表 */
export const getPromos = (params: any) =>
  http.get<
    { list: any[]; total: number; page: number; size: number; deleted: number },
    any
  >("/promos", { params });
export const getPromo = (id: number | string) =>
  http.get<any, any>(`/promos/${id}`);
export const createPromo = (data: any) =>
  http.post<any, any>("/promos", { data });
export const updatePromo = (id: number | string, data: any) =>
  http.put<any, any>(`/promos/${id}`, { data });
export const deletePromo = (id: number | string) =>
  http.delete<null, any>(`/promos/${id}`);
export const restorePromo = (id: number | string) =>
  http.post<any, any>(`/promos/${id}/restore`, { data: {} });
export const purgePromo = (id: number | string) =>
  http.delete<null, any>(`/promos/${id}/purge`);
export const setPromoStatus = (id: number | string, status: number) =>
  http.post<any, any>(`/promos/${id}/status`, { data: { status } });
export const getPromoStats = (id: number | string) =>
  http.get<any, any>(`/promos/${id}/stats`);
