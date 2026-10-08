<script setup lang="ts">
import { ref, reactive, computed, onMounted } from "vue";
import { message } from "@/utils/message";
import { ElMessageBox } from "element-plus";
import { getUploads, deleteUpload, batchDeleteUploads } from "@/api/ownform";
import { Search } from "@element-plus/icons-vue";
import { copyWithTip } from "@/utils/clipboard";

defineOptions({
  name: "UploadsList"
});

const loading = ref(false);
const list = ref<any[]>([]);
const total = ref(0);
const keyword = ref("");
const query = reactive({ page: 1, size: 24 });

/* 多选批量删除 */
const selected = ref<number[]>([]);
const allChecked = computed(
  () => list.value.length > 0 && list.value.every(f => selected.value.includes(f.id))
);
function toggleAll(checked: unknown) {
  const on = checked === true || checked === "true";
  selected.value = on ? list.value.map(f => f.id) : [];
}
function toggleSel(id: number, checked: unknown) {
  const on = checked === true || checked === "true";
  const i = selected.value.indexOf(id);
  if (on && i === -1) selected.value.push(id);
  if (!on && i > -1) selected.value.splice(i, 1);
}
function clearSel() {
  selected.value = [];
}
async function batchDel() {
  if (!selected.value.length) return;
  const ok = await ElMessageBox.confirm(
    `删除选中的 ${selected.value.length} 个附件？文件将从存储中一并删除，不可恢复。`,
    "批量删除",
    { confirmButtonText: "确定删除", cancelButtonText: "取消", type: "warning" }
  )
    .then(() => true)
    .catch(() => false);
  if (!ok) return;
  try {
    const d = await batchDeleteUploads(selected.value.slice());
    message(d.deleted || "已删除", { type: "success" });
    selected.value = [];
    await load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

const isImage = (p: string) => /\.(png|jpe?g|gif|webp|bmp)$/i.test(p);
const extOf = (p: string) =>
  ((p.split(".").pop() || "FILE") as string).toUpperCase().slice(0, 5);
const fmtSize = (n: number) => {
  if (!n) return "-";
  if (n < 1024) return n + " B";
  if (n < 1048576) return (n / 1024).toFixed(1) + " KB";
  return (n / 1048576).toFixed(2) + " MB";
};

async function load(page?: number) {
  if (page) query.page = page;
  loading.value = true;
  try {
    const d = await getUploads({
      page: query.page,
      size: query.size,
      keyword: keyword.value
    });
    list.value = d.list;
    total.value = d.total;
    selected.value = [];
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  loading.value = false;
}

function copyUrl(f: any) {
  copyWithTip(location.origin + f.path, "链接已复制");
}

async function remove(f: any) {
  const ok = await ElMessageBox.confirm(
    `删除附件「${f.name}」？文件将从存储中一并删除。`,
    "提示",
    { confirmButtonText: "确定", cancelButtonText: "取消", type: "warning" }
  )
    .then(() => true)
    .catch(() => false);
  if (!ok) return;
  try {
    await deleteUpload(f.id);
    message("已删除", { type: "success" });
    load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

onMounted(() => load(1));
</script>

<template>
  <div v-loading="loading">
    <el-card shadow="never">
      <div class="filter-bar">
        <el-input
          v-model="keyword"
          placeholder="搜索文件名"
          clearable
          style="width: 220px"
          :prefix-icon="Search"
          @keyup.enter="load(1)"
          @clear="load(1)"
        />
        <el-button type="primary" @click="load(1)">查询</el-button>
      </div>

      <div v-if="selected.length" class="batch-bar">
        <span class="batch-count">已选 {{ selected.length }} 项</span>
        <el-button type="danger" size="small" @click="batchDel">
          批量删除
        </el-button>
        <el-button size="small" @click="clearSel">取消选择</el-button>
      </div>
      <div v-if="list.length" class="sel-row">
        <el-checkbox
          :model-value="allChecked"
          @change="(v: unknown) => toggleAll(v)"
        >
          全选当前页（{{ list.length }} 项）
        </el-checkbox>
      </div>
      <div v-if="list.length" class="attach-grid">
        <div v-for="f in list" :key="f.id" class="attach-item">
          <el-checkbox
            class="attach-check"
            :model-value="selected.includes(f.id)"
            @change="(v: unknown) => toggleSel(f.id, v)"
          />
          <div class="attach-thumb">
            <el-image
              v-if="isImage(f.path)"
              :src="f.path"
              :preview-src-list="[f.path]"
              fit="cover"
              style="width: 100%; height: 100%"
              preview-teleported
            />
            <div v-else class="attach-ext">{{ extOf(f.path) }}</div>
          </div>
          <div class="attach-name" :title="f.name">{{ f.name }}</div>
          <div class="attach-meta">
            <span>{{ fmtSize(f.size) }}</span>
            <span v-if="f.form_title" class="attach-form" :title="f.form_title">
              {{ f.form_title }}
            </span>
          </div>
          <div class="attach-ops">
            <el-button link type="primary" size="small" @click="copyUrl(f)">
              复制链接
            </el-button>
            <el-button link type="danger" size="small" @click="remove(f)">
              删除
            </el-button>
          </div>
        </div>
      </div>
      <el-empty v-else description="暂无附件" />

      <div v-if="total > query.size" class="pager">
        <el-pagination
          background
          layout="total, prev, pager, next"
          :total="total"
          :page-size="query.size"
          :current-page="query.page"
          @current-change="(p: number) => load(p)"
        />
      </div>
    </el-card>
  </div>
</template>

<style scoped lang="scss">
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;

  h2 {
    margin: 0;
    font-size: 18px;
  }
}

.filter-bar {
  display: flex;
  gap: 10px;
  margin-bottom: 14px;
}

.batch-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
  padding: 8px 12px;
  background: var(--el-color-danger-light-9);
  border: 1px solid var(--el-color-danger-light-5);
  border-radius: 8px;

  .batch-count {
    font-size: 13px;
    color: var(--el-text-color-primary);
  }
}

.sel-row {
  display: flex;
  align-items: center;
  margin-bottom: 10px;
  padding: 6px 10px;
  background: var(--el-fill-color-light);
  border-radius: 8px;
}

.attach-grid {

  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 14px;
}

.attach-item {
  position: relative;

  .attach-check {
    position: absolute;
    top: 6px;
    left: 6px;
    z-index: 2;
    background: rgba(255, 255, 255, 0.92);
    border-radius: 4px;
    padding: 0 2px;
  }

  border: 1px solid #ebeef5;
  border-radius: 8px;
  padding: 10px;
  transition: box-shadow 0.15s;

  &:hover {
    box-shadow: 0 4px 14px rgb(0 21 41 / 8%);
  }
}

.attach-thumb {
  width: 100%;
  height: 110px;
  border-radius: 6px;
  overflow: hidden;
  background: #f5f7fa;
  display: flex;
  align-items: center;
  justify-content: center;
}

.attach-ext {
  color: #909399;
  font-size: 20px;
  font-weight: 700;
  letter-spacing: 1px;
}

.attach-name {
  font-size: 13px;
  margin-top: 8px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.attach-meta {
  display: flex;
  justify-content: space-between;
  color: #909399;
  font-size: 12px;
  margin-top: 4px;
}

.attach-form {
  max-width: 90px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.attach-ops {
  display: flex;
  justify-content: space-between;
  margin-top: 6px;
}

.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 14px;
}
</style>
