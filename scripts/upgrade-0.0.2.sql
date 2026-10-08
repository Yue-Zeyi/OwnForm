-- ==========================================================================
--  OwnForm 增量升级脚本（0.0.1 → 0.0.2）
--  用途：渠道协作功能
--    1) users 新增 permissions_json（成员功能权限）
--    2) form_submissions 新增 channel_id（来源渠道）+ 查询索引
--    3) 新建 form_channels（表单分发渠道）
--    4) 新建 form_members（表单协作成员授权）
--  执行：mysql -u<user> -p <库名> < upgrade-0.0.2.sql
--  说明：全部语句可重复执行（幂等）；表前缀默认 of_，不同请全局替换
-- ==========================================================================

-- 1) users.permissions_json ------------------------------------------------
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'of_users'
    AND COLUMN_NAME = 'permissions_json'
);
SET @sql := IF(@has_col = 0,
  'ALTER TABLE `of_users` ADD COLUMN `permissions_json` TEXT NULL COMMENT ''成员功能权限JSON数组，NULL=默认全开'' AFTER `role`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) form_submissions.channel_id + 索引 ------------------------------------
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'of_form_submissions'
    AND COLUMN_NAME = 'channel_id'
);
SET @sql := IF(@has_col = 0,
  'ALTER TABLE `of_form_submissions` ADD COLUMN `channel_id` INT UNSIGNED NULL DEFAULT NULL COMMENT ''来源渠道，NULL=直接访问'' AFTER `form_id`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'of_form_submissions'
    AND INDEX_NAME = 'idx_form_channel'
);
SET @sql := IF(@has_idx = 0,
  'ALTER TABLE `of_form_submissions` ADD KEY `idx_form_channel` (`form_id`, `channel_id`)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) form_channels（表单分发渠道）------------------------------------------
CREATE TABLE IF NOT EXISTS `of_form_channels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '渠道名称',
  `code` VARCHAR(16) NOT NULL COMMENT '渠道码 /s/{slug}/{code}',
  `member_id` INT UNSIGNED NULL DEFAULT NULL COMMENT '归属成员ID，NULL=公共渠道',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 0停用（停用仅不再推广，不拦截提交）',
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

-- 4) form_members（表单协作成员授权）----------------------------------------
CREATE TABLE IF NOT EXISTS `of_form_members` (
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
