-- ownform 表单收集系统 数据库结构
-- 由安装向导自动执行，表前缀 of_

CREATE TABLE IF NOT EXISTS `{{prefix}}users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL COMMENT '登录名',
  `password` VARCHAR(255) NOT NULL COMMENT 'bcrypt密码',
  `nickname` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '昵称',
  `role` VARCHAR(20) NOT NULL DEFAULT 'member' COMMENT 'admin管理员/member成员',
  `permissions_json` TEXT NULL COMMENT '成员功能权限JSON数组，NULL=默认全开',
  `last_login_time` DATETIME DEFAULT NULL,
  `last_login_ip` VARCHAR(45) NOT NULL DEFAULT '',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1正常 0禁用',
  `created_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台用户';

CREATE TABLE IF NOT EXISTS `{{prefix}}settings` (
  `setting_key` VARCHAR(50) NOT NULL COMMENT '配置键',
  `setting_value` TEXT COMMENT '配置值',
  `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='全局配置';

CREATE TABLE IF NOT EXISTS `{{prefix}}forms` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '创建者，预留多用户',
  `title` VARCHAR(100) NOT NULL COMMENT '表单标题',
  `description` TEXT COMMENT '表单说明',
  `slug` VARCHAR(16) NOT NULL COMMENT '分享码 /s/{slug}',
  `fields_json` MEDIUMTEXT COMMENT '设计器字段规则JSON',
  `settings_json` MEDIUMTEXT COMMENT '提交设置JSON（含提交后跳转、关联页面）',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0草稿 1收集中 2已关闭',
  `submit_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表单';

CREATE TABLE IF NOT EXISTS `{{prefix}}form_submissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id` INT UNSIGNED NOT NULL,
  `channel_id` INT UNSIGNED NULL DEFAULT NULL COMMENT '来源渠道，NULL=直接访问',
  `data_json` MEDIUMTEXT COMMENT '{字段: 值} JSON',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
  `device` VARCHAR(10) NOT NULL DEFAULT '' COMMENT 'pc / mobile',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1正常 0待审核/隐藏',
  `flag` TINYINT NOT NULL DEFAULT 0 COMMENT '旗标：0无 1红 2橙 3黄 4绿 5蓝 6紫',
  `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` DATETIME DEFAULT NULL,
  `deleted_at` DATETIME DEFAULT NULL COMMENT '回收站：非 NULL 为已删除',
  PRIMARY KEY (`id`),
  KEY `idx_form_time` (`form_id`, `created_at`),
  KEY `idx_form_ip` (`form_id`, `ip`),
  KEY `idx_flag` (`form_id`, `flag`),
  -- 审核模式下列表按 status 过滤，缺此索引会退化为全表扫描
  KEY `idx_form_status` (`form_id`, `status`),
  -- 概览页 30 日趋势按 created_at 分组（管理员范围无 form_id 过滤）
  KEY `idx_created` (`created_at`),
  KEY `idx_form_deleted` (`form_id`, `deleted_at`),
  KEY `idx_form_channel` (`form_id`, `channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表单提交数据';

CREATE TABLE IF NOT EXISTS `{{prefix}}form_channels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '渠道名称',
  `code` VARCHAR(16) NOT NULL COMMENT '渠道码 /s/{slug}/{code}',
  `member_id` INT UNSIGNED NULL DEFAULT NULL COMMENT '归属成员ID，NULL=公共渠道',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 0停用（停用仅不再推广，不拦截提交）',
  `online_from` DATETIME NULL COMMENT '定时上线：NULL=立即',
  `online_until` DATETIME NULL COMMENT '定时下线：NULL=不限',
  `submit_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人用户ID',
  `created_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  `deleted_at` DATETIME DEFAULT NULL COMMENT '软删：链接不再归因，历史提交渠道列显示已删除渠道',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_form` (`form_id`),
  KEY `idx_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表单分发渠道';

CREATE TABLE IF NOT EXISTS `{{prefix}}form_members` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id` INT UNSIGNED NOT NULL,
  `member_id` INT UNSIGNED NOT NULL,
  `data_scope` ENUM('all','own') NOT NULL DEFAULT 'own' COMMENT 'all全部数据/own仅自己渠道数据',
  `can_channel` TINYINT NOT NULL DEFAULT 1 COMMENT '可自建渠道链接',
  `can_export` TINYINT NOT NULL DEFAULT 0 COMMENT '可导出CSV',
  `created_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_form_member` (`form_id`, `member_id`),
  KEY `idx_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表单协作成员授权';

CREATE TABLE IF NOT EXISTS `{{prefix}}uploads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联表单，0为未知',
  `path` VARCHAR(500) NOT NULL COMMENT '相对URL或云端完整地址',
  `name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '原始文件名',
  `size` INT UNSIGNED NOT NULL DEFAULT 0,
  `mime` VARCHAR(100) NOT NULL DEFAULT '',
  `created_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_form` (`form_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='上传附件';

CREATE TABLE IF NOT EXISTS `{{prefix}}logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作人ID，0为访客/系统',
  `username` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '操作人账号',
  `module` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '模块 auth/form/data/fill/upload/user/page/promo/sys/http',
  `action` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '动作',
  `detail` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '详情',
  `request` TEXT COMMENT '请求报文（脱敏截断）',
  `response` TEXT COMMENT '响应报文（脱敏截断）',
  `duration` INT NOT NULL DEFAULT 0 COMMENT '耗时ms',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1成功 0失败',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_module` (`module`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统日志';

CREATE TABLE IF NOT EXISTS `{{prefix}}form_versions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id` INT UNSIGNED NOT NULL,
  `fields_json` MEDIUMTEXT COMMENT '旧版字段规则JSON',
  `settings_json` TEXT COMMENT '旧版提交设置JSON',
  `created_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_form_created` (`form_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表单版本快照';

CREATE TABLE IF NOT EXISTS `{{prefix}}pages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '创建者',
  `title` VARCHAR(100) NOT NULL COMMENT '页面标题',
  `slug` VARCHAR(16) NOT NULL COMMENT '分享码 /p/{slug}',
  `description` TEXT COMMENT '页面摘要',
  `cover` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '封面图',
  `content_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1=富文本 2=Markdown',
  `content_src` MEDIUMTEXT COMMENT '内容源码（Markdown 或 HTML）',
  `content_html` MEDIUMTEXT COMMENT '渲染并消毒后的 HTML',
  `settings_json` TEXT COMMENT '页面设置JSON',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0草稿 1已发布 2已下线',
  `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  `deleted_at` DATETIME DEFAULT NULL COMMENT '回收站：非 NULL 为已删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='自定义页面';


CREATE TABLE IF NOT EXISTS `{{prefix}}promos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '创建者',
  `name` VARCHAR(100) NOT NULL COMMENT '渠道名称',
  `type` VARCHAR(10) NOT NULL DEFAULT 'qrcode' COMMENT 'qrcode活码 short短链',
  `code` VARCHAR(16) NOT NULL COMMENT '短码 /q/{code}',
  `mode` VARCHAR(10) NOT NULL DEFAULT 'direct' COMMENT '跳转方式（短链）：direct直接跳 guide中转页',
  `rotate` VARCHAR(4) NOT NULL DEFAULT 'seq' COMMENT '轮询方式：seq顺序 rand随机',
  `flow` TINYINT NOT NULL DEFAULT 0 COMMENT '并流：落地页同时展示全部二维码（活码）',
  `title` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '落地页标题（活码）',
  `tip` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '引导语（活码，如：长按识别二维码添加）',
  `fallback` VARCHAR(200) NOT NULL DEFAULT '' COMMENT '兜底文案：全部二维码用完时展示（活码）',
  `bottom_type` VARCHAR(10) NOT NULL DEFAULT 'none' COMMENT '底部条类型（活码）：none不显示 text纯文字 link跳转按钮',
  `bottom_text` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '底部条文字',
  `bottom_target` VARCHAR(1000) NOT NULL DEFAULT '' COMMENT '底部条跳转目标（link 时）：http(s) 链接或页面分享码',
  `domain` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '发布域名（空=当前域名）',
  `click_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  `deleted_at` DATETIME DEFAULT NULL COMMENT '回收站：非 NULL 为已删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='引流推广位（活码/短链）';

CREATE TABLE IF NOT EXISTS `{{prefix}}promo_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `promo_id` INT UNSIGNED NOT NULL,
  `sort` SMALLINT NOT NULL DEFAULT 0 COMMENT '展示顺序',
  `kind` VARCHAR(4) NOT NULL DEFAULT 'img' COMMENT 'img图片(活码) url链接(短链)',
  `target` VARCHAR(1000) NOT NULL COMMENT '图片地址或目标链接',
  `scan_limit` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '扫码上限：达到后自动切换下一个，0=不限',
  `weight` SMALLINT NOT NULL DEFAULT 1 COMMENT '权重：加权轮询配比',
  `device` VARCHAR(10) NOT NULL DEFAULT 'all' COMMENT '设备规则：all/ios/android/pc',
  `time_from` VARCHAR(5) NOT NULL DEFAULT '' COMMENT '生效开始 HH:MM，空=不限',
  `time_to` VARCHAR(5) NOT NULL DEFAULT '' COMMENT '生效结束 HH:MM，支持跨零点',
  `scans` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已分发/点击次数',
  `longpress` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '长按识别次数（活码图片）',
  PRIMARY KEY (`id`),
  KEY `idx_promo` (`promo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='引流条目（活码图片/短链链接）';

CREATE TABLE IF NOT EXISTS `{{prefix}}promo_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `promo_id` INT UNSIGNED NOT NULL,
  `item_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '命中的 promo_items.id',
  `target_url` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '实际跳转地址',
  `device` VARCHAR(10) NOT NULL DEFAULT 'pc' COMMENT 'ios/android/pc',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `ua` VARCHAR(500) NOT NULL DEFAULT '',
  `referer` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_promo_time` (`promo_id`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='引流点击日志';
