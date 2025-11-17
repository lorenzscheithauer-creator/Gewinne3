CREATE DATABASE IF NOT EXISTS `gewinne3` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `gewinne3`;

CREATE TABLE IF NOT EXISTS `gewinnspiele` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `link_zur_webseite` VARCHAR(500) NOT NULL,
  `beschreibung` TEXT,
  `status` ENUM('Aktiv','Ende') NOT NULL DEFAULT 'Aktiv',
  `endet_am` DATE NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
