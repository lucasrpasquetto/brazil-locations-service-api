-- Blit Locations — schema
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS countries (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(10) NOT NULL,
  phone_code VARCHAR(10) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS regions (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  country_id INT UNSIGNED NOT NULL,
  name VARCHAR(50) NOT NULL,
  sigla VARCHAR(2) NOT NULL,
  KEY idx_country (country_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS states (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  slug VARCHAR(2) NOT NULL,
  country_id INT UNSIGNED NOT NULL,
  code INT UNSIGNED NOT NULL,
  region_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_slug (slug),
  KEY idx_region (region_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cities (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  state_id INT UNSIGNED NOT NULL,
  code VARCHAR(10) NOT NULL,
  name VARCHAR(120) NOT NULL,
  name_search VARCHAR(120) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  UNIQUE KEY uq_code (code),
  KEY idx_state (state_id),
  KEY idx_search (name_search)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
