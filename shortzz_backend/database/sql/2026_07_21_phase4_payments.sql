-- Phase 4 (mobile money payments) — run on production via server-side mysql
-- CLI (php artisan tinker is broken on the shared host; migrations are for
-- dev). Mirrors database/migrations/2026_07_21_000001_add_payment_provider_settings.php.

ALTER TABLE tbl_settings
  ADD COLUMN active_payout_provider VARCHAR(50) NULL,
  ADD COLUMN active_collection_provider VARCHAR(50) NULL,
  ADD COLUMN dpo_company_token VARCHAR(191) NULL,
  ADD COLUMN dpo_service_type VARCHAR(191) NULL,
  ADD COLUMN lenco_api_key VARCHAR(191) NULL,
  ADD COLUMN paystack_secret_key VARCHAR(191) NULL,
  ADD COLUMN paystack_public_key VARCHAR(191) NULL,
  ADD COLUMN flutterwave_secret_key VARCHAR(191) NULL;

ALTER TABLE tbl_redeem_request
  ADD COLUMN provider VARCHAR(50) NULL AFTER account,
  ADD COLUMN provider_reference VARCHAR(191) NULL AFTER provider;

CREATE TABLE tbl_coin_topup_request (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  coin_package_id BIGINT UNSIGNED NOT NULL,
  coins INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  provider VARCHAR(50) NULL,
  provider_reference VARCHAR(191) NULL,
  status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_topup_user (user_id),
  INDEX idx_topup_reference (provider_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
