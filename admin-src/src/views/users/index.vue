<script setup lang="ts">
import { ref, reactive, onMounted } from "vue";
import { useRouter } from "vue-router";
import { message } from "@/utils/message";
import { ElMessageBox } from "element-plus";
import {
  getUsers,
  getUserForms,
  createUser,
  updateUser,
  deleteUser
} from "@/api/ownform";
import { Plus, View } from "@element-plus/icons-vue";

defineOptions({
  name: "UsersList"
});

const router = useRouter();
const userInfo = JSON.parse(localStorage.getItem("user-info") || '{"id":0}');

const loading = ref(false);
const saving = ref(false);
const list = ref<any[]>([]);
const editVisible = ref(false);
const editId = ref(0);
const editForm = reactive<any>({
  username: "",
  nickname: "",
  role: "member",
  password: "",
  status: 1,
  canCreateForm: true
});

/* 用户详情抽屉 */
const drawerVisible = ref(false);
const drawerLoading = ref(false);
const detail = ref<any>({ user: {}, forms: [], summary: {} });

const fmtTime = (s: string) =>
  s ? String(s).replace("T", " ").slice(0, 16) : "-";

async function load() {
  loading.value = true;
  try {
    const d = await getUsers();
    list.value = d.list;
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  loading.value = false;
}

async function openDetail(row: any) {
  drawerVisible.value = true;
  drawerLoading.value = true;
  detail.value = { user: row, forms: [], summary: {} };
  try {
    detail.value = await getUserForms(row.id);
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  drawerLoading.value = false;
}

function openCreate() {
  editId.value = 0;
  Object.assign(editForm, {
    username: "",
    nickname: "",
    role: "member",
    password: "",
    status: 1,
    canCreateForm: true
  });
  editVisible.value = true;
}

function openEdit(row: any) {
  editId.value = row.id;
  Object.assign(editForm, {
    username: row.username,
    nickname: row.nickname,
    role: row.role,
    password: "",
    status: row.status,
    // permissions 为 null 表示默认全开
    canCreateForm: !row.permissions || row.permissions.includes("form:create")
  });
  editVisible.value = true;
}

async function save() {
  saving.value = true;
  try {
    // 成员功能权限：form:create 可按账号关闭；管理员恒全量不传
    const permissions =
      editForm.role === "member"
        ? editForm.canCreateForm
          ? ["form:create"]
          : []
        : undefined;
    const payload: any = { ...editForm, permissions };
    delete payload.canCreateForm;
    if (editId.value) {
      await updateUser(editId.value, payload);
    } else {
      await createUser(payload);
    }
    message("已保存", { type: "success" });
    editVisible.value = false;
    load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
  saving.value = false;
}

async function resetPwd(row: any) {
  try {
    const { value } = await ElMessageBox.prompt(
      `为「${row.username}」设置新密码（至少 6 位）：`,
      "重置密码",
      {
        confirmButtonText: "确定",
        cancelButtonText: "取消",
        inputType: "password"
      }
    );
    await updateUser(row.id, { password: value });
    message("密码已重置", { type: "success" });
  } catch (e: any) {
    if (e !== "cancel" && e?.message) message(e.message, { type: "error" });
  }
}

async function remove(row: any) {
  const ok = await ElMessageBox.confirm(
    `删除用户「${row.username}」？其创建的表单将保留。`,
    "提示",
    { confirmButtonText: "确定", cancelButtonText: "取消", type: "warning" }
  )
    .then(() => true)
    .catch(() => false);
  if (!ok) return;
  try {
    await deleteUser(row.id);
    message("已删除", { type: "success" });
    load();
  } catch (e: any) {
    message(e.message, { type: "error" });
  }
}

onMounted(() => load());
</script>

<template>
  <div v-loading="loading">
    <div class="page-head">
      <div>
        <div class="page-sub">
          管理员可管理全部表单；成员只能查看和操作自己创建的表单
        </div>
      </div>
      <el-button type="primary" :icon="Plus" @click="openCreate">
        新增用户
      </el-button>
    </div>

    <el-card shadow="never">
      <el-table :data="list" empty-text="暂无用户">
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column prop="username" label="账号" min-width="120" />
        <el-table-column prop="nickname" label="昵称" min-width="120" />
        <el-table-column label="角色" width="150">
          <template #default="{ row }">
            <el-tag
              :type="row.role === 'admin' ? 'warning' : 'info'"
              size="small"
            >
              {{ row.role === "admin" ? "管理员" : "成员" }}
            </el-tag>
            <el-tooltip
              v-if="row.role === 'member' && row.permissions && !row.permissions.includes('form:create')"
              content="该成员已被禁止创建表单"
              placement="top"
            >
              <el-tag size="small" type="danger" effect="plain" style="margin-left: 4px">
                禁建表单
              </el-tag>
            </el-tooltip>
          </template>
        </el-table-column>
        <el-table-column label="表单数" width="90" align="center">
          <template #default="{ row }">
            <el-link type="primary" :underline="false" @click="openDetail(row)">
              {{ row.form_count || 0 }}
            </el-link>
          </template>
        </el-table-column>
        <el-table-column label="数据数" width="90" align="center">
          <template #default="{ row }">
            <el-link type="primary" :underline="false" @click="openDetail(row)">
              {{ row.sub_count || 0 }}
            </el-link>
          </template>
        </el-table-column>
        <el-table-column label="待审核" width="90" align="center">
          <template #default="{ row }">
            <el-tag
              v-if="row.pending_count > 0"
              type="warning"
              size="small"
              effect="plain"
            >
              {{ row.pending_count }}
            </el-tag>
            <span v-else style="color: #c0c4cc">0</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag
              :type="row.status === 1 ? 'success' : 'danger'"
              size="small"
              effect="plain"
            >
              {{ row.status === 1 ? "正常" : "禁用" }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="最后登录" width="170">
          <template #default="{ row }">{{
            fmtTime(row.last_login_time)
          }}</template>
        </el-table-column>
        <el-table-column
          label="操作"
          width="300"
          fixed="right"
          class-name="op-nowrap"
        >
          <template #default="{ row }">
            <el-button
              link
              type="primary"
              :icon="View"
              @click="openDetail(row)"
            >
              查看
            </el-button>
            <el-button link type="primary" @click="openEdit(row)"
              >编辑</el-button
            >
            <el-button link type="warning" @click="resetPwd(row)">
              重置密码
            </el-button>
            <el-button
              link
              type="danger"
              :disabled="row.id === userInfo.id"
              @click="remove(row)"
            >
              删除
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 用户详情抽屉：统计 + 创建的表单 + 收集数据入口 -->
    <el-drawer
      v-model="drawerVisible"
      :title="
        detail.user?.username
          ? `用户详情 · ${detail.user.username}`
          : '用户详情'
      "
      size="640px"
    >
      <div v-loading="drawerLoading">
        <div class="detail-user">
          <div class="detail-name">
            {{ detail.user?.nickname || detail.user?.username }}
            <el-tag
              :type="detail.user?.role === 'admin' ? 'warning' : 'info'"
              size="small"
              style="margin-left: 8px"
            >
              {{ detail.user?.role === "admin" ? "管理员" : "成员" }}
            </el-tag>
            <el-tag
              :type="detail.user?.status === 1 ? 'success' : 'danger'"
              size="small"
              effect="plain"
              style="margin-left: 6px"
            >
              {{ detail.user?.status === 1 ? "正常" : "禁用" }}
            </el-tag>
          </div>
          <div class="detail-sub">
            最后登录：{{ fmtTime(detail.user?.last_login_time) }} · 注册于
            {{ fmtTime(detail.user?.created_at) }}
          </div>
        </div>

        <div class="detail-stats">
          <div class="stat-box">
            <div class="stat-num">{{ detail.summary?.form_count || 0 }}</div>
            <div class="stat-label">创建表单</div>
          </div>
          <div class="stat-box">
            <div class="stat-num">{{ detail.summary?.sub_count || 0 }}</div>
            <div class="stat-label">收集数据</div>
          </div>
          <div class="stat-box">
            <div class="stat-num warn">
              {{ detail.summary?.pending_count || 0 }}
            </div>
            <div class="stat-label">待审核</div>
          </div>
        </div>

        <div class="detail-forms-title">创建的表单</div>
        <el-table
          :data="detail.forms"
          empty-text="该用户还没有创建表单"
          size="small"
        >
          <el-table-column label="表单" min-width="180" show-overflow-tooltip>
            <template #default="{ row }">{{ row.title }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90" align="center">
            <template #default="{ row }">
              <el-tag
                :type="
                  row.status === 1
                    ? 'success'
                    : row.status === 2
                      ? 'warning'
                      : 'info'
                "
                size="small"
                effect="plain"
              >
                {{
                  row.status === 1
                    ? "收集中"
                    : row.status === 2
                      ? "已关闭"
                      : "草稿"
                }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="数据" width="70" align="center">
            <template #default="{ row }">{{ row.submit_count }}</template>
          </el-table-column>
          <el-table-column label="待审" width="70" align="center">
            <template #default="{ row }">
              <span :class="{ 'pending-hot': row.pending_count > 0 }">
                {{ row.pending_count }}
              </span>
            </template>
          </el-table-column>
          <el-table-column label="创建时间" width="150">
            <template #default="{ row }">{{
              fmtTime(row.created_at)
            }}</template>
          </el-table-column>
          <el-table-column label="操作" width="130" fixed="right">
            <template #default="{ row }">
              <el-button
                link
                type="primary"
                @click="router.push('/forms/data/' + row.id)"
              >
                查看数据
              </el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </el-drawer>

    <!-- 新增/编辑 -->
    <el-dialog
      v-model="editVisible"
      :title="editId ? '编辑用户' : '新增用户'"
      width="440px"
    >
      <el-form label-width="90px" label-position="left">
        <el-form-item label="账号">
          <el-input
            v-model="editForm.username"
            :disabled="!!editId"
            placeholder="3-30 位字母开头"
          />
        </el-form-item>
        <el-form-item label="昵称">
          <el-input v-model="editForm.nickname" placeholder="默认同账号" />
        </el-form-item>
        <el-form-item label="角色">
          <el-radio-group v-model="editForm.role">
            <el-radio value="member">成员（仅自己的表单）</el-radio>
            <el-radio value="admin">管理员（全部表单）</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="editForm.role === 'member'" label="功能权限">
          <el-checkbox v-model="editForm.canCreateForm">
            允许创建表单
          </el-checkbox>
        </el-form-item>
        <el-form-item :label="editId ? '重置密码' : '初始密码'">
          <el-input
            v-model="editForm.password"
            type="password"
            show-password
            :placeholder="editId ? '留空则不修改' : '至少 6 位'"
          />
        </el-form-item>
        <el-form-item v-if="editId" label="状态">
          <el-switch
            v-model="editForm.status"
            :active-value="1"
            :inactive-value="0"
            active-text="正常"
            inactive-text="禁用"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="save"
          >保存</el-button
        >
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
:deep(.op-nowrap .cell) {
  white-space: nowrap;
}

.page-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 14px;
  gap: 12px;

  h2 {
    margin: 0;
    font-size: 18px;
  }
}

.page-sub {
  color: #909399;
  font-size: 13px;
  margin-top: 6px;
}

.detail-user {
  margin-bottom: 16px;

  .detail-name {
    font-size: 16px;
    font-weight: 600;
    color: #303133;
  }

  .detail-sub {
    margin-top: 6px;
    color: #909399;
    font-size: 12px;
  }
}

.detail-stats {
  display: flex;
  gap: 12px;
  margin-bottom: 18px;

  .stat-box {
    flex: 1;
    padding: 14px 0;
    text-align: center;
    border-radius: 6px;
    background: #f5f7fa;

    .stat-num {
      font-size: 22px;
      font-weight: 600;
      color: #409eff;

      &.warn {
        color: #e6a23c;
      }
    }

    .stat-label {
      margin-top: 4px;
      color: #909399;
      font-size: 12px;
    }
  }
}

.detail-forms-title {
  margin-bottom: 10px;
  font-size: 14px;
  font-weight: 600;
  color: #303133;
}

.pending-hot {
  color: #e6a23c;
  font-weight: 600;
}
</style>
