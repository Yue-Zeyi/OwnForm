<script setup lang="ts">
import { brand, copyrightText } from "@/utils/brand";
</script>

<template>
  <footer
    class="layout-footer text-[rgba(0,0,0,0.6)] dark:text-[rgba(220,220,242,0.8)]"
  >
    {{ copyrightText() }}
    <a
      v-if="brand.icp"
      class="hover:text-primary! ml-2"
      href="https://beian.miit.gov.cn/"
      target="_blank"
      rel="noreferrer"
    >
      {{ brand.icp }}
    </a>
  </footer>
</template>

<style scoped>
.layout-footer {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  padding: 0 0 8px;
  font-size: 13px;
}
</style>
