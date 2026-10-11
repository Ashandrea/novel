CREATE DATABASE IF NOT EXISTS `novel`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `novel`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(60) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'user') NOT NULL DEFAULT 'user',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_username_unique` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `novels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(100) NOT NULL,
  `author` VARCHAR(80) NOT NULL,
  `genre` VARCHAR(40) NOT NULL DEFAULT '',
  `publication_year` SMALLINT UNSIGNED NULL,
  `status` ENUM('Belum dibaca', 'Sedang dibaca', 'Selesai') NOT NULL DEFAULT 'Belum dibaca',
  `description` VARCHAR(240) NOT NULL DEFAULT '',
  `owner_user_id` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `novels_title_index` (`title`),
  KEY `novels_author_index` (`author`),
  KEY `novels_status_index` (`status`),
  KEY `novels_owner_user_id_index` (`owner_user_id`),
  CONSTRAINT `novels_owner_user_fk` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `novel_chapters` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `novel_id` INT UNSIGNED NOT NULL,
  `chapter_number` SMALLINT UNSIGNED NOT NULL,
  `title` VARCHAR(120) NOT NULL,
  `content` MEDIUMTEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `novel_chapters_number_unique` (`novel_id`, `chapter_number`),
  CONSTRAINT `novel_chapters_novel_fk` FOREIGN KEY (`novel_id`) REFERENCES `novels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
