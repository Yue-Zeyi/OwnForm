/*!
 * OwnForm 访客端表单渲染共享模块
 *
 * 同时服务于两个场景：
 *   1. 独立填写页  /s/{slug}      —— 整页就是一个表单
 *   2. 页面内嵌表单 /p/{slug}      —— 表单嵌在页面正文中间
 *
 * 之所以抽出共享文件：表单的挂载、验证码、访问密码、限一次、
 * 提交等逻辑有两百多行，若各写一份必然出现行为漂移
 * （例如只改了填写页、忘了内嵌表单的提交去重）。
 *
 * 暴露为一个 Vue mixin，宿主组件只需提供 state 与模板插槽。
 */
(function (global) {
  'use strict';

  var ElMessage = global.ElementPlus && global.ElementPlus.ElMessage;

  /** 全国省市区数据懒加载：约 150KB 静态文件，仅在表单含省市区级联时请求 */
  function loadRegionData() {
    if (!regionLoader) {
      regionLoader = fetch('/static/vendor/region-data.json')
        .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error(r.status)); })
        .catch(function () { regionLoader = null; return []; });
    }
    return regionLoader;
  }

  /**
   * 把 form-create 规则调整为可直接渲染的形态
   *
   * 四处兼容处理（与 admin-src/src/utils/formRule.ts 保持一致）：
   *   1. designer 3.5 产出的 upload.action 是占位值，改为本地上传接口
   *   2. element-plus 上传成功后 file.url 仍是 blob 预览地址，需写回服务端地址
   *   3. form-create 独立版没有 textarea 类型，转为 input + props.type
   *   4. 省市区级联（props.__region）注入区划数据（regionData 须已就位）
   */
  function patchRule(rule, formId, regionData) {
    var deep = JSON.parse(JSON.stringify(rule || []));
    // 提示信息节点：渲染在输入框正下方
    var hintNode = function (text) {
      return {
        type: 'div',
        class: 'of-field-hint',
        style: { color: '#909399', fontSize: '12px', lineHeight: '1.5', margin: '-14px 0 14px' },
        children: ['\u2139 ' + text]
      };
    };
    (function walk(nodes) {
      // 倒序遍历：可在当前字段后原地插入提示节点
      for (var i = (nodes || []).length - 1; i >= 0; i--) {
        var n = nodes[i];
        if (!n || typeof n !== 'object') continue;
        if (n.type === 'upload') {
          n.props = n.props || {};
          // 访客上传地址无条件覆盖：表单定义里的历史占位值（如 '/'）会导致
          // 文件被 POST 到页面地址而 302 进后台
          n.props.action = '/api/upload' + (formId ? '?formId=' + formId : '');
          n.props.name = n.props.name || 'file';
          n.props.headers = Object.assign({}, n.props.headers || {}, csrfHeaders());
          if (!n.props.onSuccess) {
            n.props.onSuccess = function (res, file) {
              var url = res && res.data && res.data.url;
              if (url && file) file.url = url;
              else if (file) file.status = 'fail'; // 响应里没有有效地址（被网关/拦截器吞掉）→ 界面按失败处理
            };
          }
        }
        if (n.type === 'textarea') {
          n.type = 'input';
          n.props = n.props || {};
          n.props.type = 'textarea';
        }
        // 提示信息：注入到输入框正下方的提示节点
        if (n.field && n.info) {
          var text = n.info;
          n.info = '';
          nodes.splice(i + 1, 0, hintNode(text));
        }
        if (n.type === 'cascader' && n.props && n.props.__region) {
          n.props.props = Object.assign({ emitPath: true }, n.props.props || {});
          n.props.options = Array.isArray(regionData) ? regionData : [];
        }
        if (Array.isArray(n.children)) walk(n.children);
        if (n.props && Array.isArray(n.props.rule)) walk(n.props.rule);
      }
    })(deep);
    return deep;
  }

  /* ---------- 字段图标与尾部辅助组件的共享助手（与填写页保持一致） ---------- */
  var ICON_CACHE = {};
  function iconComp(rule) {
    var name = rule ? (rule.props && rule.props.__icon) || rule.__icon : '';
    if (!name || typeof name !== 'string') return null;
    if (name in ICON_CACHE) return ICON_CACHE[name];
    var lib = global.ElementPlusIconsVue || {};
    ICON_CACHE[name] = lib[name] || null;
    return ICON_CACHE[name];
  }
  function titleText(rule) {
    if (!rule) return '';
    if (typeof rule.title === 'string') return rule.title;
    return (rule.title && rule.title.title) || '';
  }
  function splitTail(rule) {
    var LAYOUT = ['row', 'col', 'card', 'table', 'grid', 'tabs', 'collapse', 'collapseitem',
      'subform', 'group', 'tableform', 'el-row', 'el-col'];
    var main = (rule || []).slice();
    var tail = [];
    while (main.length) {
      var last = main[main.length - 1];
      var type = String((last && last.type) || '').toLowerCase();
      if (last && !last.field && LAYOUT.indexOf(type) === -1) {
        tail.unshift(main.pop());
      } else break;
    }
    return [main, tail];
  }
  /* 提交按钮位置切分：props.__submit 标记前=主表单、标记后=按钮下方内容；
     无标记时按钮固定在最后（末尾纯展示组件排到按钮下） */
  function splitBySubmit(rule) {
    var idx = -1;
    (rule || []).forEach(function (n, i) {
      if (idx === -1 && n && n.props && n.props.__submit) idx = i;
    });
    if (idx === -1) {
      var parts = splitTail(rule);
      return { before: parts[0], after: parts[1], hasMarker: false };
    }
    return { before: rule.slice(0, idx), after: rule.slice(idx + 1), hasMarker: true };
  }
  /* 后台登录态下测试自己表单：上传带上后台留在 Cookie 里的 CSRF 令牌，
     兼容仍在运行旧版中间件（按登录态校验）的服务器；访客无此 Cookie，不受影响 */
  function csrfHeaders() {
    var m = document.cookie.match(/(?:^|;\s*)csrf-token=([^;]+)/);
    if (!m) return {};
    try { return { 'X-CSRF-TOKEN': decodeURIComponent(m[1]) }; } catch (e) { return {}; }
  }

  var TITLE_SLOT = `
    <template #title="{ rule }">
      <component v-if="iconComp(rule)" :is="iconComp(rule)"
                 style="vertical-align:-2.5px; margin-right:4px; width:1em; height:1em" />
      <span>{{ titleText(rule) }}</span>
    </template>
  `;

  /**
   * 表单渲染与提交的通用实现
   *
   * 宿主需提供：
   *   formSlug     表单 slug（提交与验证码接口用它）
   *   form         后端下发的表单定义（fields / formOption / captcha ...）
   *   onSubmitted  提交成功回调（宿主可在此做跳转）
   */
  var FormEmbedMixin = {
    data: function () {
      return {
        fApi: null,
        submitting: false,
        honeypot: '',
        // 访客输入的表单访问密码（needPassword 为 true 时由宿主模板提供输入框）
        embedPassword: '',
        // 提交验证：点击提交按钮时以弹窗触发（不再常驻表单主体）
        captchaOpen: false,
        pendingSubmit: false,
        captchaType: 'none',
        captchaImg: '',
        captchaToken: '',
        captchaCode: '',
        smsCode: '',
        smsWait: 0,
        smsTimer: null,
        smsSending: false,
        geetCode: null,
        geetestObj: null,
        successText: '提交成功，感谢您的填写！'
      };
    },

    mounted: function () {
      // 极验脚本预加载；图形验证码在弹窗打开时再获取
      if (this.captchaType === 'geetest') this.initGeetest();
    },

    beforeUnmount: function () {
      // 必须清理：页面可能同时内嵌多个表单，残留定时器会持续触发
      if (this.smsTimer) {
        clearInterval(this.smsTimer);
        this.smsTimer = null;
      }
    },

    methods: {
      /** 图像验证码 */
      refreshCaptcha: function () {
        var self = this;
        self.captchaCode = '';
        fetch('/api/captcha', { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d.code === 0) {
              self.captchaImg = d.data.img;
              self.captchaToken = d.data.token;
            }
          })
          .catch(function () { /* 网络异常时保持空态，用户可刷新重试 */ });
      },

      /** 极验人机验证 */
      initGeetest: function () {
        var self = this;
        var captchaId = (this.form && this.form.geetestCaptchaId) || '';
        if (!captchaId) return;
        var init = function () {
          if (!global.initGeetest4) return setTimeout(init, 300);
          global.initGeetest4(
            { captchaId: captchaId, product: 'bind', language: 'z-cn' },
            function (obj) {
              self.geetestObj = obj;
              // 验证通过后自动续提交（pendingSubmit 在点击提交按钮时置位）
              obj.onSuccess(function () {
                var r = obj.getValidate();
                if (r) {
                  self.geetCode = {
                    lot_number: r.lot_number,
                    captcha_output: r.captcha_output,
                    pass_token: r.pass_token,
                    gen_time: r.gen_time
                  };
                }
                if (self.pendingSubmit) {
                  self.pendingSubmit = false;
                  self.doSubmit();
                }
              });
              obj.onClose(function () {
                self.geetestObj = obj;
                self.pendingSubmit = false;
              });
            }
          );
        };
        // 已注入过则直接复用，避免重复加载第三方脚本
        if (global.__ofGeetestLoaded) {
          init();
          return;
        }
        var s = document.createElement('script');
        s.src = 'https://static.geetest.com/v4/gt4.js';
        s.onload = function () { global.__ofGeetestLoaded = true; init(); };
        s.onerror = function () { global.__ofGeetestLoaded = false; };
        document.head.appendChild(s);
      },

      /** 发送短信验证码 */
      sendSms: function () {
        var self = this;
        if (self.smsSending || self.smsWait > 0) return;
        if (!self.fApi) return;
        var phoneField = self.form.smsPhoneField;
        if (!phoneField) return ElMessage.error('表单未配置手机号字段');
        var fd = self.fApi.formData();
        var phone = String(fd[phoneField] || '').trim();
        if (!/^1[3-9]\d{9}$/.test(phone)) {
          return ElMessage.error('请先在表单中填写正确的手机号');
        }
        self.smsSending = true;
        fetch('/api/fill/' + encodeURIComponent(self.formSlug) + '/sms-code', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ phone: phone })
        })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d.code !== 0) throw new Error(d.msg || '发送失败');
            ElMessage.success(d.msg || '验证码已发送');
            self.smsWait = (d.data && d.data.wait) || 60;
            if (self.smsTimer) clearInterval(self.smsTimer);
            self.smsTimer = setInterval(function () {
              self.smsWait--;
              if (self.smsWait <= 0) {
                clearInterval(self.smsTimer);
                self.smsTimer = null;
              }
            }, 1000);
          })
          .catch(function (e) { ElMessage.error(e.message || '发送失败'); })
          .finally(function () { self.smsSending = false; });
      },

      /** 合并主表单与提交按钮后表单实例的字段值 */
      allFormData: function () {
        var fd = this.fApi ? this.fApi.formData() : {};
        if (this.fApiTail) Object.assign(fd, this.fApiTail.formData());
        return fd;
      },

      /** 省市区数据：挂载表单前预取（异步后补 options 不会触发表单重渲染） */
      loadRegionOptions: function () {
        var self = this;
        return loadRegionData().then(function (data) {
          self._regionData = Array.isArray(data) ? data : [];
          return self._regionData;
        });
      },

      /** 挂载 form-create 到容器（返回 Promise<boolean>） */
      mountForm: function (container) {
        var self = this;
        var rule = (this.form && this.form.fields) || [];
        if (!rule.length) return Promise.resolve(false);

        // 省市区级联需先取到区划数据再挂载
        var ready = String(JSON.stringify(rule)).indexOf('__region') !== -1
          ? this.loadRegionOptions()
          : Promise.resolve();

        return ready.then(function () {
          container.innerHTML = '';
          // 页面内嵌场景后端下发的主键是 formId（非 id），两者兜底兼容
          var patched = patchRule(rule, self.form.formId || self.form.id, self._regionData);
          // 提交按钮位置切分（可由「提交按钮」辅助组件拖拽指定）
          self.fApiTail = null;
          var parts = splitBySubmit(patched);

          var app = global.Vue.createApp({
            setup: function () {
              var fApi = global.Vue.shallowRef(null);
              global.Vue.watch(fApi, function (v) { self.fApi = v || null; });
              var opt = Object.assign({}, self.form.formOption || {});
              // 统一使用宿主的提交按钮，禁用内置按钮
              opt.submitBtn = false;
              opt.resetBtn = false;
              opt.form = Object.assign({}, opt.form || {});
              if (global.innerWidth < 640) {
                opt.form.labelPosition = 'top';
                delete opt.form.labelWidth;
              } else if (!opt.form.labelWidth) {
                opt.form.labelWidth = 'auto';
              }
              return { fApi: fApi, rule: parts.before, option: opt, iconComp: iconComp, titleText: titleText };
            },
            template: '<form-create v-model:api="fApi" :rule="rule" :option="option">' + TITLE_SLOT + '</form-create>'
          });
          app.use(global.ElementPlus, { locale: global.ElementPlusLocaleZhCn });
          app.use(global.formCreate);
          app.mount(container);
          // 记录 app 引用，卸载时销毁，避免内存泄漏
          self.__fcApp = app;
          // 辅助组件区：插到宿主提交按钮之后
          if (parts.after.length) {
            var box = document.createElement('div');
            box.className = 'ef-tail';
            var root = self.$el;
            var actions = root && root.querySelector ? root.querySelector('.ef-actions') : null;
            if (actions && actions.parentElement) actions.parentElement.insertBefore(box, actions.nextSibling);
            else if (root) root.appendChild(box);
            var tailApp = global.Vue.createApp({
              setup: function () {
                var fApiRef = global.Vue.shallowRef(null);
                global.Vue.watch(fApiRef, function (v) { self.fApiTail = v || null; });
                var opt = Object.assign({}, self.form.formOption || {});
                opt.submitBtn = false;
                opt.resetBtn = false;
                opt.form = Object.assign({}, opt.form || {});
                if (global.innerWidth < 640) { opt.form.labelPosition = 'top'; delete opt.form.labelWidth; }
                return { fApi: fApiRef, rule: parts.after, option: opt, iconComp: iconComp, titleText: titleText };
              },
              template: '<form-create v-model:api="fApi" :rule="rule" :option="option">' + TITLE_SLOT + '</form-create>'
            });
            tailApp.use(global.ElementPlus, { locale: global.ElementPlusLocaleZhCn });
            tailApp.use(global.formCreate);
            tailApp.mount(box);
            self.__fcTailApp = tailApp;
          }
          return true;
        });
      },

      /**
       * 提交入口：先校验表单字段，再按验证类型触发——
       *   图码/短信 → 弹窗内完成；极验 → 直接拉起自带浮层；
       *   无验证 → 直接提交
       */
      submit: function () {
        var self = this;
        if (!self.fApi || self.submitting) return;

        self.fApi.validate().then(function () {
          var tailCheck = self.fApiTail ? self.fApiTail.validate() : Promise.resolve();
          return tailCheck;
        }).then(function () {
          if (self.form.needPassword && !String(self.embedPassword || '').trim()) {
            return ElMessage.warning('请输入访问密码');
          }
          if (self.captchaType === 'none') return self.doSubmit();
          if (self.captchaType === 'geetest') {
            if (self.geetCode) return self.doSubmit();
            if (!self.geetestObj) {
              return ElMessage.error('人机验证组件加载中，请稍后重试');
            }
            self.pendingSubmit = true;
            self.geetestObj.verify();
            return;
          }
          if (self.captchaType === 'image' && !self.captchaImg) {
            self.refreshCaptcha();
          }
          self.captchaOpen = true;
        }).catch(function () {
          ElMessage.error('请完善表单必填项');
        });
      },

      /** 短信验证码目标号码（脱敏展示） */
      smsPhoneMasked: function () {
        if (!this.fApi || !this.form.smsPhoneField) return '';
        var fd = this.fApi.formData();
        var p = String(fd[this.form.smsPhoneField] || '').trim();
        if (!/^1[3-9]\d{9}$/.test(p)) return p;
        return p.slice(0, 3) + '****' + p.slice(7);
      },

      /** 实际发起提交 */
      doSubmit: function () {
        var self = this;
        if (!self.fApi) return Promise.resolve();

        if (self.captchaType === 'image' && !self.captchaCode.trim()) {
          return Promise.resolve(ElMessage.error('请输入图形验证码'));
        }
        if (self.captchaType === 'sms' && !self.smsCode.trim()) {
          return Promise.resolve(ElMessage.error('请输入短信验证码'));
        }
        if (self.captchaType === 'geetest' && !self.geetCode) {
          return Promise.resolve(ElMessage.error('请先完成人机验证'));
        }

        var fd = self.allFormData();
        // 上传失败时表单值里会残留 blob: 本地预览地址，提交前拦截并提示重传
        var badUploads = Object.keys(fd).some(function (k) {
          return Array.isArray(fd[k]) && fd[k].some(function (x) {
            return typeof x === 'string' && x.indexOf('blob:') === 0;
          });
        });
        if (badUploads) return ElMessage.error('有文件未上传成功，请删除后重新上传');
        var payload = { form: fd, __hp: self.honeypot };
        // 访客在嵌入组件里输入的访问密码（后端从不下发密码明文）
        if (self.form.needPassword) payload.__password = self.embedPassword;
        if (self.captchaType === 'image') {
          payload.__captcha_token = self.captchaToken;
          payload.__captcha_code = self.captchaCode;
        } else if (self.captchaType === 'sms') {
          payload.__sms_code = self.smsCode;
        } else if (self.captchaType === 'geetest') {
          payload.__geetest = self.geetCode;
        }

        self.submitting = true;
        return fetch('/api/fill/' + encodeURIComponent(self.formSlug) + '/submit', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify(payload)
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.code !== 0) {
              if (data.code === 4008) {
                // 付费表单：内嵌环境无法完成支付，引导到独立填写页
                ElMessage.error('该表单为付费表单，请打开独立页面完成填写与支付');
                setTimeout(function () {
                  window.open('/s/' + self.formSlug, '_blank');
                }, 1200);
                return;
              }
              ElMessage.error(data.msg || '提交失败');
              if (data.code === 4005) {
                // 重复提交：按成功处理，避免访客反复看到错误
                self.captchaOpen = false;
                self.submitted = true;
                self.successText = data.msg || '您已提交过';
                if (self.onSubmitted) self.onSubmitted();
              } else if (data.code === 4006) {
                // 验证码错误：弹窗保持打开，图码自动刷新供重试
                if (self.captchaType === 'image') self.refreshCaptcha();
                if (self.captchaType === 'geetest' && self.geetestObj) {
                  self.geetestObj.reset();
                  self.pendingSubmit = false;
                }
              }
              return;
            }
            self.captchaOpen = false;
            self.submitted = true;
            self.successText = data.msg || '感谢您的填写！';
            if (self.onSubmitted) self.onSubmitted();
          })
          .catch(function () {
            ElMessage.error('网络异常，请稍后重试');
          })
          .finally(function () { self.submitting = false; });
      }
    }
  };

  global.OwnFormFormEmbed = {
    mixin: FormEmbedMixin,
    patchRule: patchRule
  };
})(window);