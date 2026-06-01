CREATE DATABASE IF NOT EXISTS `smart_security_system`;
USE `smart_security_system`;
CREATE TABLE IF NOT EXISTS `distance_logs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `distance_cm` decimal(5,2) NOT NULL,
    `object_detected` tinyint(1) DEFAULT 0,
    `timestamp` datetime DEFAULT CURRENT_TIMESTAMP,
    `status` varchar(50) DEFAULT 'Normal',
    PRIMARY KEY (`id`),
    KEY `idx_timestamp` (`timestamp`),
    KEY `idx_detection` (`object_detected`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `photos` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `filename` varchar(255) NOT NULL,
    `filepath` varchar(500) NOT NULL,
    `size_kb` decimal(10,2) DEFAULT NULL,
    `detection_event_id` int(11) DEFAULT NULL,
    `timestamp` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_timestamp` (`timestamp`),
    KEY `detection_event_id` (`detection_event_id`),
    CONSTRAINT `photos_ibfk_1` FOREIGN KEY (`detection_event_id`) REFERENCES `distance_logs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `gsm_alerts` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `phone_number` varchar(20) NOT NULL,
    `message` text NOT NULL,
    `status` enum('sent','failed','delivered') DEFAULT 'sent',
    `timestamp` datetime DEFAULT CURRENT_TIMESTAMP,
    `response_code` varchar(10) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_timestamp` (`timestamp`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- TABLE: system_logs
-- Stores system event logs
-- ============================================
CREATE TABLE IF NOT EXISTS `system_logs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `event_type` varchar(50) NOT NULL,
    `description` text DEFAULT NULL,
    `severity` enum('info','warning','critical') DEFAULT 'info',
    `timestamp` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_timestamp` (`timestamp`),
    KEY `idx_severity` (`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
