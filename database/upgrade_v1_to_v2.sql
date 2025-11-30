-- Upgrade-Skript auf den Stand mit Entity-First-Klassifizierung und flexiblen Achsen
-- Ausführen mit: mysql <datenbankname> < database/upgrade_v1_to_v2.sql

-- 1) File-Inventory um Klassifizierungsstatus erweitern
ALTER TABLE `file_inventory`
    ADD COLUMN IF NOT EXISTS `classification_state` ENUM('unclassified','entity_only','outfit_assigned','pose_assigned','view_assigned','fully_classified') NOT NULL DEFAULT 'unclassified' AFTER `asset_revision_id`;

-- Sicherstellen, dass vorhandene Installationen die vollständige ENUM-Liste nutzen
ALTER TABLE `file_inventory`
    MODIFY COLUMN `classification_state` ENUM('unclassified','entity_only','outfit_assigned','pose_assigned','view_assigned','fully_classified') NOT NULL DEFAULT 'unclassified';

-- Bestehende Daten auffüllen: verknüpfte Revisionen gelten als voll klassifiziert
UPDATE `file_inventory`
SET `classification_state` = CASE
    WHEN `asset_revision_id` IS NOT NULL THEN 'fully_classified'
    ELSE 'unclassified'
END
WHERE `classification_state` IS NULL OR `classification_state` = '';

-- 2) Entity-File-Linking für den Entity-First-Workflow
CREATE TABLE IF NOT EXISTS `entity_file_links` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `entity_id` INT NOT NULL,
    `file_inventory_id` INT NOT NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_entity_file` (`entity_id`, `file_inventory_id`),
    CONSTRAINT `fk_entity_file_entity` FOREIGN KEY (`entity_id`) REFERENCES `entities` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_entity_file_inventory` FOREIGN KEY (`file_inventory_id`) REFERENCES `file_inventory` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Konfigurierbare Klassifizierungsachsen je Entity-Typ
CREATE TABLE IF NOT EXISTS `classification_axes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `axis_key` VARCHAR(100) NOT NULL,
    `label` VARCHAR(255) NOT NULL,
    `applies_to` ENUM('character','location','scene','chapter','prop','background','item','creature','project_custom') NOT NULL,
    `has_predefined_values` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_axis_per_type` (`axis_key`, `applies_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `classification_axis_values` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `axis_id` INT NOT NULL,
    `value_key` VARCHAR(100) NOT NULL,
    `label` VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_axis_value` (`axis_id`, `value_key`),
    CONSTRAINT `fk_axis_values_axis` FOREIGN KEY (`axis_id`) REFERENCES `classification_axes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `revision_classifications` (
    `revision_id` INT NOT NULL,
    `axis_id` INT NOT NULL,
    `value_key` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`revision_id`, `axis_id`),
    CONSTRAINT `fk_rev_class_rev` FOREIGN KEY (`revision_id`) REFERENCES `asset_revisions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rev_class_axis` FOREIGN KEY (`axis_id`) REFERENCES `classification_axes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
