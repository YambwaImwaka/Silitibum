-- Phase 3 (MySQL livestream signalling) — run on production via mysql CLI.

CREATE TABLE livestreams (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_id VARCHAR(64) NOT NULL UNIQUE,
  host_id BIGINT UNSIGNED NOT NULL,
  description VARCHAR(255) NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'LIVESTREAM',
  battle_type VARCHAR(20) NOT NULL DEFAULT 'INITIATE',
  battle_duration INT NOT NULL DEFAULT 5,
  battle_created_at BIGINT UNSIGNED NULL,
  is_restrict_to_join TINYINT(1) NOT NULL DEFAULT 0,
  host_view_id INT NULL,
  like_count INT UNSIGNED NOT NULL DEFAULT 0,
  watching_count INT UNSIGNED NOT NULL DEFAULT 0,
  co_host_ids TEXT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'live',
  ended_at DATETIME NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY idx_ls_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE livestream_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  livestream_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(12) NOT NULL DEFAULT 'AUDIENCE',
  audio_status VARCHAR(12) NOT NULL DEFAULT 'ON',
  video_status VARCHAR(12) NOT NULL DEFAULT 'ON',
  live_coin INT NOT NULL DEFAULT 0,
  current_battle_coin INT NOT NULL DEFAULT 0,
  total_battle_coin INT NOT NULL DEFAULT 0,
  followers_gained TEXT NULL,
  join_stream_time BIGINT UNSIGNED NULL,
  left_at DATETIME NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_stream_user (livestream_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE livestream_comments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  livestream_id BIGINT UNSIGNED NOT NULL,
  sender_id BIGINT UNSIGNED NOT NULL,
  receiver_id BIGINT UNSIGNED NULL,
  comment_type VARCHAR(20) NOT NULL DEFAULT 'TEXT',
  comment TEXT NULL,
  gift_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY idx_lc_stream (livestream_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
