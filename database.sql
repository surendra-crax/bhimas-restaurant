-- ============================================================
-- BHIMAS TRADITIONAL INDIAN KITCHEN
-- database.sql — Complete schema + default data
--
-- Engine:    MySQL / MariaDB (InnoDB, utf8mb4)
-- Usage:     mysql -u root -p bhimas_db < database.sql
--            OR import via phpMyAdmin / cPanel
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION';

-- ── Create database (skip if already exists) ────────────────
-- CREATE DATABASE IF NOT EXISTS `bhimas_db`
--   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `bhimas_db`;

-- ============================================================
-- TABLE: bookings
-- Stores table reservation requests from reservation.html
-- ============================================================
DROP TABLE IF EXISTS `bookings`;
CREATE TABLE `bookings` (
    `id`               INT UNSIGNED       NOT NULL AUTO_INCREMENT,
    `booking_ref`      VARCHAR(30)        NOT NULL COMMENT 'BHM-RES-YYYYMMDD-XXXX',
    `name`             VARCHAR(120)       NOT NULL,
    `phone`            VARCHAR(30)        NOT NULL,
    `email`            VARCHAR(180)       NOT NULL,
    `date`             DATE               NOT NULL COMMENT 'Requested dining date',
    `time`             VARCHAR(10)        NOT NULL COMMENT 'HH:MM 24-hour format',
    `guests`           TINYINT UNSIGNED   NOT NULL DEFAULT 2,
    `special_requests` TEXT               NULL,
    `status`           ENUM('pending','confirmed','cancelled')
                                          NOT NULL DEFAULT 'pending',
    `created_at`       DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_booking_ref` (`booking_ref`),
    KEY `idx_bookings_date`    (`date`),
    KEY `idx_bookings_status`  (`status`),
    KEY `idx_bookings_email`   (`email`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Table reservation requests';


-- ============================================================
-- TABLE: orders
-- Stores takeaway pre-orders from takeaway.html
-- ============================================================
DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
    `id`            INT UNSIGNED       NOT NULL AUTO_INCREMENT,
    `order_ref`     VARCHAR(30)        NOT NULL COMMENT 'BHM-ORD-YYYYMMDD-XXXX',
    `name`          VARCHAR(120)       NOT NULL,
    `phone`         VARCHAR(30)        NOT NULL,
    `email`         VARCHAR(180)       NOT NULL,
    `items_json`    MEDIUMTEXT         NOT NULL COMMENT 'JSON array of {id,name,price,qty}',
    `pickup_time`   VARCHAR(10)        NOT NULL COMMENT 'HH:MM 24-hour format',
    `total_amount`  DECIMAL(8,2)       NOT NULL DEFAULT 0.00,
    `notes`         TEXT               NULL     COMMENT 'Special instructions',
    `status`        ENUM('pending','preparing','ready','completed','cancelled')
                                       NOT NULL DEFAULT 'pending',
    `created_at`    DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_order_ref`    (`order_ref`),
    KEY `idx_orders_pickup_time` (`pickup_time`),
    KEY `idx_orders_status`      (`status`),
    KEY `idx_orders_email`       (`email`),
    KEY `idx_orders_created`     (`created_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Takeaway pre-orders';


-- ============================================================
-- TABLE: messages
-- Stores contact form submissions from contact.html
-- ============================================================
DROP TABLE IF EXISTS `messages`;
CREATE TABLE `messages` (
    `id`         INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(120)   NOT NULL,
    `email`      VARCHAR(180)   NOT NULL,
    `subject`    VARCHAR(120)   NOT NULL DEFAULT 'General Enquiry',
    `message`    TEXT           NOT NULL,
    `is_read`    TINYINT(1)     NOT NULL DEFAULT 0,
    `created_at` DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_messages_is_read`  (`is_read`),
    KEY `idx_messages_email`    (`email`),
    KEY `idx_messages_created`  (`created_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Contact form messages';


-- ============================================================
-- TABLE: site_settings
-- Key-value store for all configurable site settings.
-- Queried by get-settings.php and admin panel.
-- ============================================================
DROP TABLE IF EXISTS `site_settings`;
CREATE TABLE `site_settings` (
    `setting_key`   VARCHAR(80)    NOT NULL,
    `setting_value` TEXT           NOT NULL,
    `is_public`     TINYINT(1)     NOT NULL DEFAULT 1
                                   COMMENT '1 = safe to expose to JS via get-settings.php',
    `updated_at`    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Configurable site settings';


-- ============================================================
-- DEFAULT SETTINGS
-- ============================================================

INSERT INTO `site_settings` (`setting_key`, `setting_value`, `is_public`) VALUES

-- ── Feature toggles ────────────────────────────────────────
('reservation_enabled',     '1',        1),
('takeaway_enabled',        '1',        1),

-- ── Contact & branding ────────────────────────────────────
('restaurant_name',         'Bhimas Traditional Indian Kitchen',   1),
('phone',                   '+352 12 34 56 78',                    1),
('email',                   'hello@bhimas.lu',              1),
('address_line1',           '123 Rue de la Gare',                  1),
('address_line2',           '',                                     1),
('city',                    'Luxembourg City',                      1),
('postcode',                'L-1616',                               1),
('owner_email',             'hello@bhimas.lu',              0),  -- private: only used server-side

-- ── Opening hours (format: "HH:MM-HH:MM" or "closed") ────
-- Sun=0, Mon=1, Tue=2, Wed=3, Thu=4, Fri=5, Sat=6
('hours_sun',               '12:00-21:00',  1),
('hours_mon',               '11:00-22:00',  1),
('hours_tue',               '11:00-22:00',  1),
('hours_wed',               '11:00-22:00',  1),
('hours_thu',               '11:00-22:00',  1),
('hours_fri',               '11:00-23:00',  1),
('hours_sat',               '11:00-23:00',  1),

-- ── Reservation settings ──────────────────────────────────
('slot_interval_mins',                    '30',  1),
('last_reservation_mins_before_close',    '60',  1),
('max_party_size',                        '20',  1),

-- ── Takeaway / order settings ─────────────────────────────
('min_takeaway_minutes',                  '60',   1),  -- min lead time before pickup
('pickup_interval_mins',                  '15',   1),
('last_pickup_mins_before_close',         '30',   1),
('min_order_amount',                      '10.00', 1),
('currency_symbol',                       '€',    1),
('currency_code',                         'EUR',  1),

-- ── Compatibility aliases (used by Phase 3/4 JS) ──────────
-- These mirror the per-day hours for the generic slot generator
-- JS uses open_time / close_time for weekday defaults
('open_time',                             '11:00',  1),
('close_time',                            '22:00',  1),
('open_time_weekend',                     '11:00',  1),
('close_time_weekend',                    '23:00',  1),

-- ── Timezone ──────────────────────────────────────────────
('timezone',                              'Europe/Luxembourg',  0);  -- server-side only


-- ============================================================
-- Optional: reset AUTO_INCREMENT after DROP + recreate
-- (No-op if this is a fresh installation)
-- ============================================================

SET foreign_key_checks = 1;
