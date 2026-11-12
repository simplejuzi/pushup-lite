-- ============================================================
-- PUSHUP · MySQL 8.0 建表脚本
-- 用法：宝塔 → 数据库 → phpMyAdmin → 选库 → 导入本文件
--       或在 SQL 窗口整段粘贴执行。重复执行安全（IF NOT EXISTS）。
-- 字符集：utf8mb4 / utf8mb4_0900_ai_ci（支持 emoji 昵称与头像）
-- ============================================================

-- 如果还没建库，先建（库名可改，改完同步改 config.php）
CREATE DATABASE IF NOT EXISTS `pushup`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;
USE `pushup`;

-- ---------- 账号 ----------
-- 邮箱 + bcrypt 密码。密码哈希可直接复用 Supabase 的 encrypted_password。
CREATE TABLE IF NOT EXISTS `users` (
  `id` CHAR(36) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------- 资料（对外公开：id / 昵称 / 头像 emoji / 加入时间）----------
CREATE TABLE IF NOT EXISTS `profiles` (
  `id` CHAR(36) NOT NULL,
  `name` VARCHAR(60) NOT NULL DEFAULT '新朋友',
  `emoji` VARCHAR(16) NOT NULL DEFAULT '💪',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_profiles_name` (`name`),
  CONSTRAINT `fk_profiles_user` FOREIGN KEY (`id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------- 登录会话（access token / refresh token 都是 64 位随机串）----------
CREATE TABLE IF NOT EXISTS `sessions` (
  `token` CHAR(64) NOT NULL,
  `uid` CHAR(36) NOT NULL,
  `refresh_token` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `refresh_expires_at` DATETIME NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`token`),
  UNIQUE KEY `uk_sessions_refresh` (`refresh_token`),
  KEY `idx_sessions_uid` (`uid`),
  CONSTRAINT `fk_sessions_user` FOREIGN KEY (`uid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------- 动态 ----------
CREATE TABLE IF NOT EXISTS `posts` (
  `id` VARCHAR(64) NOT NULL,
  `uid` CHAR(36) NOT NULL,
  `text` TEXT NOT NULL,
  `workout` JSON NULL,
  `ts` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_posts_ts` (`ts` DESC),
  KEY `idx_posts_uid` (`uid`),
  CONSTRAINT `fk_posts_user` FOREIGN KEY (`uid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------- 点赞（一人一条，主键天然去重）----------
CREATE TABLE IF NOT EXISTS `post_likes` (
  `post_id` VARCHAR(64) NOT NULL,
  `uid` CHAR(36) NOT NULL,
  `ts` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`post_id`, `uid`),
  KEY `idx_likes_uid` (`uid`),
  CONSTRAINT `fk_likes_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_likes_user` FOREIGN KEY (`uid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------- 评论 ----------
CREATE TABLE IF NOT EXISTS `post_comments` (
  `id` VARCHAR(64) NOT NULL,
  `post_id` VARCHAR(64) NOT NULL,
  `uid` CHAR(36) NOT NULL,
  `text` TEXT NOT NULL,
  `ts` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comments_post_ts` (`post_id`, `ts`),
  KEY `idx_comments_uid` (`uid`),
  CONSTRAINT `fk_comments_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comments_user` FOREIGN KEY (`uid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------- 训练记录：一人一天一条 ----------
CREATE TABLE IF NOT EXISTS `records` (
  `uid` CHAR(36) NOT NULL,
  `d` CHAR(10) NOT NULL,
  `payload` JSON NOT NULL,
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`uid`, `d`),
  KEY `idx_records_d` (`d`),
  CONSTRAINT `fk_records_user` FOREIGN KEY (`uid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------- 偏好设置：常用动作 / 自定义动作 / 主题 / 配色 ----------
CREATE TABLE IF NOT EXISTS `settings` (
  `uid` CHAR(36) NOT NULL,
  `favs` JSON NULL,
  `custom` JSON NULL,
  `cats` JSON NULL,
  `nickname` VARCHAR(60) NULL,
  `theme` VARCHAR(20) NULL,
  `palette` VARCHAR(20) NULL,
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`uid`),
  CONSTRAINT `fk_settings_user` FOREIGN KEY (`uid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------- 查询视图：好友页「训练天数」用（uid + 日期，不含内容）----------
CREATE OR REPLACE VIEW `v_record_days` AS
  SELECT `uid`, `d` FROM `records`;
