// OwnForm 使用纯静态路由，无后端动态路由
export const getAsyncRoutes = () => {
  return Promise.resolve({ success: true, data: [] });
};
