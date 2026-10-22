-- ============================================================
-- BHIMAS TRADITIONAL INDIAN KITCHEN
-- php/update_schema.sql — Database migration for login/history/reset system
-- ============================================================

SET foreign_key_checks = 0;

-- ── 1. Create customers table ──
CREATE TABLE IF NOT EXISTS `customers` (
    `id`               INT UNSIGNED       NOT NULL AUTO_INCREMENT,
    `name`             VARCHAR(120)       NOT NULL,
    `email`            VARCHAR(180)       NOT NULL,
    `password_hash`    VARCHAR(255)       NOT NULL,
    `phone`            VARCHAR(30)        NULL,
    `created_at`       DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_customers_email` (`email`),
    KEY `idx_customers_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Create password_resets table ──
CREATE TABLE IF NOT EXISTS `password_resets` (
    `email`            VARCHAR(180)       NOT NULL,
    `token`            VARCHAR(64)        NOT NULL,
    `expires_at`       DATETIME           NOT NULL,
    `created_at`       DATETIME           NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`email`),
    UNIQUE KEY `uq_resets_token` (`token`),
    KEY `idx_resets_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
