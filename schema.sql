-- Telegram Sub-for-Sub Escrow Bot Database Schema
-- MySQL InnoDB Engine

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- Users table - Stores Telegram user information
CREATE TABLE `users` (
  `telegram_id` bigint NOT NULL,
  `username` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`telegram_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Channels table - Stores channels where bot is admin
CREATE TABLE `channels` (
  `channel_id` bigint NOT NULL,
  `owner_id` bigint NOT NULL,
  `channel_title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bot_is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`channel_id`),
  KEY `idx_channels_owner_id` (`owner_id`),
  CONSTRAINT `fk_channels_owner_id` FOREIGN KEY (`owner_id`) REFERENCES `users` (`telegram_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Agreements table - Stores mutual subscription agreements
CREATE TABLE `agreements` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_a_id` bigint NOT NULL,
  `channel_a_id` bigint NOT NULL,
  `user_b_id` bigint NOT NULL,
  `channel_b_id` bigint NOT NULL,
  `status` enum('pending','active','canceled','compromised') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_agreements_user_a` (`user_a_id`),
  KEY `idx_agreements_user_b` (`user_b_id`),
  KEY `idx_agreements_channel_a` (`channel_a_id`),
  KEY `idx_agreements_channel_b` (`channel_b_id`),
  KEY `idx_agreements_status` (`status`),
  CONSTRAINT `fk_agreements_user_a_id` FOREIGN KEY (`user_a_id`) REFERENCES `users` (`telegram_id`),
  CONSTRAINT `fk_agreements_user_b_id` FOREIGN KEY (`user_b_id`) REFERENCES `users` (`telegram_id`),
  CONSTRAINT `fk_agreements_channel_a_id` FOREIGN KEY (`channel_a_id`) REFERENCES `channels` (`channel_id`),
  CONSTRAINT `fk_agreements_channel_b_id` FOREIGN KEY (`channel_b_id`) REFERENCES `channels` (`channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
