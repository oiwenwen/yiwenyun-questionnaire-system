-- ============================================================================
-- 易问云问卷系统 · 建表脚本（权威版）
-- ----------------------------------------------------------------------------
-- 来源：不是靠代码反推的，而是直接用 MySQL 5.7.26 加载你保留下来的数据文件
--       (D:\phpstudy_pro\Extensions\MySQL5.7.26\data\yiwenyun) 后导出的真实结构。
--       字段长度、枚举值、索引名均与线上那份保持一致。
--
-- 用法（全新环境初始化空库）：
--   mysql -u root -p < schema.sql
-- 然后导入数据（如果有）：
--   mysql -u root -p < yiwenyun_ready_20261011.sql
--
-- ⚠️ 关于答卷时间字段的重要说明：
--   恢复出来的旧表里，responses 的时间列叫 `submitted_at`，
--   但代码（api/submit_response.php、api/get_responses.php、
--   api/export_results.php、index.html）全部使用 `submit_time`。
--   线上系统能正常提交，说明线上库用的就是 `submit_time`；
--   本地这份 `submitted_at` 属于更早一代的表结构。
--   本脚本按代码使用的名字 `submit_time` 输出。
--   如果你从云端快照恢复了线上数据库，请先确认列名：
--       SHOW COLUMNS FROM responses;
--   若确实是 submitted_at，执行下面这句即可（该表通常可以安全改名）：
--       ALTER TABLE responses CHANGE COLUMN submitted_at submit_time DATETIME DEFAULT CURRENT_TIMESTAMP;
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `yiwenyun`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `yiwenyun`;

-- ---------------------------------------------------------------------------
-- 用户表
--   role:   admin 管理员 / editor 编辑者（注册默认） / viewer 只读
--   status: active 可登录 / disabled 停用
--   登录接口按 username + status='active' 查询，密码为 password_hash() 生成的 bcrypt 哈希
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `username`   varchar(50)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `password`   varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role`       enum('admin','editor','viewer') COLLATE utf8mb4_unicode_ci DEFAULT 'editor',
  `email`      varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime     DEFAULT CURRENT_TIMESTAMP,
  `status`     enum('active','disabled') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 问卷主表
--   status: draft 草稿 / published 已发布（可被公开填写） / closed 已关闭
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `surveys`;
CREATE TABLE `surveys` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `title`       varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text         COLLATE utf8mb4_unicode_ci,
  `user_id`     int(11)      NOT NULL,
  `status`      enum('draft','published','closed') COLLATE utf8mb4_unicode_ci DEFAULT 'draft',
  `created_at`  datetime     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=MyISAM AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 题目表
--   type: text 文本 / radio 单选 / checkbox 多选
--         matrix、scale 在表结构里预留了，但当前前端与接口尚未实现
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `questions`;
CREATE TABLE `questions` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `survey_id`  int(11)      NOT NULL,
  `title`      text         COLLATE utf8mb4_unicode_ci NOT NULL,
  `type`       enum('radio','checkbox','text','matrix','scale') COLLATE utf8mb4_unicode_ci NOT NULL,
  `required`   tinyint(1)   DEFAULT '0',
  `sort_order` int(11)      DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `survey_id` (`survey_id`)
) ENGINE=MyISAM AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 选项表（仅 radio / checkbox 使用）
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `options`;
CREATE TABLE `options` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `question_id` int(11)      NOT NULL,
  `text`        varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order`  int(11)      DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `question_id` (`question_id`)
) ENGINE=MyISAM AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 提交记录表（一次提交一行）
--   注意：列名必须是 submit_time，代码依赖它；详见文件头的说明
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `responses`;
CREATE TABLE `responses` (
  `id`          int(11)     NOT NULL AUTO_INCREMENT,
  `survey_id`   int(11)     NOT NULL,
  `submit_time` datetime    DEFAULT CURRENT_TIMESTAMP,
  `ip_address`  varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `survey_id` (`survey_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 答案表（一条答案一行；多选题的多个选项用 ", " 拼进 answer_text）
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS `answers`;
CREATE TABLE `answers` (
  `id`          int(11) NOT NULL AUTO_INCREMENT,
  `response_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `answer_text` text    COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `response_id` (`response_id`),
  KEY `question_id` (`question_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- 关于第一个管理员账号
-- ----------------------------------------------------------------------------
-- 不要在这里手工 INSERT 密码：users.password 存的是 bcrypt 哈希，手写的哈希登不进去。
-- 正确做法二选一：
--   A) 部署后在首页点「注册」建个账号，然后执行：
--        UPDATE users SET role='admin' WHERE username='你的用户名';
--   B) 用项目自带的重置工具（推荐，会正确生成哈希）：
--        php _restore/tools/reset_password.php 你的用户名 新密码 admin
--
-- 另外提醒：本项目的表都是 MyISAM 引擎，没有外键，删除操作的一致性
-- 完全由 api/delete_survey.php 里的显式级联删除来保证。
-- ============================================================================
