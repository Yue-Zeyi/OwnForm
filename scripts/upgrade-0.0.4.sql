-- ==========================================================================
--  OwnForm 增量升级脚本（0.0.3 → 0.0.4）
--  用途：表单付费/支付
--    1) 新建 form_orders 订单表
--    2) form_submissions 增加 pay_status / order_no 冗余列
--  执行：mysql -u<user> -p <库名> < upgrade-0.0.4.sql
--  说明：全部语句可重复执行（幂等）；表前缀默认 of_，不同请全局替换
-- ==========================================================================

-- 1) form_orders（支付订单流水）---------------------------------------------
CREATE TABLE IF NOT EXISTS `of_form_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no` VARCHAR(32) NOT NULL COMMENT '业务订单号（日期+随机，防遍历）',
  `form_id` INT UNSIGNED NOT NULL,
  `submission_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT '关联提交（支付成功后转正式）',
  `amount` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '应收金额',
  `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0待支付 1已支付 2已取消 3已退款 4待核销(转账)',
  `pay_type` VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'wxpay_native/wxpay_h5/alipay_page/alipay_fce/alipay_wap/transfer',
  `trade_no` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '渠道交易号',
  `refund_no` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '退款单号',
  `refund_amount` DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT '已退金额',
  `voucher` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '转账凭证图片（相对路径）',
  `buyer_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '买家标识（openid/支付宝账号，可空）',
  `paid_at` DATETIME DEFAULT NULL,
  `refunded_at` DATETIME DEFAULT NULL,
  `expire_at` DATETIME DEFAULT NULL COMMENT '待支付截止时间',
  `verify_by` INT UNSIGNED NULL DEFAULT NULL COMMENT '转账核销人',
  `verify_at` DATETIME DEFAULT NULL,
  `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '后台备注（核销/退款原因）',
  `notify_log` TEXT NULL COMMENT '回调原始报文（排障/对账）',
  `created_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_form_status` (`form_id`, `status`),
  KEY `idx_submission` (`submission_id`),
  KEY `idx_paid_at` (`paid_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表单支付订单';

-- 2) form_submissions 支付冗余列 --------------------------------------------
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'of_form_submissions'
    AND COLUMN_NAME = 'pay_status'
);
SET @sql := IF(@has_col = 0,
  'ALTER TABLE `of_form_submissions` ADD COLUMN `pay_status` TINYINT NOT NULL DEFAULT 0 COMMENT ''0无需支付 1待支付 2已支付 3待核销 4已退款'' AFTER `status`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'of_form_submissions'
    AND COLUMN_NAME = 'order_no'
);
SET @sql := IF(@has_col = 0,
  'ALTER TABLE `of_form_submissions` ADD COLUMN `order_no` VARCHAR(32) NOT NULL DEFAULT '''' AFTER `pay_status`',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'of_form_submissions'
    AND INDEX_NAME = 'idx_order_no'
);
SET @sql := IF(@has_idx = 0,
  'ALTER TABLE `of_form_submissions` ADD KEY `idx_order_no` (`order_no`)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) 提交状态新增「待支付」值 2（列表过滤用，无结构变更）
