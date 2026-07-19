-- Phase 2 (MySQL chat) — run on production via server-side mysql CLI.

CREATE TABLE chat_threads (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user1_id BIGINT UNSIGNED NOT NULL,
  user2_id BIGINT UNSIGNED NOT NULL,
  initiator_id BIGINT UNSIGNED NOT NULL,
  status ENUM('request','approved') NOT NULL DEFAULT 'request',
  last_message_id BIGINT UNSIGNED NULL,
  last_msg VARCHAR(255) NULL,
  last_msg_type VARCHAR(20) NULL,
  last_msg_user_id BIGINT UNSIGNED NULL,
  last_msg_at BIGINT UNSIGNED NULL,
  user1_unread_count INT UNSIGNED NOT NULL DEFAULT 0,
  user2_unread_count INT UNSIGNED NOT NULL DEFAULT 0,
  user1_last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  user2_last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  user1_cleared_before_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  user2_cleared_before_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uq_thread_pair (user1_id, user2_id),
  KEY idx_threads_u1 (user1_id, last_msg_at),
  KEY idx_threads_u2 (user2_id, last_msg_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE chat_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id BIGINT UNSIGNED NOT NULL,
  sender_id BIGINT UNSIGNED NOT NULL,
  message_type VARCHAR(20) NOT NULL,
  text_message TEXT NULL,
  image_message VARCHAR(500) NULL,
  video_message VARCHAR(500) NULL,
  audio_message VARCHAR(500) NULL,
  wave_data TEXT NULL,
  post_message TEXT NULL,
  story_reply_message TEXT NULL,
  is_unsent TINYINT(1) NOT NULL DEFAULT 0,
  deleted_by_user1 TINYINT(1) NOT NULL DEFAULT 0,
  deleted_by_user2 TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  KEY idx_msgs_thread (thread_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
