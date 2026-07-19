-- تنفيذ هذا الملف مرة واحدة على قاعدة البيانات (عن طريق phpMyAdmin مثلاً)

-- جدول لتسجيل كل رسالة كاش توصل، سواء اتطابقت مع موزع أو لأ
CREATE TABLE IF NOT EXISTS `cash_transactions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `service` VARCHAR(30) NOT NULL COMMENT 'vodafone / etisalat / orange',
  `sender_raw` VARCHAR(50) NOT NULL COMMENT 'الرقم أو الكود اللي وصلت منه الرسالة',
  `phone_extracted` VARCHAR(20) DEFAULT NULL COMMENT 'رقم العميل المستخرج من نص الرسالة',
  `amount` DECIMAL(10,2) DEFAULT NULL,
  `raw_message` TEXT NOT NULL,
  `dedupe_hash` VARCHAR(64) NOT NULL COMMENT 'بصمة الرسالة لمنع التكرار',
  `distributor_id` INT DEFAULT NULL,
  `status` ENUM('matched','unmatched','duplicate','parse_error') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_dedupe` (`dedupe_hash`),
  KEY `idx_distributor` (`distributor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- (اختياري لكن مهم جداً للأمان) عمود لتفعيل/تعطيل الموزع من استقبال شحن تلقائي
ALTER TABLE `distributor`
  ADD COLUMN IF NOT EXISTS `auto_topup` ENUM('on','off') NOT NULL DEFAULT 'on' AFTER `dis_phone`;
