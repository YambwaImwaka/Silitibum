-- Phase 1 (MySQL-native auth) — run on production via server-side mysql CLI
-- (php artisan tinker is broken on the shared host; migrations are for dev).
-- tbl_users.password ALREADY EXISTS (dummy-user display password) — not added here.

ALTER TABLE tbl_users
  ADD COLUMN email_verified_at DATETIME NULL,
  ADD COLUMN provider_uid VARCHAR(191) NULL,
  ADD INDEX idx_users_provider_uid (provider_uid);

CREATE TABLE verification_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(191) NOT NULL,
  code VARCHAR(6) NOT NULL,
  type ENUM('verify_email','reset_password') NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_vc_user_type (user_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
