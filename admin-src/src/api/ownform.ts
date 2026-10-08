import { http } from "@/utils/http";

/** 表单管理 */
export const getForms = (params: any) =>
  http.get<{ list: any[]; total: number }, any>("/forms", { params });
export const getForm = (id: number | string) =>
  http.get<any, any>(`/forms/${id}`);
export const createForm = (data: any) =>
  http.post<any, any>("/forms", { data });
export const updateForm = (id: number | string, data: any) =>
  http.put<any, any>(`/forms/${id}`, { data });
export const deleteForm = (id: number | string) =>
  http.delete<null, any>(`/forms/${id}`);
export const restoreForm = (id: number | string) =>
  http.post<any, any>(`/forms/${id}/restore`, { data: {} });
export const purgeForm = (id: number | string) =>
  http.delete<null, any>(`/forms/${id}/purge`);
export const setFormStatus = (id: number | string, status: number) =>
  http.post<any, any>(`/forms/${id}/status`, { data: { status } });

/** 表单协作成员 */
export const getFormMembers = (formId: number | string) =>
  http.get<any, any>(`/forms/${formId}/members`);
export const bindFormMember = (formId: number | string, data: any) =>
  http.post<any, any>(`/forms/${formId}/members`, { data });
export const updateFormMember = (
  formId: number | string,
  memberId: number | string,
  data: any
) => http.put<any, any>(`/forms/${formId}/members/${memberId}`, { data });
export const unbindFormMember = (
  formId: number | string,
  memberId: number | string
) => http.delete<null, any>(`/forms/${formId}/members/${memberId}`);

/** 表单渠道 */
export const getChannels = (formId: number | string) =>
  http.get<any, any>(`/forms/${formId}/channels`);
export const createChannel = (formId: number | string, data: any) =>
  http.post<any, any>(`/forms/${formId}/channels`, { data });
export const updateChannel = (id: number | string, data: any) =>
  http.put<any, any>(`/channels/${id}`, { data });
export const setChannelStatus = (id: number | string, status: number) =>
  http.post<any, any>(`/channels/${id}/status`, { data: { status } });
export const deleteChannel = (id: number | string) =>
  http.delete<null, any>(`/channels/${id}`);

/** 数据管理 */
export const getSubmissions = (formId: number | string, params: any) =>
  http.get<any, any>(`/data/${formId}`, { params });
export const deleteSubmission = (
  formId: number | string,
  id: number | string
) => http.delete<null, any>(`/data/${formId}/${id}`);
export const batchDeleteSubmissions = (
  formId: number | string,
  ids: number[]
) => http.post<any, any>(`/data/${formId}/batch-delete`, { data: { ids } });
export const restoreSubmissions = (formId: number | string, ids: number[]) =>
  http.post<any, any>(`/data/${formId}/restore`, { data: { ids } });
export const purgeSubmissions = (formId: number | string, ids: number[]) =>
  http.post<any, any>(`/data/${formId}/purge`, { data: { ids } });
export const reviewSubmissions = (
  formId: number | string,
  ids: number[],
  status: number
) => http.post<any, any>(`/data/${formId}/review`, { data: { ids, status } });
export const exportUrl = (
  formId: number | string,
  keyword: string,
  start: string,
  end: string,
  extra?: {
    field?: string;
    value?: string;
    flag?: number | "";
    channel_id?: number | "";
  }
) => {
  let url = `/api/data/${formId}/export?keyword=${encodeURIComponent(keyword)}&start=${start}&end=${end}`;
  if (extra?.field) url += `&field=${encodeURIComponent(extra.field)}`;
  if (extra?.value) url += `&value=${encodeURIComponent(extra.value)}`;
  if (extra?.flag !== undefined && extra.flag !== "") {
    url += `&flag=${extra.flag}`;
  }
  if (extra?.channel_id !== undefined && extra.channel_id !== "") {
    url += `&channel_id=${extra.channel_id}`;
  }
  return url;
};

/** 单条提交详情 */
export const getSubmission = (formId: number | string, id: number | string) =>
  http.get<any, any>(`/data/${formId}/detail/${id}`);

/** 跨表单批量审核（表单数据页多选） */
export const batchReviewData = (
  items: { formId: number; id: number }[],
  status: number
) => http.post<any, any>("/data/batch-review", { data: { items, status } });

/** 跨表单聚合导出（与表单数据页同一套筛选） */
export const exportAllDataUrl = (params: Record<string, any>) => {
  const q = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== "") q.append(k, String(v));
  });
  const qs = q.toString();
  return "/api/data/export-all" + (qs ? "?" + qs : "");
};

/** 编辑提交内容（管理员/创建者） */
export const editSubmission = (
  formId: number | string,
  id: number | string,
  data: Record<string, any>
) => http.post<any, any>(`/data/${formId}/edit/${id}`, { data });

/** 跨表单批量打标旗/备注（表单数据页多选） */
export const batchMarkData = (
  items: { formId: number; id: number }[],
  flag: number,
  remark: string
) =>
  http.post<any, any>("/data/batch-mark", {
    data: { items, flag, remark }
  });

/** 附件批量删除（管理员） */
export const batchDeleteUploads = (ids: number[]) =>
  http.post<any, any>("/uploads/batch-delete", { data: { ids } });
/** 标记：旗标/备注（后端字段名为 flag，0无 1红 2橙 3黄 4绿 5蓝 6紫） */
export const markSubmission = (
  formId: number | string,
  id: number | string,
  data: { flag?: number; remark?: string }
) => http.post<any, any>(`/data/${formId}/mark/${id}`, { data });

/** 统计 */
export const getStats = (formId: number | string) =>
  http.get<any, any>(`/stats/${formId}`);

/** 跨表单提交列表（表单数据页） */
/** 表单副本：复制字段/设置为新草稿 */
export const copyFormApi = (id: number | string) =>
  http.post<any, any>(`/forms/${id}/copy`);

export const getAllData = (params: {
  formId?: number;
  keyword?: string;
  start?: string;
  end?: string;
  status?: number | string;
  page?: number;
  size?: number;
}) => http.get<any, any>("/data", { params });

/** 概览 */
export const getDashboard = () => http.get<any, any>("/dashboard");

/** 附件 */
export const getUploads = (params: any) =>
  http.get<any, any>("/uploads", { params });
export const deleteUpload = (id: number | string) =>
  http.delete<null, any>(`/uploads/${id}`);

/** 用户管理 */
export const getUsers = () => http.get<any, any>("/users");
export const getUserForms = (id: number | string) =>
  http.get<any, any>(`/users/${id}/forms`);
export const createUser = (data: any) =>
  http.post<any, any>("/users", { data });
export const updateUser = (id: number | string, data: any) =>
  http.put<any, any>(`/users/${id}`, { data });
export const deleteUser = (id: number | string) =>
  http.delete<null, any>(`/users/${id}`);

/** 系统设置 */
export const getBrand = () => http.get<any, any>("/sys/brand");
export const getAbout = () => http.get<any, any>("/sys/about");
export const getSysSettings = () => http.get<any, any>("/sys/settings");
export const saveSysSettings = (data: any) =>
  http.post<any, any>("/sys/settings", { data });
export const testNotify = () => http.post<any, any>("/sys/test-notify", { data: {} });
export const testStorage = () => http.post<any, any>("/sys/test-storage", { data: {} });

/** 手动执行数据清理：scopes=清理范围，days=各范围天数 */
export const runCleanup = (
  scopes: string[],
  days: Record<string, number>
) =>
  http.post<any, any>("/sys/cleanup", {
    data: { scopes, days }
  });
