-- ==========================================================================
--  OwnForm 增量升级脚本（0.0.2 → 0.0.3）
--  用途：渠道定时上下线
--    1) form_channels 增加 online_from / online_until（在时间窗内渠道码才生效）
--  执行：mysql -u<user> -p <库名> < upgrade-0.0.3.sql
--  说明：全部语句可重复执行（幂等）；表前缀默认 of_，不同请全局替换
-- ==========================================================================

-- 1) form_channels.online_from / online_until -------------------------------
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'of_form_channels'
    AND COLUMN_NAME = 'online_from'
);
SET @sql := IF(@has_col = 0,
  'ALTER TABLE `of_form_channels` ADD COLUMN `online_from` DATETIME NULL COMMENT ''定时上线：NULL=立即'' AFTER `status`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'of_form_channels'
    AND COLUMN_NAME = 'online_until'
);
SET @sql := IF(@has_col = 0,
  'ALTER TABLE `of_form_channels` ADD COLUMN `online_until` DATETIME NULL COMMENT ''定时下线：NULL=不限'' AFTER `online_from`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
