-- Adds an optional, safe call-to-action to customer announcements.
-- This migration is idempotent on MySQL/MariaDB releases without ADD COLUMN IF NOT EXISTS.

SET @cta_label_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements' AND COLUMN_NAME = 'cta_label'
);
SET @cta_label_sql = IF(
  @cta_label_exists = 0,
  'ALTER TABLE announcements ADD COLUMN cta_label VARCHAR(80) NULL AFTER body',
  'SELECT 1'
);
PREPARE cta_label_stmt FROM @cta_label_sql;
EXECUTE cta_label_stmt;
DEALLOCATE PREPARE cta_label_stmt;

SET @cta_url_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements' AND COLUMN_NAME = 'cta_url'
);
SET @cta_url_sql = IF(
  @cta_url_exists = 0,
  'ALTER TABLE announcements ADD COLUMN cta_url VARCHAR(255) NULL AFTER cta_label',
  'SELECT 1'
);
PREPARE cta_url_stmt FROM @cta_url_sql;
EXECUTE cta_url_stmt;
DEALLOCATE PREPARE cta_url_stmt;
