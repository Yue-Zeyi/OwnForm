<script setup lang="ts">
import { isEqual } from "@pureadmin/utils";
import { useRoute, useRouter } from "vue-router";
import { ref, watch, onMounted, toRaw } from "vue";
import { getParentPaths, findRouteByPath } from "@/router/utils";
import { useMultiTagsStoreHook } from "@/store/modules/multiTags";

const route = useRoute();
const levelList = ref([]);
const router = useRouter();
const routes: any = router.options.routes;
const multiTags: any = useMultiTagsStoreHook().multiTags;

const getBreadcrumb = (): void => {
  // 当前路由信息
  let currentRoute;

  if (Object.keys(route.query).length > 0) {
    multiTags.forEach(item => {
      if (isEqual(route.query, item?.query)) {
        currentRoute = toRaw(item);
      }
    });
  } else if (Object.keys(route.params).length > 0) {
    multiTags.forEach(item => {
      if (isEqual(route.params, item?.params)) {
        currentRoute = toRaw(item);
      }
    });
  } else {
    currentRoute = findRouteByPath(router.currentRoute.value.path, routes);
  }

  // 动态参数路由（如 /forms/data/4 匹配 /forms/data/:id）在静态表中
  // 精确匹配不到，此时用 route.matched 回退构造面包屑层级
  if (!currentRoute) {
    const current = router.currentRoute.value;
    const lastMeta: any = current.meta || {};
    const chain: any[] = [];

    // 根级
    chain.push({
      path: "/",
      meta: { title: "概览", icon: "ep/odometer" },
      redirect: "/welcome"
    });

    // meta.parents / meta.getParents 定义的手动父级链。
    // getParents 用于路径中含动态 id 的页面（如 /forms/data/:formId/:id），
    // 静态 parents 无法表达，只能靠运行时参数生成。
    const manualParents: any[] =
      typeof lastMeta.getParents === "function"
        ? lastMeta.getParents({
            to: current,
            from: router.currentRoute.value
          }) || []
        : lastMeta.parents || [];
    manualParents.forEach((p: any) => {
      chain.push({ path: p.path, meta: { title: p.title } });
    });

    // 当前页
    if (lastMeta.title) {
      chain.push({ path: current.fullPath, meta: { title: lastMeta.title } });
    }

    levelList.value = chain;
    return;
  }

  // 当前路由的父级路径组成的数组
  const parentRoutes = getParentPaths(
    router.currentRoute.value.name as string,
    routes,
    "name"
  );
  // 存放组成面包屑的数组
  const matched = [];

  // 获取每个父级路径对应的路由信息
  parentRoutes.forEach(path => {
    if (path !== "/") matched.push(findRouteByPath(path, routes));
  });

  matched.push(currentRoute);

  matched.forEach((item, index) => {
    if (currentRoute?.query || currentRoute?.params) return;
    if (item?.children) {
      item.children.forEach(v => {
        if (v?.meta?.title === item?.meta?.title) {
          matched.splice(index, 1);
        }
      });
    }
  });

  levelList.value = matched.filter(
    item => item?.meta && item?.meta.title !== false
  );
};

const handleLink = item => {
  const { redirect, name, path } = item;
  if (redirect) {
    router.push(redirect as any);
  } else {
    if (name) {
      if (item.query) {
        router.push({
          name,
          query: item.query
        });
      } else if (item.params) {
        router.push({
          name,
          params: item.params
        });
      } else {
        router.push({ name });
      }
    } else {
      router.push({ path });
    }
  }
};

onMounted(() => {
  getBreadcrumb();
});

watch(
  () => route.path,
  () => {
    getBreadcrumb();
  },
  {
    deep: true
  }
);
</script>

<template>
  <el-breadcrumb class="leading-[50px]! select-none" separator="/">
    <transition-group name="breadcrumb">
      <el-breadcrumb-item
        v-for="item in levelList"
        :key="item.path"
        class="inline! items-stretch!"
      >
        <a @click.prevent="handleLink(item)">
          {{ item.meta.title }}
        </a>
      </el-breadcrumb-item>
    </transition-group>
  </el-breadcrumb>
</template>
