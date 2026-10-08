import { reactive } from "vue";
import type { FormRules } from "element-plus";

/** 登录校验（OwnForm：账号 3-30 位字母开头，密码至少 6 位） */
const loginRules = reactive<FormRules>({
  username: [
    {
      validator: (rule, value, callback) => {
        if (value === "") {
          callback(new Error("请输入账号"));
        } else {
          callback();
        }
      },
      trigger: "blur"
    }
  ],
  password: [
    {
      validator: (rule, value, callback) => {
        if (value === "") {
          callback(new Error("请输入密码"));
        } else {
          callback();
        }
      },
      trigger: "blur"
    }
  ]
});

export { loginRules };
