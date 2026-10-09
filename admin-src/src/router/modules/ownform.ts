const Layout = () => import("@/layout/index.vue");

/*
 * 关于「只有一个页面的菜单」如何扁平为一级菜单：
 *
 * SidebarItem.vue 的 hasOneShowingChild() 规定——
 * 当子菜单的 meta.showParent 为 false 且只有一个可见子项时，
 * 该子项会被提升为一级菜单直接渲染，不再出现可展开的父级。
 * 因此这类菜单仍然保留 Layout 作为路由外壳（页面需要顶栏与侧边栏），
 * 只把子路由的 showParent 改为 false，菜单上就只看到一级条目。
 *
 * 直接删掉 children 会导致页面失去 Layout 而渲染成裸页面。
 */
export default [
  {
    /*
     * 数据概览：根容器（path "/"）是框架路由树的锚点，
     * framework 依赖它把其余菜单收进 children，不能删。
     * 这里只把唯一的子项 showParent 置为 false，
     * 让它在侧边栏直接显示为一级菜单而非可展开的父级。
     */
    path: "/",
    name: "Home",
    component: Layout,
    redirect: "/welcome",
    meta: {
      title: "数据概览",
      icon: "ep/odometer",
      // 根容器必须保留在菜单树中：filterTree 会整棵移除 showLink:false
      // 的子树，若这里设为 false，其唯一子项也会一起消失。
      // 真正的扁平化由子项的 showParent:false 完成。
      showLink: true,
      rank: 0
    },
    children: [
      {
        path: "/welcome",
        name: "Welcome",
        component: () => import("@/views/welcome/index.vue"),
        meta: {
          title: "数据概览",
          icon: "ep/odometer",
          showLink: true,
          showParent: false
        }
      }
    ]
  },
  {
    /* 用户管理：只有一个页面，showParent:false 使其成为一级菜单 */
    path: "/users",
    name: "Users",
    component: Layout,
    redirect: "/users/index",
    meta: {
      title: "用户管理",
      icon: "ep/user",
      roles: ["admin"],
      showLink: true,
      rank: 2
    },
    children: [
      {
        path: "/users/index",
        name: "UsersList",
        component: () => import("@/views/users/index.vue"),
        meta: {
          title: "用户管理",
          icon: "ep/user",
          roles: ["admin"],
          showParent: false
        }
      }
    ]
  },

  {
    path: "/forms",
    name: "Forms",
    component: Layout,
    redirect: "/forms/index",
    meta: {
      title: "表单管理",
      icon: "ep/tickets",
      showLink: true,
      rank: 3
    },
    children: [
      {
        /*
         * 表单管理：四个子路由中只有列表页 showLink 为 true，
         * 数据 / 统计 / 详情是进入具体表单后的页面，不该占菜单位。
         * showParent:false 让这个唯一的可见子项直接显示为一级菜单。
         */
        path: "/forms/index",
        name: "FormsList",
        component: () => import("@/views/forms/index.vue"),
        meta: {
          title: "表单管理",
          icon: "ep/tickets",
          showLink: true,
          showParent: false
        }
      },
      {
        /* 表单数据：跨表单聚合提交列表（支持按表单/关键词/日期筛选） */
        path: "/forms/data",
        name: "FormDataAll",
        component: () => import("@/views/data/all.vue"),
        meta: {
          title: "表单数据",
          rank: 3.05,
          icon: "ep/document",
          showLink: true,
          showParent: true
        }
      },
      {
        path: "/forms/data/:id",
        name: "FormData",
        component: () => import("@/views/data/index.vue"),
        meta: {
          title: "数据管理",
          rank: 3.1,
          icon: "ep/document",
          showLink: false,
          showParent: true,
          keepAlive: false,
          parents: [{ title: "表单管理", path: "/forms/index" }]
        }
      },
      {
        path: "/forms/data/:formId/:id",
        name: "SubmissionDetail",
        component: () => import("@/views/data/detail.vue"),
        meta: {
          title: "提交详情",
          rank: 3.2,
          showLink: false,
          showParent: true,
          keepAlive: false,
          // formId 必须由当前路由参数动态解析，不可硬编码：
          // 写死某个 id 会让所有表单的详情页面包屑都指向那一个表单
          getParents: ({ to }: any) => [
            { title: "表单管理", path: "/forms/index" },
            {
              title: "数据管理",
              path: `/forms/data/${to?.params?.formId ?? ""}`
            }
          ]
        }
      },
      {
        path: "/orders/index",
        name: "Orders",
        component: () => import("@/views/orders/index.vue"),
        meta: {
          title: "订单管理",
          rank: 3.05,
          icon: "ep/money",
          showLink: true,
          showParent: false,
          parents: [{ title: "表单管理", path: "/forms/index" }]
        }
      },
      {
        path: "/forms/stats/:id",
        name: "FormStats",
        component: () => import("@/views/stats/index.vue"),
        meta: {
          title: "统计分析",
          rank: 3.3,
          icon: "ep/data-analysis",
          showLink: false,
          showParent: true,
          parents: [{ title: "表单管理", path: "/forms/index" }]
        }
      }
    ]
  },
  {
    /*
     * 页面管理：只有一个页面，showParent:false 使其成为一级菜单。
     * rank 4 使其紧跟在「表单管理」(3) 之后。
     */
    path: "/pages",
    name: "Pages",
    component: Layout,
    redirect: "/pages/index",
    meta: {
      title: "页面管理",
      icon: "ep/document",
      // 不设 roles：与表单管理一致，admin 与 member 都能进入。
      // 数据隔离在服务端完成（PageApi::pageQuery() 对非管理员
      // 强制附加 user_id 条件），成员只能看到并管理自己创建的页面。
      showLink: true,
      rank: 4
    },
    children: [
      {
        path: "/pages/index",
        name: "PagesList",
        component: () => import("@/views/pages/index.vue"),
        meta: {
          title: "页面管理",
          icon: "ep/document",
          showParent: false
        }
      }
    ]
  },

  {
    /*
     * 引流中心：活码 / 短链 / 渠道统计。
     * rank 5 使其紧跟在「页面管理」(4) 之后。
     */
    path: "/promos",
    name: "Promos",
    component: Layout,
    redirect: "/promos/index",
    meta: {
      title: "引流中心",
      icon: "ep/promotion",
      showLink: true,
      rank: 5
    },
    children: [
      {
        path: "/promos/index",
        name: "PromosList",
        component: () => import("@/views/promos/index.vue"),
        meta: {
          title: "引流中心",
          icon: "ep/promotion",
          showParent: false
        }
      }
    ]
  },
  {
    /* 附件管理：只有一个页面，showParent:false 使其成为一级菜单 */
    path: "/uploads",
    name: "Uploads",
    component: Layout,
    redirect: "/uploads/index",
    meta: {
      title: "附件管理",
      icon: "ep/folder-opened",
      showLink: true,
      rank: 6
    },
    children: [
      {
        path: "/uploads/index",
        name: "UploadsList",
        component: () => import("@/views/uploads/index.vue"),
        meta: {
          title: "附件管理",
          icon: "ep/folder-opened",
          showParent: false
        }
      }
    ]
  },
  {
    path: "/logs",
    name: "Logs",
    component: Layout,
    redirect: "/logs/fill",
    meta: {
      title: "日志管理",
      icon: "ep/list",
      roles: ["admin"],
      showLink: true,
      rank: 7
    },
    children: [
      {
        path: "/logs/fill",
        name: "FillLogsList",
        component: () => import("@/views/logs/fill.vue"),
        meta: {
          title: "提交日志",
          rank: 7.1,
          icon: "ep/document",
          roles: ["admin"],
          showLink: true,
          showParent: true
        }
      },
      {
        path: "/logs/operate",
        name: "LogsList",
        component: () => import("@/views/logs/operate.vue"),
        meta: {
          title: "操作日志",
          rank: 7.2,
          icon: "ep/list",
          roles: ["admin"],
          showLink: true,
          showParent: true
        }
      }
    ]
  },
  {
    path: "/settings",
    name: "Settings",
    component: Layout,
    redirect: "/settings/index",
    meta: {
      title: "系统设置",
      icon: "ep/setting",
      roles: ["admin"],
      showLink: true,
      rank: 8
    },
    children: [
      {
        path: "/settings/index",
        name: "SettingsPage",
        component: () => import("@/views/settings/index.vue"),
        meta: {
          title: "系统设置",
          rank: 8.1,
          icon: "ep/setting",
          roles: ["admin"],
          showLink: true,
          showParent: true
        }
      },
      {
        path: "/about/index",
        name: "AboutPage",
        component: () => import("@/views/about/index.vue"),
        meta: {
          title: "关于系统",
          rank: 8.2,
          icon: "ep/info-filled",
          showLink: true,
          showParent: true
        }
      }
    ]
  }
] satisfies Array<RouteConfigsTable>;
